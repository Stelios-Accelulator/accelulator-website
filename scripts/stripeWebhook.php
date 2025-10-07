<?php
// scripts/stripeWebhook.php
declare(strict_types=1);

// ---------- mini logging helper (goes to /tmp on one.com) ----------
function wlog(string $msg): void {
	@file_put_contents('/tmp/stripe_webhook.log',
		'['.date('c')."] ".$msg.PHP_EOL, FILE_APPEND);
}

// ---------- resilient header getter (getallheaders() often missing) ----------
function header_val(string $name): string {
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	if (isset($_SERVER[$key])) return $_SERVER[$key];
	if (function_exists('getallheaders')) {
		$h = getallheaders();
		if (isset($h[$name])) return $h[$name];
		if (isset($h[strtolower($name)])) return $h[strtolower($name)];
		if (isset($h[strtoupper($name)])) return $h[strtoupper($name)];
	}
	return '';
}

// ---------- no session needed here ----------
ini_set('display_errors', '0');          // don't echo fatal noise to Stripe
error_reporting(E_ALL);

// Your app bootstrap (PDO + cfg())
require_once __DIR__ . '/../includes/functions.php';

// Load Stripe SDK (Composer preferred, manual zip fallback)
$loaded = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
	require __DIR__ . '/../vendor/autoload.php';
	$loaded = true;
} elseif (file_exists(__DIR__ . '/../vendor/stripe/stripe-php/init.php')) {
	require __DIR__ . '/../vendor/stripe/stripe-php/init.php';
	$loaded = true;
}
if (!$loaded) {
	wlog('SDK missing');
	http_response_code(500);
	echo 'sdk-missing';
	exit;
}

// Get your signing secret from includes/config.php (as a CONSTANT)
$endpointSecret = cfg('STRIPE_WEBHOOK_SECRET', '');
if ($endpointSecret === '') {
	wlog('No STRIPE_WEBHOOK_SECRET');
	http_response_code(500);
	echo 'secret-missing';
	exit;
}

// Read & verify the event
$payload = file_get_contents('php://input') ?: '';
$sig     = header_val('Stripe-Signature');

try {
	$event = \Stripe\Webhook::constructEvent($payload, $sig, $endpointSecret);
} catch (\Throwable $e) {
	wlog('Bad signature or JSON: '.$e->getMessage());
	http_response_code(400);
	echo 'bad-signature';
	exit;
}

$type = $event->type ?? '';
$data = $event->data->object ?? null;

// --- make PDO throws surface in our log (if not already set in pdoSetup.php)
if ($pdo instanceof PDO) {
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// --- helpers with strong logging
function insert_change(array $row): void {
	global $pdo;
	if (!($pdo instanceof PDO)) throw new RuntimeException('PDO not initialised');

	$sql = "INSERT INTO company_seat_changes
			(COMPANY_REF, STRIPE_SESSION_ID, SUBSCRIPTION_ID, CREATED_AT, PROCESSED_AT, APPLIED_AT, DELTAS_JSON, TODAY_EX_VAT_PENCE)
			VALUES (:cref, :sid, :sub, NOW(), NULL, NULL, :deltas, :pence)";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([
		':cref'   => (string)($row['company_ref'] ?? '0'),
		':sid'    => (string)($row['session_id'] ?? ''),
		':sub'    => (string)($row['subscription_id'] ?? ''),   // <-- NEW
		':deltas' => (string)($row['deltas_json'] ?? '{}'),
		':pence'  => (int)($row['pence'] ?? 0),
	]);
}

function mark_processed(string $sessionId): void {
	global $pdo;
	try {
		$stmt = $pdo->prepare("UPDATE company_seat_changes
							   SET PROCESSED_AT = NOW()
							   WHERE STRIPE_SESSION_ID = :sid AND PROCESSED_AT IS NULL");
		$stmt->execute([':sid' => $sessionId]);
		wlog('Marked processed for '.$sessionId.' rows='.$stmt->rowCount());
	} catch (Throwable $e) {
		wlog('mark_processed FAIL: '.$e->getMessage());
		throw $e;
	}
}

function apply_company_seat_changes(array $row): void {
	global $pdo;
	$pdo->beginTransaction();
	try {
		$cref   = (int)$row['COMPANY_REF'];
		$deltas = json_decode($row['DELTAS_JSON'] ?? '[]', true) ?: [];

		foreach ($deltas as $d) {
			$ref   = (int)($d['ref']   ?? 0);   // access level id
			$delta = (int)($d['delta'] ?? 0);   // +/- seats

			// Update existing row; if nothing updated, insert a new one.
			$u = $pdo->prepare("UPDATE company_seats
								SET SEATS_COMMITTED = GREATEST(0, SEATS_COMMITTED + :delta),
									UPDATED_AT = NOW()
								WHERE COMPANY_REF = :cref AND ACCESS_LEVEL_REF = :ref");
			$u->execute([':delta'=>$delta, ':cref'=>$cref, ':ref'=>$ref]);

			if ($u->rowCount() === 0) {
				$i = $pdo->prepare("INSERT INTO company_seats
									(COMPANY_REF, ACCESS_LEVEL_REF, SEATS_COMMITTED, CREATED_AT, UPDATED_AT)
									VALUES (:cref, :ref, GREATEST(0, :delta), NOW(), NOW())");
				$i->execute([':cref'=>$cref, ':ref'=>$ref, ':delta'=>$delta]);
			}
		}
		$pdo->commit();
	} catch (\Throwable $e) {
		$pdo->rollBack();
		throw $e;
	}
}

// --- first day of next month (UTC) as MySQL DATE ---
function firstOfNextMonthMySQL(): string {
	$dt = new DateTime('first day of next month', new DateTimeZone('UTC'));
	return $dt->format('Y-m-d');
}

/**
 * Take the row you inserted into company_seat_changes for a Checkout Session
 * and apply its deltas to company_seats. Idempotent: if already applied it no-ops.
 */
function apply_deltas_for_session(string $sessionId): void {
	global $pdo;

	$pdo->beginTransaction();

	// Lock the change row
	$stmt = $pdo->prepare("
		SELECT * FROM company_seat_changes
		WHERE STRIPE_SESSION_ID = :sid
		FOR UPDATE
	");
	$stmt->execute([':sid' => $sessionId]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$row) { $pdo->commit(); return; }          // nothing recorded
	if (!empty($row['APPLIED_AT'])) { $pdo->commit(); return; } // already applied

	$companyRef = (int)$row['COMPANY_REF'];
	$deltas     = json_decode($row['DELTAS_JSON'] ?? '[]', true) ?: [];

	foreach ($deltas as $d) {
		$accessRef = (int)($d['ref'] ?? 0);
		$delta     = (int)($d['delta'] ?? 0);
		if ($accessRef <= 0 || $delta === 0) continue;

		if ($delta > 0) {
			// immediate increase
			$u = $pdo->prepare("
				UPDATE company_seats
				   SET SEATS_COMMITTED = GREATEST(0, SEATS_COMMITTED + :delta),
					   UPDATED_AT      = NOW()
				 WHERE COMPANY_REF = :cref AND ACCESS_LEVEL_REF = :aref
				 LIMIT 1
			");
			$u->execute([':delta'=>$delta, ':cref'=>$companyRef, ':aref'=>$accessRef]);

			if ($u->rowCount() === 0) {
				$i = $pdo->prepare("
					INSERT INTO company_seats
						(COMPANY_REF, ACCESS_LEVEL_REF, SEATS_COMMITTED, SEATS_PENDING, PENDING_EFFECTIVE, CREATED_AT, UPDATED_AT)
					VALUES (:cref, :aref, :committed, 0, NULL, NOW(), NOW())
				");
				$i->execute([':cref'=>$companyRef, ':aref'=>$accessRef, ':committed'=>$delta]);
			}
		} else {
			// stage reduction for next renewal
			$next1st = firstOfNextMonthMySQL();
			$u = $pdo->prepare("
				UPDATE company_seats
				   SET SEATS_PENDING = SEATS_PENDING + :delta,
					   PENDING_EFFECTIVE = COALESCE(PENDING_EFFECTIVE, :eff),
					   UPDATED_AT = NOW()
				 WHERE COMPANY_REF = :cref AND ACCESS_LEVEL_REF = :aref
				 LIMIT 1
			");
			$u->execute([':delta'=>$delta, ':eff'=>$next1st, ':cref'=>$companyRef, ':aref'=>$accessRef]);

			if ($u->rowCount() === 0) {
				$i = $pdo->prepare("
					INSERT INTO company_seats
						(COMPANY_REF, ACCESS_LEVEL_REF, SEATS_COMMITTED, SEATS_PENDING, PENDING_EFFECTIVE, CREATED_AT, UPDATED_AT)
					VALUES (:cref, :aref, 0, :pending, :eff, NOW(), NOW())
				");
				$i->execute([':cref'=>$companyRef, ':aref'=>$accessRef, ':pending'=>$delta, ':eff'=>$next1st]);
			}
		}
	}

	// mark this change row as applied
	$pdo->prepare("UPDATE company_seat_changes SET APPLIED_AT = NOW() WHERE ID = :id LIMIT 1")
		->execute([':id' => $row['ID']]);

	$pdo->commit();
}

try {
	wlog('Event '.$type);

	switch ($type) {
		case 'checkout.session.completed':
		$sessionId     = (string)($data->id ?? '');
		$subscriptionId= (string)($data->subscription ?? '');    // <-- NEW
		$companyRef    = (string)($data->client_reference_id ?? '0');
		$deltasJson    = (string)($data->metadata->seat_changes_json ?? '{}');
		
		$pence = 0; // optional to compute
		
		insert_change([
			'company_ref'     => $companyRef,
			'session_id'      => $sessionId,
			'subscription_id' => $subscriptionId,                // <-- NEW
			'deltas_json'     => $deltasJson,
			'pence'           => $pence,
		]);
		
		wlog("Inserted change for session {$sessionId} sub {$subscriptionId} cref {$companyRef}");
		break;
		}

		case 'invoice.payment_succeeded':
		$subId = (string)($data->subscription ?? '');
		if ($subId === '') {
			wlog('invoice.payment_succeeded without subscription id');
			break; // nothing to do
		}
		
		// Find the pending change for this subscription
		$stmt = $pdo->prepare("SELECT * FROM company_seat_changes
							   WHERE SUBSCRIPTION_ID = :sub AND PROCESSED_AT IS NULL
							   ORDER BY ID DESC LIMIT 1");
		$stmt->execute([':sub' => $subId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		
		if ($row) {
			apply_company_seat_changes($row);
		
			$upd = $pdo->prepare("UPDATE company_seat_changes
								  SET PROCESSED_AT = NOW(), APPLIED_AT = NOW()
								  WHERE ID = :id");
			$upd->execute([':id' => $row['ID']]);
		
			wlog("Applied seat change for sub {$subId} (row {$row['ID']})");
		} else {
			wlog("No pending seat change found for sub {$subId}");
		}
		break;
		}

		default:
			wlog('Unhandled '.$type);
	}

	http_response_code(200);
	echo 'ok';
} catch (Throwable $e) {
	wlog('Handler error: '.$e->getMessage());
	http_response_code(500);
	echo 'handler-error';
}