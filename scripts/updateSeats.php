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

// --- JSON error/exception handlers + DEBUG logger (placed BEFORE CSRF to capture early exits) ---
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
	@error_log("[updateSeats] PHP error: $str at $file:$line");
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
	@error_log('[updateSeats] EXCEPTION ' . $e->getMessage());
	exit;
});

/** DEBUG: force logs to a file right next to this script */
if (!defined('SEAT_DBG')) define('SEAT_DBG', true);
$__DBG_FILE = __DIR__ . '/updateSeats_debug.log';
@ini_set('log_errors', '1');        // ensure PHP will log errors
@ini_set('error_log', $__DBG_FILE); // route error_log() to our file

/** Dual-writer: write to error_log(), and also append directly to the same file */
function dlog(string $label, $data = null): void {
	if (!SEAT_DBG) return;
	global $__DBG_FILE;
	$payload = $data === null ? '' : ' ' . json_encode($data, JSON_UNESCAPED_SLASHES);
	$line = '[updateSeats] ' . $label . $payload;
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
	// 🔎 LOG why we’re failing CSRF
	dlog('csrf.fail', [
		'incoming' => substr((string)$incoming, 0, 64),
		'session'  => substr((string)$sessionToken, 0, 64),
		'sid'      => session_id(),
		'cookie'   => ($_COOKIE['PHPSESSID'] ?? null),
		'path'     => ini_get('session.cookie_path'),
		'domain'   => ini_get('session.cookie_domain'),
	]);

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

dlog('csrf.ok', ['sid'=>session_id()]);

// --- Secondary CSRF check ---
if (!function_exists('validateCsrfToken') || !validateCsrfToken()) {
	dlog('csrf.validator.fail', ['sid'=>session_id()]);
	json_response(['status' => 'error', 'message' => 'Invalid CSRF token (validator)'], 403);
	exit;
}
dlog('csrf.validator.ok', ['sid'=>session_id()]);

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

	// Does table have APPLY_AFTER?
	$hasApplyAfter = (bool)$pdo->query("SHOW COLUMNS FROM company_seat_changes LIKE 'APPLY_AFTER'")->fetch(PDO::FETCH_ASSOC);

	// Prepared statements we’ll reuse
	$ins = $hasApplyAfter
		? $pdo->prepare("
			INSERT INTO company_seat_changes
				(COMPANY_REF, STRIPE_SESSION_ID, SUBSCRIPTION_ID, DELTAS_JSON, TODAY_EX_VAT_PENCE, CREATED_AT, PROCESSED_AT, APPLIED_AT, APPLY_AFTER)
			VALUES
				(:c, NULL, NULL, :dj, 0, NOW(), NULL, NULL, :aa)
		")
		: $pdo->prepare("
			INSERT INTO company_seat_changes
				(COMPANY_REF, STRIPE_SESSION_ID, SUBSCRIPTION_ID, DELTAS_JSON, TODAY_EX_VAT_PENCE, CREATED_AT, PROCESSED_AT, APPLIED_AT)
			VALUES
				(:c, NULL, NULL, :dj, 0, NOW(), NULL, NULL)
		");

	$selPending = $hasApplyAfter
		? $pdo->prepare("
			SELECT ID, DELTAS_JSON, APPLY_AFTER
			  FROM company_seat_changes
			 WHERE COMPANY_REF = :c
			   AND APPLIED_AT IS NULL
			   AND APPLY_AFTER IS NOT NULL
			 ORDER BY CREATED_AT ASC, ID ASC
		")
		: $pdo->prepare("
			SELECT ID, DELTAS_JSON
			  FROM company_seat_changes
			 WHERE COMPANY_REF = :c
			   AND APPLIED_AT IS NULL
			 ORDER BY CREATED_AT ASC, ID ASC
		");

	$upd = $pdo->prepare("UPDATE company_seat_changes SET DELTAS_JSON = :dj WHERE ID = :id");

	$countQueued = 0;

	foreach ($reductions as $r) {
		$ref   = (int)($r['ref'] ?? 0);
		$delta = (int)($r['delta'] ?? 0); // negative value (e.g. -2)
		if ($ref <= 0 || $delta >= 0) continue; // only process decreases
	
		// 1️⃣ For each reduction, we handle it *individually* — not bundled.
		//    This ensures each ref gets its own row in company_seat_changes.
		$selPending->execute([':c' => $companyRef]);
		$targetId   = null;
		$targetDj   = null;
		$targetIdx  = null;
		
		while ($row = $selPending->fetch(PDO::FETCH_ASSOC)) {
			$dj = json_decode((string)$row['DELTAS_JSON'], true);
			if (!is_array($dj)) continue;
		
			foreach ($dj as $idx => $item) {
				if ((int)($item['ref'] ?? 0) === $ref && (int)($item['delta'] ?? 0) < 0) {
					$targetId  = (int)$row['ID'];
					$targetDj  = $dj;
					$targetIdx = $idx;
					break 2; // found it—stop both loops
				}
			}
		}
		
		if ($targetId !== null) {
			// make that entry more negative by *adding* the (negative) $delta
			$targetDj[$targetIdx]['delta'] += $delta;
		
			// drop zero entries (keeps JSON clean)
			$targetDj = array_values(array_filter($targetDj, fn($e) => (int)($e['delta'] ?? 0) !== 0));
		
			$upd->execute([
				':dj' => json_encode($targetDj, JSON_UNESCAPED_SLASHES),
				':id' => $targetId,
			]);
			$countQueued++;
			continue;
		}
	
		if ($targetId !== null) {
			// Update existing row’s delta (make it more negative)
			$targetDj[0]['delta'] += $delta;
			$upd->execute([
				':dj' => json_encode($targetDj, JSON_UNESCAPED_SLASHES),
				':id' => $targetId,
			]);
			$countQueued++;
			continue;
		}
	
		// 2️⃣ Otherwise, insert a brand-new row for this ref
		$dj = [['ref' => $ref, 'delta' => $delta]];
		if ($hasApplyAfter) {
			$ins->execute([
				':c'  => $companyRef,
				':dj' => json_encode($dj, JSON_UNESCAPED_SLASHES),
				':aa' => $applyAfter,
			]);
		} else {
			$ins->execute([
				':c'  => $companyRef,
				':dj' => json_encode($dj, JSON_UNESCAPED_SLASHES),
			]);
		}
		$countQueued++;
	}

	return [
		'queued'      => $countQueued,
		'apply_after' => $applyAfter,
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
		'status'      => 'ok',
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

// --- MERGE scheduled reductions from company_seat_changes into snapshot ---
$sch = $pdo->prepare("
		SELECT DELTAS_JSON
			FROM company_seat_changes
		 WHERE COMPANY_REF = :c
			 AND APPLY_AFTER IS NOT NULL        -- scheduled for month end
			 AND APPLIED_AT IS NULL             -- not consumed yet
");
$sch->execute([':c' => $companyRef]);

while ($row = $sch->fetch(PDO::FETCH_ASSOC)) {
		$deltas = json_decode((string)$row['DELTAS_JSON'], true);
		if (!is_array($deltas)) continue;
		foreach ($deltas as $d) {
				$ref   = (int)($d['ref']   ?? 0);
				$delta = (int)($d['delta'] ?? 0);  // negative for decreases
				if ($ref && $delta < 0) {
						if (!isset($seatsByRef[$ref])) {
								$seatsByRef[$ref] = ['COMMITTED' => 0, 'PENDING' => 0];
						}
						// bring PENDING down (more negative) by the scheduled drop
						$seatsByRef[$ref]['PENDING'] += $delta; // $delta is negative
				}
		}
}
dlog('seats.snapshot.merged', $seatsByRef);

// --- Normalize increases in case the client sent absolute targets instead of deltas ---
// We treat incoming "delta" as the desired *target* if it looks like an absolute seat count.
// Client sends true deltas; just aggregate multiple entries for the same ref
$agg = [];
foreach ($increases as $i) {
	$r = (int)$i['ref'];
	$d = (int)$i['delta'];
	if ($r > 0 && $d > 0) {
		$agg[$r] = ($agg[$r] ?? 0) + $d;
	}
}
$increases = [];
foreach ($agg as $r => $sumDelta) {
	if ($sumDelta > 0) $increases[] = ['ref' => $r, 'delta' => $sumDelta];
}
dlog('increases.aggregated', $increases);

$lineItems = [];
$__netChargeableSeats = 0; // 👈 track chargeable seats across all roles
$activationByRef = [];
$netAddByRef = []; // NEW: how many live seats to add now (delta minus cancellations)
foreach ($increases as $inc) {
	$lvl = $byRef[(int)$inc['ref']] ?? null;
	if (!$lvl) continue;

	$unitAmount = (int) round(((float)$lvl['MRR']) * 100); // pence
	if ($unitAmount <= 0) continue;
	
	// One-off activation charge for seats not covered by pending decreases (server mirrors UI)
	$delta = (int)$inc['delta']; // seats being added now
	
	$committed = (int)($seatsByRef[(int)$inc['ref']]['COMMITTED'] ?? 0);
	$pending   = (int)($seatsByRef[(int)$inc['ref']]['PENDING']   ?? 0);
	
	/**
	 * Any pending decreases (negative PENDING) are credit.
	 * We "uncancel" up to that amount at no charge and only bill for the net seats.
	 */
	$cancellable = max(0, -$pending);                // seats we can cover by cancelling decreases
	$chargeable  = max(0, $delta - $cancellable);    // bill only for net seats beyond that
	
	$__netChargeableSeats += $chargeable;
	$netAddByRef[(int)$inc['ref']] = $chargeable; // NEW
	
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
	
	// Pro-rata charge is for ALL added seats
	$activationAmount = (int) round($unitAmount * $chargeable * $fraction);
	$activationByRef[(int)$inc['ref']] = (int)(($activationByRef[(int)$inc['ref']] ?? 0) + $activationAmount);
	
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

// Build the minimal "net" payload the webhook will apply (+ve only)
$netSeatChanges = [];
foreach ($netAddByRef as $ref => $net) {
	$ref = (int)$ref;
	$net = (int)$net;
	if ($ref > 0 && $net > 0) {
		$netSeatChanges[] = ['ref' => $ref, 'net' => $net];
	}
}

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

/**
 * Reduce pending decreases in company_seat_changes for the given increases.
 * - Does NOT touch company_seats (we only “uncancel” within the pending JSON rows).
 * - Works whether we later charge via Stripe or not.
 */
function consume_pending_decreases(PDO $pdo, int $companyRef, array $increases, array $snapshotByRef): void
{
	// Detect APPLY_AFTER once
	$hasApplyAfterCol = (bool)$pdo->query("SHOW COLUMNS FROM company_seat_changes LIKE 'APPLY_AFTER'")
								  ->fetch(PDO::FETCH_ASSOC);

	// Select relevant (not-yet-applied) scheduled decrease rows
	$selDec = $hasApplyAfterCol
		? $pdo->prepare("
			SELECT ID, DELTAS_JSON
			  FROM company_seat_changes
			 WHERE COMPANY_REF = :c
			   AND APPLY_AFTER IS NOT NULL
			   AND APPLIED_AT IS NULL
			 ORDER BY CREATED_AT ASC, ID ASC
		")
		: $pdo->prepare("
			SELECT ID, DELTAS_JSON
			  FROM company_seat_changes
			 WHERE COMPANY_REF = :c
			   AND APPLIED_AT IS NULL
			 ORDER BY CREATED_AT ASC, ID ASC
		");

	$updDec = $pdo->prepare("UPDATE company_seat_changes SET DELTAS_JSON = :dj WHERE ID = :id");

	foreach ($increases as $inc) {
		$ref   = (int)$inc['ref'];
		$delta = (int)$inc['delta'];           // seats being added
		$pend  = (int)($snapshotByRef[$ref]['PENDING'] ?? 0); // <= 0 if we have pending decreases
		$cancellable = max(0, -$pend);
		$left = min($cancellable, $delta);
		if ($left <= 0) continue;

		// Walk through pending decrease rows, moving deltas toward zero
		$selDec->execute([':c' => $companyRef]);
		while ($left > 0 && ($row = $selDec->fetch(PDO::FETCH_ASSOC))) {
			$dj = json_decode((string)$row['DELTAS_JSON'], true);
			if (!is_array($dj)) continue;

			$changed = false;
			foreach ($dj as &$d) {
				if ((int)($d['ref'] ?? 0) !== $ref) continue;
				$v = (int)($d['delta'] ?? 0);
				if ($v >= 0) continue; // only decreases

				$eat = min($left, -$v);      // v is negative
				$d['delta'] = $v + $eat;     // move toward zero
				$left      -= $eat;
				$changed    = true;
				if ($left === 0) break;
			}
			unset($d);

			if ($changed) {
				// remove zero entries so rows can naturally disappear later
				$dj = array_values(array_filter($dj, fn($e) => (int)($e['delta'] ?? 0) !== 0));
				$updDec->execute([
					':dj' => json_encode($dj, JSON_UNESCAPED_SLASHES),
					':id' => (int)$row['ID'],
				]);
			}
		}
	}
}

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
						// --- Merge scheduled reductions (from company_seat_changes) into the in-tx snapshot ---
						if (!empty($refs)) {
								// We only need rows for this company with not-yet-applied, scheduled changes
								$selSched = $pdo->prepare("
										SELECT ID, DELTAS_JSON
											FROM company_seat_changes
										 WHERE COMPANY_REF = :c
											 AND APPLIED_AT IS NULL
											 AND APPLY_AFTER IS NOT NULL
										 FOR UPDATE
								");
								$selSched->execute([':c' => $companyRef]);
						
								// Build a quick lookup of the affected refs for performance
								$affected = array_fill_keys($refs, true);
						
								while ($row = $selSched->fetch(PDO::FETCH_ASSOC)) {
										$dj = json_decode((string)$row['DELTAS_JSON'], true);
										if (!is_array($dj)) continue;
						
										foreach ($dj as $d) {
												$r = (int)($d['ref'] ?? 0);
												$v = (int)($d['delta'] ?? 0);
												// Only count scheduled decreases for the refs we are changing now
												if (!isset($affected[$r]) || $v >= 0) continue;
						
												if (!isset($seatsByRef[$r])) {
														$seatsByRef[$r] = ['COMMITTED' => 0, 'PENDING' => 0];
												}
												// Make PENDING more negative by the scheduled decrease
												$seatsByRef[$r]['PENDING'] += $v; // $v is negative
										}
								}
								dlog('no_charge.merged_pending', $seatsByRef);
						}
				}
				
				// company_seat_changes rows (adapt to APPLY_AFTER presence)
				$hasApplyAfterCol = (bool)$pdo->query("SHOW COLUMNS FROM company_seat_changes LIKE 'APPLY_AFTER'")->fetch(PDO::FETCH_ASSOC);
				

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

						// Reduce any existing pending-decrease rows for this ref by $cancelNow
						if ($cancelNow > 0) {
								dlog('no_charge.selDec.scan', ['companyRef'=>$companyRef, 'ref'=>$ref, 'cancelNow'=>$cancelNow]);
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
												dlog('no_charge.selDec.updated', ['rowId'=>(int)$row['ID'], 'newDJ'=>$dj]);
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
				
				consume_pending_decreases($pdo, $companyRef, $increases, $seatsByRef);
				
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

// Even if we’re charging, we must cancel any matched pending decreases now,
// so badges/UI reflect the net after this change.
try {
	$pdo->beginTransaction();
	consume_pending_decreases($pdo, $companyRef, $increases, $seatsByRef);
	$pdo->commit();
} catch (Throwable $e) {
	if ($pdo->inTransaction()) $pdo->rollBack();
	dlog('stripe.consume_pending.error', $e->getMessage());
	// Don’t hard-fail the charge if cancellation bookkeeping hiccups,
	// but we *do* log it so we can correct if needed.
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
			// full deltas (for reference / UI)
			'seat_changes_json'     => json_encode($increases, JSON_UNESCAPED_SLASHES),
			// NET increases only — the webhook will apply these to company_seats
			'seat_changes_net_json' => json_encode($netSeatChanges, JSON_UNESCAPED_SLASHES),
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
	
	// --- write one row per ref for this session (so UI/badges stay correct) ---
	$insPerRef = $pdo->prepare("
		INSERT INTO company_seat_changes
			(COMPANY_REF, STRIPE_SESSION_ID, SUBSCRIPTION_ID, DELTAS_JSON,
			 TODAY_EX_VAT_PENCE, CREATED_AT, PROCESSED_AT, APPLIED_AT, APPLY_AFTER)
		VALUES
			(:c, :sess, NULL, :dj, :pence, NOW(), NULL, NULL, NULL)
	");
	
	foreach ($increases as $inc) {
		$ref   = (int)$inc['ref'];
		$delta = (int)$inc['delta'];                  // > 0
		$net   = (int)($netAddByRef[$ref] ?? $delta); // fallback keeps old behavior if ever missing
		$dj    = json_encode([['ref'=>$ref,'delta'=>$delta,'net'=>$net]], JSON_UNESCAPED_SLASHES); // NEW: include net
		$pence = (int)($activationByRef[$ref] ?? 0);  // may be 0 if fully covered by cancellations
	
		$insPerRef->execute([
			':c'     => $companyRef,
			':sess'  => $session->id,
			':dj'    => $dj,
			':pence' => $pence,
		]);
	}

	// Convenience: also put the Checkout Session ID into metadata (optional)
	\Stripe\Checkout\Session::update($session->id, [
		'metadata' => ['checkout_session_id' => $session->id],
	]);

	json_response(['status' => 'ok', 'url' => $session->url], 200);

} catch (\Throwable $e) {
	json_response(['status' => 'error', 'message' => 'Stripe error: '.$e->getMessage()], 500);
}