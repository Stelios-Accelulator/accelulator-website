<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $user = checkUser();
  $companyRef = (int) getUsersCompanyId($user);

  // Current committed seats
  $stmt = $pdo->prepare("
	SELECT ACCESS_LEVEL_REF AS ref, SEATS_COMMITTED AS committed
	FROM company_seats
	WHERE COMPANY_REF = :c
  ");
  $stmt->execute([':c' => $companyRef]);
  $committed = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
	$committed[(int)$r['ref']] = (int)$r['committed'];
  }

  // Pending reductions scheduled for next renewal (APPLY_AFTER set, not yet APPLIED)
  $stmt = $pdo->prepare("
	SELECT DELTAS_JSON, APPLY_AFTER
	FROM company_seat_changes
	WHERE COMPANY_REF = :c
	  AND APPLY_AFTER IS NOT NULL
	  AND APPLIED_AT IS NULL
  ");
  $stmt->execute([':c' => $companyRef]);

  $pending = []; // ref => negative int
  $effective = null;
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$effective = $effective ?: $row['APPLY_AFTER'];
	$deltas = json_decode($row['DELTAS_JSON'], true) ?: [];
	foreach ($deltas as $d) {
	  $ref = (int)($d['ref'] ?? 0);
	  $delta = (int)($d['delta'] ?? 0);
	  if ($ref && $delta < 0) $pending[$ref] = ($pending[$ref] ?? 0) + $delta;
	}
  }

  // Build payload rows
  $rows = [];
  foreach ($committed as $ref => $c) {
	$p = (int)($pending[$ref] ?? 0);              // <= 0
	$target = max(0, $c + $p);                    // what UI should show in input
	$rows[] = [
	  'ref'               => $ref,
	  'seats_committed'   => $c,
	  'seats_pending'     => $p,
	  'pending_effective' => $effective,          // e.g. 2025-11-01 00:00:00
	  'target_seats'      => $target,
	];
  }

  echo json_encode(['status'=>'success','rows'=>$rows]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}