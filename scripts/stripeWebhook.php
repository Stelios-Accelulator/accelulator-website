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

// Make sure PDO throws so we see errors in wlog()
if ($pdo instanceof PDO) {
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

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

/**
 * Apply a set of seat deltas to company_seats and mark the change row APPLIED_AT.
 * Idempotent by STRIPE_SESSION_ID: if already applied, it exits cleanly.
 *
 * @param int         $companyRef
 * @param string      $deltasJson   e.g. [{"ref":7,"delta":1},{"ref":9,"delta":2}]
 * @param string      $sessionId     Stripe Checkout session id
 * @param string|null $subscriptionId Optional subscription id for traceability
 */
 function apply_deltas(int $companyRef, string $deltasJson, string $sessionId, ?string $subscriptionId = null): void
 {
	 /** @var PDO $pdo */
	 global $pdo; // use the PDO provided by includes/functions.php
 
	 // Fetch the change row; if already applied, exit
	 $q = $pdo->prepare("SELECT id, applied_at FROM company_seat_changes WHERE STRIPE_SESSION_ID = :sid LIMIT 1");
	 $q->execute([':sid' => $sessionId]);
	 $change = $q->fetch(PDO::FETCH_ASSOC);
 
	 if (!$change) {
		 wlog("apply_deltas: no change row for sid=$sessionId");
		 return;
	 }
	 if (!empty($change['applied_at'])) {
		 wlog("apply_deltas: already applied sid=$sessionId");
		 return;
	 }
 
	 $deltas = json_decode($deltasJson, true);
	 if (!is_array($deltas) || !$deltas) {
		 wlog("apply_deltas: empty/invalid deltas sid=$sessionId");
		 return;
	 }
 
	 try {
		 $pdo->beginTransaction();
 
		 // UPDATE then INSERT for (COMPANY_REF, ACCESS_LEVEL_REF)
		 $upd = $pdo->prepare("
			 UPDATE company_seats
				SET SEATS_COMMITTED = SEATS_COMMITTED + :delta,
					UPDATED_AT      = NOW()
			  WHERE COMPANY_REF      = :c
				AND ACCESS_LEVEL_REF = :a
		 ");
		 $ins = $pdo->prepare("
			 INSERT INTO company_seats
				 (COMPANY_REF, ACCESS_LEVEL_REF, SEATS_COMMITTED, SEATS_PENDING, PENDING_EFFECTIVE, CREATED_AT, UPDATED_AT)
			 VALUES
				 (:c, :a, :delta, 0, NULL, NOW(), NOW())
		 ");
 
		 foreach ($deltas as $d) {
			 $accessRef = (int)($d['ref']   ?? 0);
			 $delta     = (int)($d['delta'] ?? 0);
			 if ($accessRef <= 0 || $delta === 0) continue;
 
			 $upd->execute([':delta' => $delta, ':c' => $companyRef, ':a' => $accessRef]);
			 if ($upd->rowCount() === 0) {
				 $ins->execute([':c' => $companyRef, ':a' => $accessRef, ':delta' => $delta]);
			 }
		 }
 
		 // Mark as applied + processed
		 $mark = $pdo->prepare("
			 UPDATE company_seat_changes
				SET APPLIED_AT      = NOW(),
					PROCESSED_AT    = NOW(),
					SUBSCRIPTION_ID = COALESCE(SUBSCRIPTION_ID, :sub)
			  WHERE STRIPE_SESSION_ID = :sid
				AND APPLIED_AT IS NULL
		 ");
		 $mark->execute([':sid' => $sessionId, ':sub' => $subscriptionId]);
 
		 $pdo->commit();
		 wlog("apply_deltas: applied sid=$sessionId company=$companyRef");
	 } catch (Throwable $e) {
		 if ($pdo->inTransaction()) $pdo->rollBack();
		 wlog('apply_deltas error: '.$e->getMessage());
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
			try {
				// Stripe object for the session
				$data = $event->data->object; // checkout.session
		
				$sessionId      = (string)$data->id;
				$companyRef     = (int)($data->client_reference_id ?? 0);
				$subscriptionId = isset($data->subscription) ? (string)$data->subscription : null;
				$deltasJson     = isset($data->metadata->seat_changes_json) ? (string)$data->metadata->seat_changes_json : '[]';
				$paidStatus     = strtolower((string)($data->payment_status ?? ''));
		
				// Minimal validation + visibility
				if ($companyRef <= 0)  { wlog("cs.completed: missing companyRef for sid=$sessionId");  echo 'ok'; http_response_code(200); break; }
				if (trim($deltasJson) === '') $deltasJson = '[]';
		
				// 1) Persist/Upsert into company_seat_changes now (no helper ambiguity)
				/** @var PDO $pdo */
				global $pdo; // use shared PDO
		
				// (Optional but recommended) ensure there's a unique index on STRIPE_SESSION_ID
				// ALTER TABLE company_seat_changes ADD UNIQUE KEY uq_session (STRIPE_SESSION_ID);
		
				$ins = $pdo->prepare("
					INSERT INTO company_seat_changes
						(COMPANY_REF, STRIPE_SESSION_ID, SUBSCRIPTION_ID, DELTAS_JSON, TODAY_EX_VAT_PENCE, CREATED_AT)
					VALUES
						(:c, :sid, :sub, :deltas, :pence, NOW())
					ON DUPLICATE KEY UPDATE
						SUBSCRIPTION_ID = VALUES(SUBSCRIPTION_ID),
						DELTAS_JSON     = VALUES(DELTAS_JSON)
				");
		
				// ex-VAT if available; falls back to total
				$pence = (int)($data->amount_subtotal ?? $data->amount_total ?? 0);
		
				$ins->execute([
					':c'      => $companyRef,
					':sid'    => $sessionId,
					':sub'    => $subscriptionId,
					':deltas' => $deltasJson,
					':pence'  => $pence,
				]);
		
				wlog("cs.completed: stored change sid=$sessionId cref=$companyRef paid=$paidStatus pence=$pence deltas=$deltasJson");
		
				// 2) Apply immediately if Checkout reports paid (covers one-off activation)
				if ($paidStatus === 'paid') {
					apply_deltas($companyRef, $deltasJson, $sessionId, $subscriptionId);
					wlog("cs.completed: applied sid=$sessionId");
				} else {
					wlog("cs.completed: not applied (status=$paidStatus) sid=$sessionId");
				}
		
				echo 'ok'; http_response_code(200);
			} catch (Throwable $e) {
				wlog('cs.completed error: '.$e->getMessage());
				// Still return 200 so Stripe doesn’t retry forever; the log tells us what failed
				echo 'handled'; http_response_code(200);
			}
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
				
				global $pdo;
				
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
		
		case 'checkout.session.async_payment_succeeded': {
			try {
				$data = $event->data->object; // checkout.session
		
				$sessionId      = (string)$data->id;
				$companyRef     = (int)($data->client_reference_id ?? 0);
				$subscriptionId = isset($data->subscription) ? (string)$data->subscription : null;
				$deltasJson     = isset($data->metadata->seat_changes_json) ? (string)$data->metadata->seat_changes_json : '[]';
		
				/** @var PDO $pdo */
				global $pdo;
		
				$ins = $pdo->prepare("
					INSERT INTO company_seat_changes
						(COMPANY_REF, STRIPE_SESSION_ID, SUBSCRIPTION_ID, DELTAS_JSON, TODAY_EX_VAT_PENCE, CREATED_AT)
					VALUES
						(:c, :sid, :sub, :deltas, :pence, NOW())
					ON DUPLICATE KEY UPDATE
						SUBSCRIPTION_ID = VALUES(SUBSCRIPTION_ID),
						DELTAS_JSON     = VALUES(DELTAS_JSON)
				");
				$pence = (int)($data->amount_subtotal ?? $data->amount_total ?? 0);
				$ins->execute([':c'=>$companyRef, ':sid'=>$sessionId, ':sub'=>$subscriptionId, ':deltas'=>$deltasJson, ':pence'=>$pence]);
		
				apply_deltas($companyRef, $deltasJson, $sessionId, $subscriptionId);
				wlog("cs.async_payment_succeeded: applied sid=$sessionId");
				echo 'ok'; http_response_code(200);
			} catch (Throwable $e) {
				wlog('cs.async_payment_succeeded error: '.$e->getMessage());
				echo 'handled'; http_response_code(200);
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