<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
	$user = checkUser();
	if ($user === '') {
		http_response_code(401);
		echo json_encode(['status'=>'error','message'=>'Not authorised']);
		exit;
	}

	$ref = (int) getUsersCompanyId($user);

	$input = json_decode(file_get_contents('php://input'), true) ?? [];
	$empKey = (int)($input['resourceRef'] ?? -1);
	$paymentDate = trim((string)($input['paymentDate'] ?? ''));
	$paymentValue = (float)($input['paymentValue'] ?? 0);

	if ($empKey < 0 || $paymentDate === '' || $paymentValue <= 0) {
		http_response_code(400);
		echo json_encode(['status'=>'error','message'=>'Invalid payload']);
		exit;
	}

	global $pdo;

	$tResources = "{$ref}_resources";
	$tActuals   = "{$ref}_actuals";
	$tSplitRule = "{$ref}_cost_split_rule";
	$tSplitUsed = "{$ref}_cost_split_used";

	// Ensure this EMP_KEY is a contractor
	$chk = $pdo->prepare("SELECT CONTRACT_TYPE FROM $tResources WHERE REF = :r LIMIT 1");
	$chk->execute([':r' => $empKey]);
	$ctype = (int)($chk->fetchColumn() ?? -1);
	if ($ctype !== 3) {
		http_response_code(400);
		echo json_encode(['status'=>'error','message'=>'Selected resource is not a contractor']);
		exit;
	}

	// _actuals.DATE is datetime; your UI provides yyyy-mm-dd
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
		http_response_code(400);
		echo json_encode(['status'=>'error','message'=>'Invalid paymentDate format']);
		exit;
	}
	
	$dt = $paymentDate . ' 00:00:00';
	
	// Anchor month (calendar) from payment date
	$monthStart = substr($paymentDate, 0, 7) . '-01';
	
	// Derive YEAR/PERIOD consistently for this company's month bucket
	[$year, $period] = deriveYearPeriodForMonth($pdo, $tActuals, $monthStart);
	
	if ($year < 2000 || $period < 1 || $period > 12) {
		http_response_code(400);
		echo json_encode(['status'=>'error','message'=>'Invalid derived YEAR/PERIOD']);
		exit;
	}

	// IMPORTANT: decide what TYPE should be for contractor payments
	// Use a constant for now so you can change in one place later.
	// If you already have a pay element ref for "Contractor", set it here.
	$CONTRACTOR_TYPE_ID = 1;
	
	$pdo->beginTransaction();
	
	$stmt = $pdo->prepare("
		INSERT INTO $tActuals (DATE, PERIOD, YEAR, EMP_KEY, TYPE, VALUE)
		VALUES (:dt, :period, :year, :emp, :type, :val)
	");
	$stmt->execute([
		':dt'     => $dt,
		':period' => $period,
		':year'   => $year,
		':emp'    => $empKey,
		':type'   => $CONTRACTOR_TYPE_ID,
		':val'    => $paymentValue
	]);
	
	upsertCostSplitUsedMonth(
		$pdo,
		$tSplitRule,
		$tSplitUsed,
		'RESOURCE',
		(int)$empKey,
		(int)$year,
		(int)$period,
		(string)$monthStart
	);
	
	$pdo->commit();
	
	echo json_encode(['status'=>'success']);
	exit;

} catch (Throwable $e) {
	if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
	http_response_code(500);
	error_log('[insertContractorPayment] ' . $e->getMessage());
	echo json_encode(['status'=>'error','message'=>'Server error saving contractor payment']);
	exit;
}