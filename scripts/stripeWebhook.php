<?php
// scripts/stripeWebhook.php
declare(strict_types=1);

// ---- tiny logger (one.com lets you write under /tmp) ----
function wlog(string $msg): void {
	@file_put_contents('/tmp/stripe_webhook.log','['.date('c')."] $msg\n", FILE_APPEND);
}

// ---- resilient header getter (getallheaders() not always present) ----
function header_val(string $name): string {
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	if (isset($_SERVER[$key])) return $_SERVER[$key];
	if (function_exists('getallheaders')) {
		$h = getallheaders();
		if (isset($h[$name]))              return $h[$name];
		if (isset($h[strtolower($name)]))  return $h[strtolower($name)];
		if (isset($h[strtoupper($name)]))  return $h[strtoupper($name)];
	}
	return '';
}

ini_set('display_errors','0');
error_reporting(E_ALL);

// ---- your app bootstrap (PDO + cfg()) ----
require_once __DIR__ . '/../includes/functions.php'; // gives $pdo and cfg()

// ---- Stripe SDK ----
$loaded = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
	require __DIR__ . '/../vendor/autoload.php';
	$loaded = true;
} elseif (file_exists(__DIR__ . '/../vendor/stripe/stripe-php/init.php')) {
	require __DIR__ . '/../vendor/stripe/stripe-php/init.php';
	$loaded = true;
}
if (!$loaded) { wlog('SDK missing'); http_response_code(500); echo 'sdk-missing'; exit; }

// ✅ set API key for downstream API calls (like Session::all)
\Stripe\Stripe::setApiKey(cfg('STRIPE_SECRET_KEY'));

// ---- verify signature ----
$secret = cfg('STRIPE_WEBHOOK_SECRET', '');
if ($secret === '') { wlog('No STRIPE_WEBHOOK_SECRET'); http_response_code(500); echo 'secret-missing'; exit; }

$payload = file_get_contents('php://input') ?: '';
$sig     = header_val('Stripe-Signature');

try {
	$event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
} catch (\Throwable $e) {
	wlog('Bad signature/JSON: '.$e->getMessage());
	http_response_code(400);
	echo 'bad-signature';
	exit;
}

$type = $event->type ?? '';
$data = $event->data->object ?? null;

// ---------- DB helpers ----------
/** Insert or upsert a pending change row (idempotent on STRIPE_SESSION_ID) */
function insert_change(array $row): void {
	global $pdo;
	$sql = "INSERT INTO company_seat_changes
			(COMPANY_REF, STRIPE_SESSION_ID, CREATED_AT, PROCESSED_AT, APPLIED_AT, DELTAS_JSON, TODAY_EX_VAT_PENCE)
			VALUES (:cref, :sid, NOW(), NULL, NULL, :deltas, :pence)
			ON DUPLICATE KEY UPDATE
			  DELTAS_JSON = VALUES(DELTAS_JSON),
			  TODAY_EX_VAT_PENCE = VALUES(TODAY_EX_VAT_PENCE)";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([
		':cref'   => (int)($row['company_ref'] ?? 0),
		':sid'    => (string)($row['session_id']  ?? ''),
		':deltas' => (string)($row['deltas_json'] ?? '[]'),
		':pence'  => (int)($row['pence'] ?? 0),
	]);
}

/** Apply deltas to company_seats and mark APPLIED_AT */
function apply_deltas(int $companyRef, string $deltasJson, string $sessionId): void {
	global $pdo;

	$deltas = json_decode($deltasJson, true);
	if (!is_array($deltas)) {
		wlog("apply_deltas: invalid JSON for session $sessionId");
		return; // nothing to apply; keep row for inspection
	}

	$pdo->beginTransaction();
	try {
		$upd = $pdo->prepare(
			"UPDATE company_seats
			 SET SEATS_COMMITTED = SEATS_COMMITTED + :delta, UPDATED_AT = NOW()
			 WHERE COMPANY_REF = :cref AND ACCESS_LEVEL_REF = :ref"
		);

		foreach ($deltas as $item) {
			$ref   = (int)($item['ref']   ?? 0);
			$delta = (int)($item['delta'] ?? 0);
			if ($ref === 0 || $delta === 0) continue;

			$upd->execute([
				':delta' => $delta,
				':cref'  => $companyRef,
				':ref'   => $ref,
			]);
		}

		// mark applied
		$mark = $pdo->prepare("UPDATE company_seat_changes
							   SET APPLIED_AT = NOW()
							   WHERE STRIPE_SESSION_ID = :sid");
		$mark->execute([':sid' => $sessionId]);

		$pdo->commit();
	} catch (\Throwable $e) {
		$pdo->rollBack();
		wlog('apply_deltas error: '.$e->getMessage());
		throw $e;
	}
}

/**
 * Try hard to pull a subscription id out of a Stripe Invoice object,
 * handling the various shapes Stripe may send.
 */
function invoice_subscription_id($invoice): string {
	// 1) Top-level (common, but not always present)
	if (!empty($invoice->subscription)) return (string)$invoice->subscription;

	// 2) Parent.subscription_details.subscription (seen in your payload)
	if (!empty($invoice->parent) && !empty($invoice->parent->subscription_details) &&
		!empty($invoice->parent->subscription_details->subscription)) {
		return (string)$invoice->parent->subscription_details->subscription;
	}

	// 3) Lines[...] -> parent.subscription_item_details.subscription
	if (!empty($invoice->lines) && is_array($invoice->lines->data)) {
		foreach ($invoice->lines->data as $li) {
			if (!empty($li->parent) && !empty($li->parent->subscription_item_details) &&
				!empty($li->parent->subscription_item_details->subscription)) {
				return (string)$li->parent->subscription_item_details->subscription;
			}
		}
	}
	return '';
}

// ---------- handlers ----------
try {
	switch ($type) {
		case 'checkout.session.completed': {
			$sessionId  = (string)($data->id ?? '');
			$companyRef = (int)($data->client_reference_id ?? 0);

			// Your updateSeats.php set `seat_changes_json` in Session metadata
			$deltasJson = (string)($data->metadata->seat_changes_json ?? '[]');

			// Amount is already cents/pence (no tax in your test)
			$pence = (int)($data->amount_total ?? 0);

			insert_change([
				'company_ref' => $companyRef,
				'session_id'  => $sessionId,
				'deltas_json' => $deltasJson,
				'pence'       => $pence,
			]);

			// If you want to apply immediately after successful checkout:
			

			wlog("checkout.session.completed stored (no apply) session=$sessionId cref=$companyRef");
			break;
		}

		case 'invoice.payment_succeeded': {
			$invoice = $data;
			$invoiceId = (string)($invoice->id ?? '');
		
			try {
				// Be flexible about where the subscription id lives.
				$subscriptionId = invoice_subscription_id($invoice);
				if ($subscriptionId === '') {
					wlog("invoice $invoiceId: no subscription id found on invoice");
					break; // nothing to apply; leave for manual review
				}
		
				// Find the Checkout Session for this subscription that produced THIS invoice.
				$sessions = \Stripe\Checkout\Session::all(['subscription' => $subscriptionId, 'limit' => 50]);
				$target = null;
				foreach ($sessions->data as $s) {
					if (($s->invoice ?? null) === $invoiceId) { $target = $s; break; }
				}
				if (!$target) {
					wlog("invoice $invoiceId: no Checkout Session matched; sub=$subscriptionId");
					break; // don’t 500, let Stripe stop retrying once we log it
				}
		
				$sessionId = (string)$target->id;
		
				// Load stored deltas for that session.
				$stmt = $pdo->prepare("SELECT COMPANY_REF, DELTAS_JSON, APPLIED_AT
									   FROM company_seat_changes
									   WHERE STRIPE_SESSION_ID = :sid
									   LIMIT 1");
				$stmt->execute([':sid' => $sessionId]);
				$row = $stmt->fetch(PDO::FETCH_ASSOC);
				if (!$row) {
					wlog("invoice $invoiceId: no pending row for session $sessionId");
					break;
				}
		
				// Idempotency: if already applied, do nothing.
				if (!empty($row['APPLIED_AT'])) {
					wlog("invoice $invoiceId: already applied for sid=$sessionId");
					break;
				}
		
				apply_deltas((int)$row['COMPANY_REF'], (string)$row['DELTAS_JSON'], $sessionId);
				wlog("invoice.payment_succeeded applied; sid=$sessionId cref=".$row['COMPANY_REF']);
			} catch (\Throwable $e) {
				wlog('invoice.payment_succeeded handler error: '.$e->getMessage());
				throw $e; // let outer catch 500 so Stripe retries if it’s a transient error
			}
			break;
		}

		default:
			wlog("Unhandled type: $type");
	}

	http_response_code(200);
	echo 'ok';
} catch (\Throwable $e) {
	wlog('Handler error: '.$e->getMessage());
	http_response_code(500);
	echo 'handler-error';
}