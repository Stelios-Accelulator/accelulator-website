<?php
// scripts/getPendingDrops.php
declare(strict_types=1);

// Open session first (same cookie name you use elsewhere)
if (session_status() !== PHP_SESSION_ACTIVE) {
	if (ini_get('session.name') !== 'PHPSESSID') {
		session_name('PHPSESSID');
	}
	session_start();
}

require_once __DIR__ . '/../includes/functions.php';

// --- CSRF (aligns with the rest of your endpoints) ---
function hdr(string $name): string {
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	return $_SERVER[$key] ?? '';
}
$incoming = hdr('X-CSRF-Token');
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (!is_string($incoming) || !is_string($sessionToken) ||
	$incoming === '' || $sessionToken === '' || !hash_equals($sessionToken, $incoming)) {
	json_response(['status' => 'error', 'message' => 'Invalid CSRF token'], 403);
}

// Make sure we have a company
$userEmail  = checkUser();                 // your helper
$companyRef = (int)(getUsersCompanyId($userEmail) ?? 0);
if ($companyRef <= 0) {
	json_response(['status' => 'error', 'message' => 'No company found for current user'], 400);
}

// Pull any *queued* reductions (not applied yet). We show them until APPLIED_AT is set.
// (They are the rows we inserted when starting a downgrade and contain negative deltas.)
$sql = "
	SELECT DELTAS_JSON
	FROM company_seat_changes
	WHERE COMPANY_REF = :cref
	  AND APPLIED_AT IS NULL
	  AND APPLY_AFTER IS NOT NULL
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':cref' => $companyRef]);

$pendingByRef = []; // { ref => dropsCount }
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
	$deltas = json_decode($row['DELTAS_JSON'] ?? '[]', true);
	if (!is_array($deltas)) continue;

	foreach ($deltas as $d) {
		if (!is_array($d)) continue;
		$ref   = isset($d['ref']) ? (int)$d['ref'] : 0;
		$delta = isset($d['delta']) ? (int)$d['delta'] : 0;
		if ($ref <= 0 || $delta >= 0) continue;       // only reductions here
		$pendingByRef[$ref] = ($pendingByRef[$ref] ?? 0) + abs($delta);
	}
}

json_response([
	'status'            => 'success',
	'pendingDropsByRef' => $pendingByRef,             // { 7: 1, 9: 3, ... }
]);