<?php
// scripts/updateSeats.php
declare(strict_types=1); // forces strict scalar type-checking for this script. Good for catching accidental string->int coercions (e.g. seat counts, pennies)
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/updateSeats_debug.log');

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

// --- Helpers to match Stripe's UTC second-based proration and net in-month decreases ---

/** Month bounds in UTC as DateTimeImmutables */
function month_bounds_utc(): array {
		$tz = new DateTimeZone('UTC');
		return [
				'start' => new DateTimeImmutable('first day of this month 00:00:00', $tz),
				'end'   => new DateTimeImmutable('last day of this month 23:59:59', $tz),
				'now'   => new DateTimeImmutable('now', $tz),
		];
}

/**
 * Compute available "credit seconds" for a company/access level from decreases made earlier this month,
 * net of any increases already made this month (which consume that credit).
 * No new tables required; derived from company_seat_changes.
 */
function available_credit_seconds_this_month(PDO $pdo, int $companyRef, int $accessRef, int $monthEndTs): int {
		// Pull this month's rows for the company
		$stmt = $pdo->prepare("
				SELECT STRIPE_SESSION_ID, DELTAS_JSON, CREATED_AT, APPLIED_AT
					FROM company_seat_changes
				 WHERE COMPANY_REF = :c
					 AND CREATED_AT >= DATE_FORMAT(UTC_DATE(), '%Y-%m-01')
					 AND CREATED_AT <  DATE_FORMAT(DATE_ADD(UTC_DATE(), INTERVAL 1 MONTH), '%Y-%m-01')
		");
		$stmt->execute([':c' => $companyRef]);

		$creditSeconds = 0;

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$createdTs = strtotime($row['CREATED_AT'] . ' UTC'); // treat as UTC
				$deltas = json_decode((string)$row['DELTAS_JSON'], true);
				if (!is_array($deltas)) continue;

				foreach ($deltas as $d) {
						$ref   = (int)($d['ref']   ?? 0);
						$delta = (int)($d['delta'] ?? 0);
						if ($ref !== $accessRef || $delta === 0) continue;

						$secondsRemainingFromEvent = max(0, $monthEndTs - $createdTs);

						if ($delta < 0) {
								// A decrease creates credit: seats * seconds remaining from the time of decrease
								$creditSeconds += (-$delta) * $secondsRemainingFromEvent;
						} elseif ($delta > 0 && !empty($row['APPLIED_AT'])) {
								// An applied increase consumes credit (if any): seats * seconds remaining from the time of that increase
								$creditSeconds -= $delta * $secondsRemainingFromEvent;
						}
				}
		}

		return max(0, $creditSeconds);
}

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
	// also log the exception
	@error_log('[updateSeats] EXCEPTION ' . $e->getMessage());
	exit;
});

/** DEBUG: force logs to a file right next to this script */
if (!defined('SEAT_DBG')) define('SEAT_DBG', true);
$__DBG_FILE = __DIR__ . '/updateSeats_debug.log';
@ini_set('log_errors', '1');                 // ensure PHP will log errors
@ini_set('error_log', $__DBG_FILE);          // route error_log() to our file (many hosts allow this)

/** Dual-writer: write to error_log(), and also append directly to the same file */
function dlog(string $label, $data = null): void {
	if (!SEAT_DBG) return;
	global $__DBG_FILE;

	$payload = $data === null ? '' : ' ' . json_encode($data, JSON_UNESCAPED_SLASHES);
	$line = '[updateSeats] ' . $label . $payload;

	// 1) PHP error_log (will go to $__DBG_FILE thanks to ini_set)
	@error_log($line);

	// 2) Direct file append (bypasses PHP logging in case ini_set is ignored)
	@file_put_contents($__DBG_FILE, $line . PHP_EOL, FILE_APPEND);
}

/** BOOT probe: prove we can write, and show paths/users */
dlog('BOOT', [
	'dir'      => __DIR__,
	'cwd'      => @getcwd(),
	'user'     => @get_current_user(),
	'php_sapi' => PHP_SAPI,
	'file_exists' => @file_exists($__DBG_FILE),
	'writable'    => @is_writable(__DIR__)
]);

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

dlog('incoming.changesList', $changesList);
dlog('incoming.hit', [
	'method' => $_SERVER['REQUEST_METHOD'] ?? '',
	'raw'    => $_POST ?? [],
]);

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

dlog('split', ['increases'=>$increases, 'reductions'=>$reductions]);

// Resolve company once (used for both reductions + increases)
$user       = checkUser();                      // email (your function)
$cRef       = getUsersCompanyId($user);         // company id (your function)
$companyRef = (int)($cRef ?? 0);

// --- Helper: queue reductions to apply at renewal (first of next month UTC) ---
// Inserts a row into company_seat_changes storing the JSON deltas, zero "today ex-VAT",
// and optionally APPLY_AFTER if that column exists (keeps environments in sync).
function queue_reductions(PDO $pdo, int $companyRef, array $reductions): array {
	
	// First of next month, 00:00:00 UTC
	$applyAfter = (new DateTime('first day of next month 00:00:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	
	// Detect presence of APPLY_AFTER column
	$colExists = $pdo->query("SHOW COLUMNS FROM company_seat_changes LIKE 'APPLY_AFTER'")->fetch(PDO::FETCH_ASSOC);
	
	if ($colExists) {
		
		$sql = "
			INSERT INTO company_seat_changes (
				COMPANY_REF, 
				STRIPE_SESSION_ID, 
				CREATED_AT, 
				PROCESSED_AT, 
				APPLIED_AT, 
				DELTAS_JSON, 
				TODAY_EX_VAT_PENCE, 
				APPLY_AFTER
			) VALUES (
				:cref, 
				NULL, 
				NOW(), 
				NULL, 
				NULL, 
				:deltas, 
				0, 
				:apply_after
			)
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

/*
Stripe path for increases
==
- Load Stripe (Composer or manual zip)
- set secret from config. 
*/

// Set the variables to be used later in the script
$loadedStripe     = false;
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
$manualInit       = __DIR__ . '/../vendor/stripe/stripe-php/init.php';

// Load the autoload.php file or return an error if it does not exist
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

// --- Helpers to match Stripe's UTC proration and net in-month decreases ---

/** Month bounds in UTC as DateTimeImmutables */
function __month_bounds_utc(): array {
		$tz = new DateTimeZone('UTC');
		return [
				'start' => new DateTimeImmutable('first day of this month 00:00:00', $tz),
				'end'   => new DateTimeImmutable('last day of this month 23:59:59', $tz),
				'now'   => new DateTimeImmutable('now', $tz),
		];
}

/**
 * Compute available "credit seconds" for a company+access level from **decreases** made earlier this month,
 * net of any **increases** already applied this month (which consume that credit).
 * No new tables required — derived from company_seat_changes.
 */
function __available_credit_seconds_this_month(PDO $pdo, int $companyRef, int $accessRef, int $monthEndTs): int {
		// company_seat_changes must have a UNIQUE index on STRIPE_SESSION_ID for idempotency (recommended)
		// ALTER TABLE company_seat_changes ADD UNIQUE KEY uq_stripe_session (STRIPE_SESSION_ID);

		$stmt = $pdo->prepare("
				SELECT STRIPE_SESSION_ID, DELTAS_JSON, CREATED_AT, APPLIED_AT
					FROM company_seat_changes
				 WHERE COMPANY_REF = :c
					 AND CREATED_AT >= DATE_FORMAT(UTC_DATE(), '%Y-%m-01')
					 AND CREATED_AT <  DATE_FORMAT(DATE_ADD(UTC_DATE(), INTERVAL 1 MONTH), '%Y-%m-01')
		");
		$stmt->execute([':c' => $companyRef]);

		$creditSeconds = 0;

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				// Treat DB timestamps as UTC for consistency with Stripe
				$createdTs = strtotime($row['CREATED_AT'] . ' UTC');
				$secondsRemainingFromEvent = max(0, $monthEndTs - $createdTs);

				$deltas = json_decode((string)$row['DELTAS_JSON'], true);
				if (!is_array($deltas)) continue;

				foreach ($deltas as $d) {
						$ref   = (int)($d['ref']   ?? 0);
						$delta = (int)($d['delta'] ?? 0);
						if ($ref !== $accessRef || $delta === 0) continue;

						if ($delta < 0) {
								// A decrease creates credit: seats * seconds remaining from the time of decrease
								$creditSeconds += (-$delta) * $secondsRemainingFromEvent;
						} elseif ($delta > 0 && !empty($row['APPLIED_AT'])) {
								// An already-applied increase **consumes** credit: seats * seconds remaining from the time of increase
								$creditSeconds -= $delta * $secondsRemainingFromEvent;
						}
				}
		}

		return max(0, $creditSeconds);
}

// Pro-rata activation charge setup (one-off for the remainder of *this* calendar month)
$activationItems = [];

$__mb       = __month_bounds_utc();
$__startUtc = $__mb['start'];
$__endUtc   = $__mb['end'];
$__nowUtc   = $__mb['now'];

$totalSecs     = max(1, $__endUtc->getTimestamp() - $__startUtc->getTimestamp());
$remainingSecs = max(0, $__endUtc->getTimestamp() - $__nowUtc->getTimestamp());
$fraction      = min(1.0, $remainingSecs / $totalSecs); // 0..1

dlog('proration', [
	'start' => $__startUtc->format(DATE_ATOM),
	'end'   => $__endUtc->format(DATE_ATOM),
	'now'   => $__nowUtc->format(DATE_ATOM),
	'totalSecs' => $totalSecs,
	'remainingSecs' => $remainingSecs,
	'fraction' => $fraction
]);

// Query access levels (REF, NAME, MRR) and map increases to monthly recurring line items.
$levels = $pdo->query("SELECT REF, NAME, MRR FROM access_level")->fetchAll(PDO::FETCH_ASSOC);
$byRef  = [];
foreach ($levels as $l) $byRef[(int)$l['REF']] = $l;

// Company’s current seats so we can cancel pending decreases server-side (matches UI)
$seatsStmt = $pdo->prepare("
		SELECT ACCESS_LEVEL_REF, SEATS_COMMITTED, SEATS_PENDING
			FROM company_seats
		 WHERE COMPANY_REF = :c
");
$seatsStmt->execute([':c' => $companyRef]);

$seatsByRef = [];
while ($r = $seatsStmt->fetch(PDO::FETCH_ASSOC)) {
		$seatsByRef[(int)$r['ACCESS_LEVEL_REF']] = [
				'COMMITTED' => (int)$r['SEATS_COMMITTED'],
				'PENDING'   => (int)($r['SEATS_PENDING'] ?? 0), // may be 0 on your stack
		];
}

// 🔁 Also aggregate not-yet-applied scheduled decreases from company_seat_changes
$hasApplyAfterCol = (bool)$pdo->query("SHOW COLUMNS FROM company_seat_changes LIKE 'APPLY_AFTER'")->fetch(PDO::FETCH_ASSOC);
if ($hasApplyAfterCol) {
		$selPend = $pdo->prepare("
				SELECT DELTAS_JSON
					FROM company_seat_changes
				 WHERE COMPANY_REF = :c
					 AND APPLIED_AT IS NULL
					 AND APPLY_AFTER IS NOT NULL
		");
} else {
		// fallback when APPLY_AFTER is absent: any not-yet-applied decrease rows
		$selPend = $pdo->prepare("
				SELECT DELTAS_JSON
					FROM company_seat_changes
				 WHERE COMPANY_REF = :c
					 AND APPLIED_AT IS NULL
		");
}
$selPend->execute([':c' => $companyRef]);

$pendingByRef = [];
while ($row = $selPend->fetch(PDO::FETCH_ASSOC)) {
		$dj = json_decode((string)$row['DELTAS_JSON'], true);
		if (!is_array($dj)) continue;
		foreach ($dj as $d) {
				$ref   = (int)($d['ref']   ?? 0);
				$delta = (int)($d['delta'] ?? 0);
				if ($ref === 0 || $delta >= 0) continue; // only care about decreases (negative)
				$pendingByRef[$ref] = ($pendingByRef[$ref] ?? 0) + $delta; // delta is negative
		}
}

// Merge aggregated pending decreases into snapshot
foreach ($pendingByRef as $ref => $pendDelta) {
		if (!isset($seatsByRef[$ref])) {
				$seatsByRef[$ref] = ['COMMITTED' => 0, 'PENDING' => 0];
		}
		// Add the negative pending deltas; if company_seats already stored pending, this sums them.
		$seatsByRef[$ref]['PENDING'] += (int)$pendDelta;
}

dlog('seats.snapshot.merged', $seatsByRef);

$lineItems = [];
$__netChargeableSeats = 0; // 👈 track chargeable seats across all roles
foreach ($increases as $inc) {
	$lvl = $byRef[(int)$inc['ref']] ?? null;
	if (!$lvl) continue;

	$unitAmount = (int) round(((float)$lvl['MRR']) * 100); // pence
	if ($unitAmount <= 0) continue;
	
	// One-off activation charge for seats not covered by pending decreases (server mirrors UI)
	$delta = (int)$inc['delta']; // seats being added now (guaranteed > 0 for increases)
	
	$committed = (int)($seatsByRef[(int)$inc['ref']]['COMMITTED'] ?? 0);
	$pending   = (int)($seatsByRef[(int)$inc['ref']]['PENDING']   ?? 0);
	// pendingTarget = seats company would have at renewal if we did nothing else this month
	$pendingTarget = $committed + $pending;               // (pending is 0 or negative for decreases)
	$cancellable   = max(0, $committed - $pendingTarget); // seats we can “uncancel”
	$chargeable    = max(0, $delta - min($cancellable, $delta));
	$__netChargeableSeats += $chargeable; // 👈 accumulate
	
	dlog('calc', [
		'ref' => (int)$inc['ref'],
		'delta' => $delta,
		'committed' => $committed,
		'pending' => $pending,
		'cancellable' => $cancellable,
		'chargeable' => $chargeable,
		'unitAmount_pence' => $unitAmount,
		'fraction' => $fraction
	]);
	
	// Stripe-aligned UTC seconds fraction already in $fraction
	$activationAmount = (int) round($unitAmount * $chargeable * $fraction); // pre-multiplied by seats
	
	if ($activationAmount > 0) {
			// Charge as a **single** line where unit_amount == total amount (pre-multiplied), quantity = 1
			// This avoids unit rounding drift when credit partially covers seats.
			$activationItems[] = [
					'price_data' => [
							'currency'     => 'gbp',
							'product_data' => [
									'name' => $lvl['NAME'] . ' – seat activation (pro-rata to ' . $__endUtc->format('j M Y') . ')'
							],
							'unit_amount'  => $activationAmount,
					],
					'quantity' => 1,
			];
	}

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

// Prepend activation (one-time) items so they show above the recurring lines in Checkout
if (!empty($activationItems)) {
		$lineItems = array_merge($activationItems, $lineItems);
}

dlog('activation.items', $activationItems);

// If there is no activation charge (total 0 pence), apply seats immediately without opening Stripe
$activationTotalPence = 0;
foreach ($activationItems as $ai) {
		$activationTotalPence += (int)($ai['price_data']['unit_amount'] ?? 0);
}

dlog('activation.totals', [
	'activationTotalPence' => $activationTotalPence,
	'netChargeableSeats'   => $__netChargeableSeats,
	'lineItems_count'      => count($lineItems)
]);

// Enter no-charge path if there is no activation money to take OR nothing is chargeable
// Treat tiny +/- rounding as zero
if ($activationTotalPence <= 0 || $__netChargeableSeats <= 0) {
	dlog('branch.no_charge', ['activationTotalPence'=>$activationTotalPence, 'netChargeableSeats'=>$__netChargeableSeats]);
	
		try {
				global $pdo;
				$pdo->beginTransaction();

				// 1) Snapshot current company_seats for the affected refs (to know what we can cancel)
				$refs = array_map(fn($x)=> (int)$x['ref'], $increases);
				$inQuery = implode(',', array_fill(0, count($refs), '?'));
				$seatsByRef = [];
				if ($refs) {
						$st = $pdo->prepare("
								SELECT ACCESS_LEVEL_REF, SEATS_COMMITTED, SEATS_PENDING
									FROM company_seats
								 WHERE COMPANY_REF = ? AND ACCESS_LEVEL_REF IN ($inQuery)
								 FOR UPDATE
						");
						$st->execute(array_merge([$companyRef], $refs));
						while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
								$seatsByRef[(int)$r['ACCESS_LEVEL_REF']] = [
										'COMMITTED' => (int)$r['SEATS_COMMITTED'],
										'PENDING'   => (int)$r['SEATS_PENDING'], // <= 0 when a reduction is scheduled
								];
						}
				}

				// 2) Prepare statements (UPSERT ensures we always write)
				$upsertSeats = $pdo->prepare("
					INSERT INTO company_seats
						(COMPANY_REF, ACCESS_LEVEL_REF, SEATS_COMMITTED, SEATS_PENDING, PENDING_EFFECTIVE, CREATED_AT, UPDATED_AT)
					VALUES
						(:c, :a, :add_commit, :cancel_pending, NULL, NOW(), NOW())
					ON DUPLICATE KEY UPDATE
						SEATS_COMMITTED = SEATS_COMMITTED + VALUES(SEATS_COMMITTED),
						SEATS_PENDING   = SEATS_PENDING   + VALUES(SEATS_PENDING),
						UPDATED_AT      = NOW()
				");

				// company_seat_changes rows (adapt to APPLY_AFTER presence)
				$hasApplyAfterCol = (bool)$pdo->query("SHOW COLUMNS FROM company_seat_changes LIKE 'APPLY_AFTER'")->fetch(PDO::FETCH_ASSOC);
				
				if ($hasApplyAfterCol) {
						$insChange = $pdo->prepare("
								INSERT INTO company_seat_changes
										(COMPANY_REF, STRIPE_SESSION_ID, SUBSCRIPTION_ID, DELTAS_JSON, TODAY_EX_VAT_PENCE, CREATED_AT, PROCESSED_AT, APPLIED_AT, APPLY_AFTER)
								VALUES
										(:c, NULL, NULL, :deltas, :pence, NOW(), NOW(), NOW(), NULL)
						");
				} else {
						$insChange = $pdo->prepare("
								INSERT INTO company_seat_changes
										(COMPANY_REF, STRIPE_SESSION_ID, SUBSCRIPTION_ID, DELTAS_JSON, TODAY_EX_VAT_PENCE, CREATED_AT, PROCESSED_AT, APPLIED_AT)
								VALUES
										(:c, NULL, NULL, :deltas, :pence, NOW(), NOW(), NOW())
						");
				}

				// Helper: reduce existing decrease rows (scheduled or pending) by $consume
				if ($hasApplyAfterCol) {
						$selDec = $pdo->prepare("
								SELECT ID, DELTAS_JSON
									FROM company_seat_changes
								 WHERE COMPANY_REF = :c
									 AND APPLY_AFTER IS NOT NULL   -- a scheduled change
									 AND APPLIED_AT IS NULL        -- not yet applied
								 ORDER BY CREATED_AT ASC, ID ASC
						");
				} else {
						// Fallback: select any not-yet-applied decrease rows (no APPLY_AFTER column)
						$selDec = $pdo->prepare("
								SELECT ID, DELTAS_JSON
									FROM company_seat_changes
								 WHERE COMPANY_REF = :c
									 AND APPLIED_AT IS NULL
								 ORDER BY CREATED_AT ASC, ID ASC
						");
				}
				$updDec = $pdo->prepare("UPDATE company_seat_changes SET DELTAS_JSON = :dj WHERE ID = :id");

				// 3) Apply each increase:
				$consumedTotal = 0; // total seats of pending reductions we cancelled (for change-row logging)
				foreach ($increases as $inc) {
						$ref   = (int)$inc['ref'];
						$delta = (int)$inc['delta'];                 // seats being added now
						$comm  = (int)($seatsByRef[$ref]['COMMITTED'] ?? 0);
						$pend  = (int)($seatsByRef[$ref]['PENDING']   ?? 0); // <= 0

						// seats we can "uncancel" from pending decrease
						$cancellable = max(0, -$pend);               // how many reductions are queued
						$cancelNow   = min($cancellable, $delta);    // seats of the increase covered by cancelling a decrease
						$addCommit   = $delta;
						$addPending  = $cancelNow;                   // bring SEATS_PENDING back toward zero

						// upsert company_seats (guaranteed write)
						$upsertSeats->execute([
							':c'              => $companyRef,
							':a'              => $ref,
							':add_commit'     => $addCommit,
							':cancel_pending' => $addPending,
						]);

						// Reduce any existing pending-decrease rows for this ref by $cancelNow
						if ($cancelNow > 0) {
								$selDec->execute([':c' => $companyRef]);
								$left = $cancelNow;
								while ($left > 0 && ($row = $selDec->fetch(PDO::FETCH_ASSOC))) {
										$dj = json_decode((string)$row['DELTAS_JSON'], true);
										if (!is_array($dj)) continue;

										$changed = false;
										foreach ($dj as &$d) {
												$r = (int)($d['ref'] ?? 0);
												$v = (int)($d['delta'] ?? 0);
												if ($r !== $ref || $v >= 0) continue; // only touch decreases for this ref

												$canEat = min($left, -$v); // v is negative
												$d['delta'] = $v + $canEat; // move toward zero
												$left -= $canEat;
												$changed = true;
												if ($left === 0) break;
										}
										unset($d);

										if ($changed) {
												// remove any zero entries, keep others
												$dj = array_values(array_filter($dj, fn($e) => (int)($e['delta'] ?? 0) !== 0));
												$updDec->execute([
														':dj' => json_encode($dj, JSON_UNESCAPED_SLASHES),
														':id' => (int)$row['ID'],
												]);
										}
								}
								$consumedTotal += $cancelNow;
						}
				}

				// 4) Record the "apply-now" row (full increases, price 0 now)
				if (!$insChange->execute([
						':c'      => $companyRef,
						':deltas' => json_encode($increases, JSON_UNESCAPED_SLASHES),
						':pence'  => 0,
				])) {
						$info = $insChange->errorInfo();
						throw new RuntimeException('insChange (apply-now) failed: ' . implode(' | ', array_filter($info)));
				}

				// 5) If we cancelled any scheduled decreases, record a second row for traceability
				if ($consumedTotal > 0) {
						$cancelRows = [];
						foreach ($increases as $inc) {
								// only the portion that matched pending is effectively a "cancelled decrease"
								$ref = (int)$inc['ref'];
								// recompute cancellable quickly from snapshot
								$pend = (int)($seatsByRef[$ref]['PENDING'] ?? 0);
								$cancellable = max(0, -$pend);
								$cancelNow = min($cancellable, (int)$inc['delta']);
								if ($cancelNow > 0) $cancelRows[] = ['ref' => $ref, 'delta' => +$cancelNow];
						}
						if ($cancelRows) {
								if (!$insChange->execute([
										':c'      => $companyRef,
										':deltas' => json_encode($cancelRows, JSON_UNESCAPED_SLASHES),
										':pence'  => 0,
								])) {
										$info = $insChange->errorInfo();
										throw new RuntimeException('insChange (cancel-pending) failed: ' . implode(' | ', array_filter($info)));
								}
						}
				}

				$pdo->commit();
				
				dlog('apply.commit', ['companyRef'=>$companyRef, 'increases'=>$increases]);
				dlog('branch.no_charge.commit', ['increases'=>$increases]);
				
				json_response([
						'status'  => 'ok',
						'kind'    => 'no_charge',
						'message' => 'Seats updated and pending decreases cancelled; no payment due now.'
				], 200);
				exit;

		} catch (Throwable $e) {
				dlog('apply.error', $e->getMessage());
				if ($pdo->inTransaction()) $pdo->rollBack();
				json_response([
						'status'  => 'error',
						'message' => 'Apply-without-charge failed: '.$e->getMessage()
				], 500);
				exit;
		}
}

if (!$lineItems) {
	json_response(['status'=>'ok','message'=>'No valid line items built.'], 200);
	exit;
}

// Safety guard: if server computed "no charge", do not create a session even if we fell through
if ($activationTotalPence <= 0 || $__netChargeableSeats <= 0) {
	dlog('guard.no_charge_fellthrough', [
		'activationTotalPence'=>$activationTotalPence,
		'netChargeableSeats'=>$__netChargeableSeats,
		'lineItems'=>$lineItems,
		'activationItems'=>$activationItems
	]);
	json_response([
		'status'  => 'ok',
		'kind'    => 'no_charge_guard',
		'message' => 'No charge computed; applied without Stripe.',
	], 200);
	exit;
}

// --- Create Checkout Session (subscription mode) ---
// Return URL carries {CHECKOUT_SESSION_ID} so you can reconcile client-side if needed.
$successUrl = cfg('STRIPE_RETURN_URL', 'https://accelulator.com/pages/companySettings.php?paid=1') . '&session_id={CHECKOUT_SESSION_ID}';
$cancelUrl  = cfg('STRIPE_CANCEL_URL', 'https://accelulator.com/pages/companySettings.php?cancel=1');

try {
	dlog('branch.stripe.start', ['lineItemsCount'=>count($lineItems)]);
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
			
			// Recurring begins next cycle; we charge the one-off activation now
			'trial_end' => $__endUtc->getTimestamp(),
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