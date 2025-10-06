<?php
// scripts/updateSeats.php
declare(strict_types=1);

// 👇 Make absolutely sure we use the same cookie name and open the session first.
if (session_status() !== PHP_SESSION_ACTIVE) {
	if (ini_get('session.name') !== 'PHPSESSID') {
		session_name('PHPSESSID');
	}
	session_start();
}

// From here on we can safely include
require_once __DIR__ . '/../includes/functions.php';

// --- CSRF check (and one-time debug payload) ---
function get_header_value(string $name): string {
	// Works on hosts where getallheaders() is unavailable
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	return $_SERVER[$key] ?? '';
}

$incoming = get_header_value('X-CSRF-Token');
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
		'h'       => $incoming,          // header we received
		's'       => $sessionToken,      // token in $_SESSION
		'path'    => ini_get('session.cookie_path'),
		'domain'  => ini_get('session.cookie_domain'),
		'save_to' => ini_get('session.save_path'),
	]);
	exit;
}


// error -> JSON
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

if (session_status() === PHP_SESSION_NONE) session_start();

// include your app
require_once __DIR__ . '/../includes/functions.php';

// ---- CSRF: ensure the validator returns a boolean
if (!function_exists('validateCsrfToken')) {
  json_response(['status'=>'error','message'=>'CSRF validator missing in functions.php'], 500);
}
if (!validateCsrfToken()) {
  json_response(['status'=>'error','message'=>'Invalid CSRF token'], 403);
}

// ---- parse body (same tolerant parser as step 3)
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) json_response(['status'=>'error','message'=>'Invalid JSON body'], 400);

$changesRaw = $body['changes'] ?? null;
if (!is_array($changesRaw)) json_response(['status'=>'error','message'=>'Missing "changes"'], 400);

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

// ---------- DB + Stripe ----------

// Build increases / reductions from the parsed changes
$increases  = [];
$reductions = [];

// Optional: accumulate by ref so multiple edits to the same role collapse
$tmp = [];
foreach ($changesList as $c) {
	$ref   = (int)($c['ref']   ?? 0);
	$delta = (int)($c['delta'] ?? 0);
	if ($ref === 0 || $delta === 0) continue;
	$tmp[$ref] = ($tmp[$ref] ?? 0) + $delta;
}

foreach ($tmp as $ref => $delta) {
	if ($delta > 0) {
		$increases[]  = ['ref' => $ref, 'delta' => $delta];
	} elseif ($delta < 0) {
		// store reductions as a positive quantity (magnitude) if you like,
		// or keep the negative sign — just be consistent downstream
		$reductions[] = ['ref' => $ref, 'delta' => -$delta];
	}
}

// TEMP: quick visibility while testing
// json_response(['stage' => 'built-deltas', 'increases' => $increases, 'reductions' => $reductions]);

// If no increases, nothing to bill; you can commit any reductions and finish
if (empty($increases)) {
	json_response(['status' => 'ok', 'message' => 'No increases to bill.'], 200);
}

// Try to load Stripe (either Composer or manual zip)
$loadedStripe = false;
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
$manualInit      = __DIR__ . '/../vendor/stripe/stripe-php/init.php';

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
}

// Get secret from your config (functions.php → cfg())
$secret = cfg('STRIPE_SECRET_KEY', '');
if (!$secret) {
	json_response(['status'=>'error','message'=>'STRIPE_SECRET_KEY missing in includes/config.php'], 500);
}

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
			'recurring'    => ['interval' => 'month'], // we’re treating this like a seat sub
		],
		'quantity' => (int)$inc['delta'],
	];
}
if (!$lineItems) {
	json_response(['status'=>'ok','message'=>'No valid line items built.'], 200);
}

$user = checkUser();
$cRef = getUsersCompanyId($user);

// Create a one-off checkout for the delta (still mode: subscription gives the proration feel)
\Stripe\Stripe::setApiKey(cfg('STRIPE_SECRET_KEY'));

$companyRef = $cRef ?? '0';
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
	]);
	
	\Stripe\Checkout\Session::update($session->id, [
	  'metadata' => ['checkout_session_id' => $session->id],
	]);

	json_response(['status' => 'ok', 'url' => $session->url], 200);

} catch (\Throwable $e) {
	json_response(['status' => 'error', 'message' => 'Stripe error: '.$e->getMessage()], 500);
}