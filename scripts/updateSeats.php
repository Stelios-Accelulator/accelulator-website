<?php
// scripts/updateSeats.php
declare(strict_types=1);

// --- session: keep cookie name stable and open session early ---
if (session_status() !== PHP_SESSION_ACTIVE) {
	if (ini_get('session.name') !== 'PHPSESSID') {
		session_name('PHPSESSID');
	}
	session_start();
}

// include app helpers (PDO + cfg() + json_response() + checkUser() etc.)
require_once __DIR__ . '/../includes/functions.php';

// --- CSRF check using header (aligned with your earlier debug payload) ---
function get_header_value(string $name): string {
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	return $_SERVER[$key] ?? '';
}

$incoming     = get_header_value('X-CSRF-Token');
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (!is_string($incoming) || !is_string($sessionToken) ||
	$incoming === '' || $sessionToken === '' || !hash_equals($sessionToken, $incoming)) {

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

// also enforce your functions.php validator (safe to keep both)
if (!function_exists('validateCsrfToken') || !validateCsrfToken()) {
	json_response(['status' => 'error', 'message' => 'Invalid CSRF token (validator)'], 403);
	exit;
}

// --- errors -> JSON (so Network tab shows something useful) ---
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_error_handler(function($no,$str,$file,$line){
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['status'=>'error','where'=>'handler','message'=>"PHP error: $str at $file:$line"]);
	exit;
});
set_exception_handler(function($e){
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['status'=>'error','where'=>'exception','message'=>$e->getMessage()]);
	exit;
});

// ---- parse body (your tolerant parser) ----
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) json_response(['status'=>'error','message'=>'Invalid JSON body'], 400);

$changesRaw = $body['changes'] ?? null;
if (!is_array($changesRaw)) json_response(['status'=>'error','message'=>'Missing "changes"'], 400);

// Normalize into $changesList (exactly as you had)
$changesList = [];
if (isset($changesRaw['ref']) && array_key_exists('delta',$changesRaw)) {
	$changesList[] = ['ref'=>(int)$changesRaw['ref'],'delta'=>(int)$changesRaw['delta']];
} elseif (array_is_list($changesRaw)) {
	foreach ($changesRaw as $row) {
		if (is_array($row) && isset($row['ref']) && array_key_exists('delta',$row)) {
			$changesList[] = ['ref'=>(int)$row['ref'],'delta'=>(int)$row['delta']];
		}
	}
} else {
	foreach ($changesRaw as $ref=>$delta) $changesList[] = ['ref'=>(int)$ref,'delta'=>(int)$delta];
}

// ---------- Build increases / reductions from $changesList ----------
$increases  = [];
$reductions = [];
foreach ($changesList as $change) {
	$ref   = (int)($change['ref'] ?? 0);
	$delta = (int)($change['delta'] ?? 0);
	if ($ref === 0 || $delta === 0) continue;
	if     ($delta > 0) $increases[]  = ['ref' => $ref, 'delta' => $delta];
	elseif ($delta < 0) $reductions[] = ['ref' => $ref, 'delta' => $delta]; // keep negative
}

// Resolve company once (used for both reductions + increases)
$user       = checkUser();                      // email (your function)
$cRef       = getUsersCompanyId($user);         // company id (your function)
$companyRef = (int)($cRef ?? 0);

// ---------- helper: queue reductions (no Stripe) ----------
/**
 * Queue reductions to be applied at renewal (first of next month, 00:00 UTC).
 * Returns ['queued'=>int,'apply_after'=>'Y-m-d H:i:s'|'NULL'].
 */
function queue_reductions(PDO $pdo, int $companyRef, array $reductions): array {
	// First of next month, 00:00:00 UTC
	$applyAfter = (new DateTime('first day of next month 00:00:00', new DateTimeZone('UTC')))
		->format('Y-m-d H:i:s');

	// Detect if APPLY_AFTER column exists (keeps prod/stage in sync)
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
		// Fall back for environments that don’t yet have APPLY_AFTER
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

// ---------- queue reductions now; early-return if only reductions ----------
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

// ---------- From here on: bill only the increases (Stripe) ----------

// Try to load Stripe (either Composer or manual zip)
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

// Build the line items from your access levels
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
		// helps webhook resolve companyRef on reductions-only cycles next month
		'subscription_data' => [
			'metadata' => [
				'company_ref' => (string)$companyRef,
			],
		],
	]);

	// add an explicit echo of session id into metadata for convenience (optional)
	\Stripe\Checkout\Session::update($session->id, [
		'metadata' => ['checkout_session_id' => $session->id],
	]);

	json_response(['status' => 'ok', 'url' => $session->url], 200);

} catch (\Throwable $e) {
	json_response(['status' => 'error', 'message' => 'Stripe error: '.$e->getMessage()], 500);
}