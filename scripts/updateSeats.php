<?php
// scripts/updateSeats.php
declare(strict_types=1); // forces strict scalar type-checking for this script. Good for catching accidental string->int coercions (e.g. seat counts, pennies)

// --- session: keep cookie name stable and open session early ---

// Ensure a stable session cookie name and start the session early so we can
// (a) validate the authenticated user and CSRF token, and 
// (b) optionally pass the session id into Stripe metadata for later reconciliation.

if (session_status() !== PHP_SESSION_ACTIVE) {
	if (ini_get('session.name') !== 'PHPSESSID') {
		session_name('PHPSESSID');
	}
	session_start();
}

// --- Shared helpers ---
// Pull in DB connection ($pdo), cfg() for config, json_response(), and auth utilities (e.g., checkUser()).
require_once __DIR__ . '/../includes/functions.php';

// --- CSRF validation (header-based) ---
// Compare X-CSRF-Token header to the session token and fail fast if they don't match.

function get_header_value(string $name): string {
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	return $_SERVER[$key] ?? '';
}

$incoming     = get_header_value('X-CSRF-Token');
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (!is_string($incoming) || !is_string($sessionToken) || $incoming === '' || $sessionToken === '' || !hash_equals($sessionToken, $incoming)) {
	
	http_response_code(403);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'status'  => 'error',
		'reason'  => 'csrf_mismatch',
		'sid'     => session_id(),
		'cookie'  => ($_COOKIE['PHPSESSID'] ?? null),
		'h'       => $incoming,
		's'       => $sessionToken,
		'path'    => ini_get('session.cookie_path'),
		'domain'  => ini_get('session.cookie_domain'),
		'save_to' => ini_get('session.save_path'),
	]);
	
	exit;

}

// --- Secondary CSRF check ---
// Also run your functions.php validator; safe to keep both.
if (!function_exists('validateCsrfToken') || !validateCsrfToken()) { // Checks if the csrf token is valid and, if it isn't exits the script
	json_response(['status' => 'error', 'message' => 'Invalid CSRF token (validator)'], 403);
	exit;
}

// --- JSON error/exception handlers ---
// Ensures PHP notices/exceptions return JSON rather than blank pages.

ini_set('display_errors', '1');

error_reporting(E_ALL);

set_error_handler(function($no,$str,$file,$line){
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'status'=>'error',
		'where'=>'handler',
		'message'=>"PHP error: $str at $file:$line"
	]);
	exit;
});

set_exception_handler(function($e){
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'status'=>'error',
		'where'=>'exception',
		'message'=>$e->getMessage()
	]);
	exit;
});

/* --- Parse request body ---
Expect body: { changes: [{ref:<access_level_ref>, delta:<+/- seats>}, ...] }
Tolerant to shapes: single object, array of objects, or map { ref: delta }. */

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) json_response(['status'=>'error','message'=>'Invalid JSON body'], 400);
$changesRaw = $body['changes'] ?? null;
if (!is_array($changesRaw)) json_response(['status'=>'error','message'=>'Missing "changes"'], 400);

// Normalise into a flat list of {ref:int, delta:int}
$changesList = [];
if (isset($changesRaw['ref']) && array_key_exists('delta',$changesRaw)) {
	$changesList[] = [
		'ref'=>(int)$changesRaw['ref'],
		'delta'=>(int)$changesRaw['delta']
	];
} elseif (array_is_list($changesRaw)) {
	foreach ($changesRaw as $row) {
		if (is_array($row) && isset($row['ref']) && array_key_exists('delta',$row)) {
			$changesList[] = [
				'ref'=>(int)$row['ref'],
				'delta'=>(int)$row['delta']
			];
		}
	}
} else {
	foreach ($changesRaw as $ref=>$delta) $changesList[] = [
		'ref'=>(int)$ref,
		'delta'=>(int)$delta
	];
}

// --- Split into increases (bill now) vs reductions (apply at renewal) ---
$increases  = [];
$reductions = [];

foreach ($changesList as $change) {
	$ref   = (int)($change['ref'] ?? 0);
	$delta = (int)($change['delta'] ?? 0);
	if ($ref === 0 || $delta === 0) continue;
	if     ($delta > 0) $increases[]  = [
		'ref' => $ref, 
		'delta' => $delta
	];
	elseif ($delta < 0) $reductions[] = [
		'ref' => $ref, 
		'delta' => $delta
	]; // keep negative
}

// Resolve company once (used for both reductions + increases)
$user       = checkUser();                      // email (your function)
$cRef       = getUsersCompanyId($user);         // company id (your function)
$companyRef = (int)($cRef ?? 0);

// --- Helper: queue reductions to apply at renewal (first of next month UTC) ---
// Inserts a row into company_seat_changes storing the JSON deltas, zero "today ex-VAT",
// and optionally APPLY_AFTER if that column exists (keeps environments in sync).
function queue_reductions(PDO $pdo, int $companyRef, array $reductions): array {
	// First of next month, 00:00:00 UTC
	$applyAfter = (new DateTime('first day of next month 00:00:00', new DateTimeZone('UTC')))
		->format('Y-m-d H:i:s');
	// Detect presence of APPLY_AFTER column
	$colExists = $pdo->query("
		SHOW COLUMNS FROM company_seat_changes LIKE 'APPLY_AFTER'
	")->fetch(PDO::FETCH_ASSOC);
	if ($colExists) {
		$sql = "
			INSERT INTO company_seat_changes
				(COMPANY_REF, STRIPE_SESSION_ID, CREATED_AT, PROCESSED_AT, APPLIED_AT, DELTAS_JSON, TODAY_EX_VAT_PENCE, APPLY_AFTER)
			VALUES
				(:cref, NULL, NOW(), NULL, NULL, :deltas, 0, :apply_after)
		";
		$params = [
			':cref'        => $companyRef,
			':deltas'      => json_encode($reductions, JSON_UNESCAPED_SLASHES),
			':apply_after' => $applyAfter,
		];
	} else {
		// Backwards-compatible insert if APPLY_AFTER not available yet
		$sql = "
			INSERT INTO company_seat_changes
				(COMPANY_REF, STRIPE_SESSION_ID, CREATED_AT, PROCESSED_AT, APPLIED_AT, DELTAS_JSON, TODAY_EX_VAT_PENCE)
			VALUES
				(:cref, NULL, NOW(), NULL, NULL, :deltas, 0)
		";
		$params = [
			':cref'   => $companyRef,
			':deltas' => json_encode($reductions, JSON_UNESCAPED_SLASHES),
		];
	}
	$stmt = $pdo->prepare($sql);
	if (!$stmt->execute($params)) {
		$info = $stmt->errorInfo(); // [SQLSTATE, driver_code, driver_msg]
		throw new RuntimeException('Queue insert failed: ' . implode(' | ', array_filter($info)));
	}
	return [
		'queued'      => count($reductions),
		'apply_after' => $colExists ? $applyAfter : null,
	];
}

// --- Queue reductions now; early-exit if there are no increases ---
$queued = null;
if (!empty($reductions)) {
	try {
		$queued = queue_reductions($pdo, $companyRef, $reductions);
	} catch (Throwable $e) {
		json_response([
			'status'  => 'error',
			'message' => 'Could not queue reductions',
			'detail'  => $e->getMessage(),   // 👈 this is the important bit
		], 500);
		exit;
	}
}

if (empty($increases) && !empty($reductions)) {
	json_response([
		'status'      => 'success',
		'kind'        => 'reductions_queued',
		'queued'      => $queued['queued'] ?? 0,
		'apply_after' => $queued['apply_after'] ?? null,
		'message'     => 'Reductions recorded — they’ll apply at renewal.',
	]);
	exit;
}

if (empty($increases) && empty($reductions)) {
	json_response(['status'=>'ok','message'=>'No seat changes.']);
	exit;
}

// --- Stripe path for increases ---
// Load Stripe (Composer or manual zip), set secret from config.
$loadedStripe     = false;
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
$manualInit       = __DIR__ . '/../vendor/stripe/stripe-php/init.php';

if (file_exists($composerAutoload)) {
	require_once $composerAutoload;
	$loadedStripe = true;
} elseif (file_exists($manualInit)) {
	require_once $manualInit;
	$loadedStripe = true;
}
if (!$loadedStripe) {
	json_response([
		'status'  => 'error',
		'message' => 'Stripe SDK not found. Expected vendor/autoload.php or vendor/stripe/stripe-php/init.php'
	], 500);
	exit;
}

// Get secret from your config (functions.php → cfg())
$secret = cfg('STRIPE_SECRET_KEY', '');
if (!$secret) {
	json_response(['status'=>'error','message'=>'STRIPE_SECRET_KEY missing in includes/config.php'], 500);
	exit;
}
\Stripe\Stripe::setApiKey($secret);

// --- Build line items from access levels ---
// Query access levels (REF, NAME, MRR) and map increases to monthly recurring line items.
$levels = $pdo->query("SELECT REF, NAME, MRR FROM access_level")->fetchAll(PDO::FETCH_ASSOC);
$byRef  = [];
foreach ($levels as $l) $byRef[(int)$l['REF']] = $l;

$lineItems = [];
foreach ($increases as $inc) {
	$lvl = $byRef[(int)$inc['ref']] ?? null;
	if (!$lvl) continue;

	$unitAmount = (int) round(((float)$lvl['MRR']) * 100); // pence
	if ($unitAmount <= 0) continue;

	$lineItems[] = [
		'price_data' => [
			'currency'     => 'gbp',
			'product_data' => ['name' => $lvl['NAME']],
			'unit_amount'  => $unitAmount,
			'recurring'    => ['interval' => 'month'],
		],
		'quantity' => (int)$inc['delta'],
	];
}
if (!$lineItems) {
	json_response(['status'=>'ok','message'=>'No valid line items built.'], 200);
	exit;
}

// --- Create Checkout Session (subscription mode) ---
// Return URL carries {CHECKOUT_SESSION_ID} so you can reconcile client-side if needed.
$successUrl = cfg('STRIPE_RETURN_URL', 'https://accelulator.com/pages/companySettings.php?paid=1') . '&session_id={CHECKOUT_SESSION_ID}';
$cancelUrl  = cfg('STRIPE_CANCEL_URL', 'https://accelulator.com/pages/companySettings.php?cancel=1');

try {
	$session = \Stripe\Checkout\Session::create([
		'mode'                 => 'subscription',
		'line_items'           => $lineItems,
		'success_url'          => $successUrl,
		'cancel_url'           => $cancelUrl,
		'client_reference_id'  => (string)$companyRef,
		'payment_method_types' => ['card'],
		'metadata'             => [
			// keep a copy of what we’re billing for in case webhook is late
			'seat_changes_json' => json_encode($increases),
		],
		// Make company_ref readily available to the webhook via subscription metadata
		'subscription_data' => [
			'metadata' => [
				'company_ref' => (string)$companyRef,
			],
		],
	]);

	// Convenience: also put the Checkout Session ID into metadata (optional)
	\Stripe\Checkout\Session::update($session->id, [
		'metadata' => ['checkout_session_id' => $session->id],
	]);

	json_response(['status' => 'ok', 'url' => $session->url], 200);

} catch (\Throwable $e) {
	json_response(['status' => 'error', 'message' => 'Stripe error: '.$e->getMessage()], 500);
}