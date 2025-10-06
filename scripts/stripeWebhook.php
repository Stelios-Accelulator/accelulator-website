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
	try {
		$sql = "INSERT INTO company_seat_changes
				(COMPANY_REF, STRIPE_SESSION_ID, CREATED_AT, PROCESSED_AT, DELTAS_JSON, TODAY_EX_VAT_PENCE)
				VALUES (:cref, :sid, NOW(), NULL, :deltas, :pence)";
		$stmt = $pdo->prepare($sql);
		$stmt->execute([
			':cref'   => (string)($row['company_ref'] ?? '0'),
			':sid'    => (string)($row['session_id'] ?? ''),
			':deltas' => (string)($row['deltas_json'] ?? '{}'),
			':pence'  => (int)($row['pence'] ?? 0),
		]);
		wlog('DB insert OK for session '.$row['session_id']);
	} catch (Throwable $e) {
		wlog('DB insert FAIL: '.$e->getMessage());
		if (isset($stmt)) { wlog('errorInfo='.json_encode($stmt->errorInfo())); }
		throw $e;
	}
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

try {
	wlog('Event '.$type);

	switch ($type) {
		case 'checkout.session.completed': {
			$sessionId  = (string)($data->id ?? '');
			$companyRef = (string)($data->client_reference_id ?? '0');
			$deltasJson = (string)($data->metadata->seat_changes_json ?? '{}');
			$pence      = (int)($data->amount_total ?? 0); // Stripe sends this in the event

			wlog("checkout.session.completed sid={$sessionId} cref={$companyRef} pence={$pence} deltas={$deltasJson}");

			insert_change([
				'company_ref' => $companyRef,
				'session_id'  => $sessionId,
				'deltas_json' => $deltasJson,
				'pence'       => $pence,
			]);
			break;
		}

		case 'invoice.payment_succeeded': {
			// If you add checkout_session_id to invoice metadata when you create the Session,
			// this will flip PROCESSED_AT. Otherwise, this is a no-op (still 200 OK).
			$sessionId = (string)($data->metadata->checkout_session_id ?? '');
			if ($sessionId !== '') {
				mark_processed($sessionId);
			} else {
				wlog('invoice.payment_succeeded without checkout_session_id metadata');
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