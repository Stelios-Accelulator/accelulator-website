<?php
// getCompanySeats.php (snippet)
require_once __DIR__ . '/../includes/functions.php';

$userEmail  = checkUser();
$companyRef = getUsersCompanyId($userEmail);

if (!$companyRef) {
	json_response(['status' => 'error', 'message' => 'No company found'], 400);
}

// Get the current committed seats
$sql = "SELECT ACCESS_LEVEL_REF, SEATS_COMMITTED FROM company_seats WHERE COMPANY_REF = :cref";
$stmt = $pdo->prepare($sql);
$stmt->execute([':cref' => $companyRef]);
$current = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [ref => seats]

// Get any pending seat deltas from queued changes (APPLY_AFTER in future)
$sql2 = "
	SELECT DELTAS_JSON
	FROM company_seat_changes
	WHERE COMPANY_REF = :cref
	  AND APPLIED_AT IS NULL
	  AND APPLY_AFTER IS NOT NULL
";
$stmt2 = $pdo->prepare($sql2);
$stmt2->execute([':cref' => $companyRef]);

$pendingDeltas = [];
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
	$deltas = json_decode($row['DELTAS_JSON'] ?? '[]', true);
	if (!is_array($deltas)) continue;
	foreach ($deltas as $d) {
		$ref = (int)($d['ref'] ?? 0);
		$delta = (int)($d['delta'] ?? 0);
		if ($ref <= 0 || $delta === 0) continue;
		$pendingDeltas[$ref] = ($pendingDeltas[$ref] ?? 0) + $delta;
	}
}

// Merge to show both current seats and projected (next period)
$result = [];
foreach ($current as $ref => $seats) {
	$ref = (int)$ref;
	$pending = (int)($pendingDeltas[$ref] ?? 0);
	$projected = $seats + $pending;
	$result[] = [
		'ACCESS_LEVEL_REF' => $ref,
		'SEATS_COMMITTED'  => $seats,
		'PENDING_DELTA'    => $pending,
		'PROJECTED_SEATS'  => $projected,
	];
}

json_response(['status' => 'success', 'rows' => $result]);