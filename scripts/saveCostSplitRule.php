<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

function json_fail(string $msg, int $code = 400): void {
	http_response_code($code);
	echo json_encode(['status' => 'error', 'message' => $msg]);
	exit;
}

function json_ok(array $extra = []): void {
	echo json_encode(array_merge(['status' => 'success'], $extra));
	exit;
}

// ---- CSRF (use your existing helper) ----
if (!validateCsrfToken()) {
	json_fail('CSRF check failed', 403);
}

// ---- Auth / company context (your standard approach) ----
$user = checkUser();
$companyRef = (int)getUsersCompanyId($user);
if ($companyRef < 0) json_fail('Invalid company', 401);

$userRef = 0;
if (is_array($user)) {
	$userRef = (int)($user['REF'] ?? 0);
}

// ---- Read JSON ----
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) json_fail('Invalid JSON body');

$scope    = strtoupper(trim((string)($data['scope'] ?? '')));
$scopeRef = (int)($data['scopeRef'] ?? 0);

$opex   = (float)($data['opexPct'] ?? 0);
$capex  = (float)($data['capexPct'] ?? 0);
$except = (float)($data['exceptPct'] ?? 0);

$notes = trim((string)($data['notes'] ?? ''));

// ---- Validate ----
if (!in_array($scope, ['RESOURCE', 'ROLE'], true)) json_fail('Invalid scope');
if ($scopeRef <= 0) json_fail('Invalid scopeRef');

$total = $opex + $capex + $except;
if (abs($total - 100.0) > 0.05) json_fail('Percentages must total 100%');

$table = $companyRef . "_cost_split_rule";

// ---- Determine EFFECTIVE_FROM (Option B: THIS month onward) ----
// Backwards compatible:
// - If client sends 'effectiveFrom' as YYYY-MM-DD, use it
// - Else if client sends effectiveFromYear/Period, use first of that month
// - Else default to first of current month
$effectiveFrom = '';

if (!empty($data['effectiveFrom'])) {
	$effectiveFrom = (string)$data['effectiveFrom']; // expect YYYY-MM-DD
} else {
	$fromYear   = (int)($data['effectiveFromYear'] ?? 0);
	$fromPeriod = (int)($data['effectiveFromPeriod'] ?? 0);

	if ($fromYear > 0 && $fromPeriod >= 1 && $fromPeriod <= 12) {
		$effectiveFrom = sprintf('%04d-%02d-01', $fromYear, $fromPeriod);
	} else {
		$now = new DateTimeImmutable('now');
		$effectiveFrom = $now->format('Y-m-01'); // first day of current month
	}
}

// Validate date format strictly
$dtFrom = DateTimeImmutable::createFromFormat('Y-m-d', $effectiveFrom);
if (!$dtFrom || $dtFrom->format('Y-m-d') !== $effectiveFrom) {
	json_fail('Invalid effectiveFrom date', 400);
}

// Keep legacy YEAR/PERIOD in sync (month-based)
$effectiveFromYear   = (int)$dtFrom->format('Y');
$effectiveFromPeriod = (int)$dtFrom->format('n'); // 1..12

// day before for closing previous open-ended row
$effectiveTo = $dtFrom->modify('-1 day')->format('Y-m-d');
$effectiveToYear   = (int)DateTimeImmutable::createFromFormat('Y-m-d', $effectiveTo)->format('Y');
$effectiveToPeriod = (int)DateTimeImmutable::createFromFormat('Y-m-d', $effectiveTo)->format('n');

try {
	global $pdo;

	if (!isset($pdo) || !($pdo instanceof PDO)) {
		json_fail('DB connection not available ($pdo)', 500);
	}

	$pdo->beginTransaction();

	// 0) If a rule already starts on this EFFECTIVE_FROM date, update it (live-save friendly).
	$sqlFindExisting = "
		SELECT REF
		FROM `$table`
		WHERE SCOPE = :scope
			AND SCOPE_REF = :scopeRef
			AND EFFECTIVE_FROM = :effectiveFrom
		LIMIT 1
	";
	$stmt = $pdo->prepare($sqlFindExisting);
	$stmt->execute([
		':scope' => $scope,
		':scopeRef' => $scopeRef,
		':effectiveFrom' => $effectiveFrom,
	]);
	$existingRef = (int)($stmt->fetchColumn() ?: 0);

	if ($existingRef > 0) {
		$sqlUpdate = "
			UPDATE `$table`
			SET OPEX_PCT = :opex,
					CAPEX_PCT = :capex,
					EXCEPT_PCT = :except,
					NOTES = :notes
			WHERE REF = :ref
			LIMIT 1
		";
		$stmt = $pdo->prepare($sqlUpdate);
		$stmt->execute([
			':opex'   => $opex,
			':capex'  => $capex,
			':except' => $except,
			':notes'  => ($notes !== '' ? $notes : null),
			':ref'    => $existingRef,
		]);

		$pdo->commit();
		json_ok([
			'ruleRef' => $existingRef,
			'mode' => 'updated',
			'effectiveFrom' => $effectiveFrom
		]);
	}

	// 1) Close any open-ended rule that started BEFORE this effectiveFrom date
	$sqlClose = "
		UPDATE `$table`
		SET EFFECTIVE_TO = :effectiveTo,
				EFFECTIVE_TO_YEAR = :toYear,
				EFFECTIVE_TO_PERIOD = :toPeriod
		WHERE SCOPE = :scope
			AND SCOPE_REF = :scopeRef
			AND EFFECTIVE_TO IS NULL
			AND EFFECTIVE_FROM < :effectiveFrom
	";
	$stmt = $pdo->prepare($sqlClose);
	$stmt->execute([
		':effectiveTo'   => $effectiveTo,
		':toYear'        => $effectiveToYear,
		':toPeriod'      => $effectiveToPeriod,
		':scope'         => $scope,
		':scopeRef'      => $scopeRef,
		':effectiveFrom' => $effectiveFrom,
	]);

	// 2) Insert new open-ended rule from this month
	$sqlInsert = "
		INSERT INTO `$table`
			(SCOPE, SCOPE_REF,
			 EFFECTIVE_FROM, EFFECTIVE_TO,
			 EFFECTIVE_FROM_YEAR, EFFECTIVE_FROM_PERIOD,
			 EFFECTIVE_TO_YEAR, EFFECTIVE_TO_PERIOD,
			 OPEX_PCT, CAPEX_PCT, EXCEPT_PCT,
			 NOTES, CREATED_BY)
		VALUES
			(:scope, :scopeRef,
			 :effectiveFrom, NULL,
			 :fromYear, :fromPeriod,
			 NULL, NULL,
			 :opex, :capex, :except,
			 :notes, :createdBy)
	";
	$stmt = $pdo->prepare($sqlInsert);
	$stmt->execute([
		':scope'         => $scope,
		':scopeRef'      => $scopeRef,
		':effectiveFrom' => $effectiveFrom,
		':fromYear'      => $effectiveFromYear,
		':fromPeriod'    => $effectiveFromPeriod,
		':opex'          => $opex,
		':capex'         => $capex,
		':except'        => $except,
		':notes'         => ($notes !== '' ? $notes : null),
		':createdBy'     => $userRef ?: null,
	]);

	$newRuleRef = (int)$pdo->lastInsertId();
	$pdo->commit();

	json_ok([
		'ruleRef' => $newRuleRef,
		'mode' => 'inserted',
		'effectiveFrom' => $effectiveFrom
	]);

} catch (Throwable $e) {
	if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
	json_fail('Save failed: ' . $e->getMessage(), 500);
}