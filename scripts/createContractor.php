<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
	$user = checkUser();
	if ($user === '') {
		http_response_code(401);
		echo json_encode(['status' => 'error', 'message' => 'Not authorised']);
		exit;
	}

	$ref = (int) getUsersCompanyId($user);
	$GLOBALS['ref'] = $ref; // lets crypto.php resolve the right company key

	$cryptoPath = __DIR__ . '/../includes/crypto.php';
	if (is_file($cryptoPath)) {
		require_once $cryptoPath;
	}

	$input = json_decode(file_get_contents('php://input'), true) ?? [];

	$first  = trim((string)($input['firstName'] ?? ''));
	$middle = trim((string)($input['middleName'] ?? ''));
	$surname= trim((string)($input['surname'] ?? ''));
	$dept   = (int)($input['departmentRef'] ?? 0);
	$start  = trim((string)($input['startDate'] ?? ''));
	$rate   = (float)($input['dailyRate'] ?? 0);
	$fte    = (float)($input['fte'] ?? 0);

	if ($first === '' || $start === '' || $rate <= 0 || $fte <= 0) {
		http_response_code(400);
		echo json_encode(['status'=>'error','message'=>'Missing/invalid contractor fields']);
		exit;
	}

	$annualSalary = $rate * 5 * 48 * $fte;

	global $pdo;
	$tResources = "{$ref}_resources";
	$tDetails   = "{$ref}_details";

	// ---------- encryption (match employee creation path) ----------
	if (!function_exists('company_data_key') || !function_exists('enc_field') || !function_exists('name_tag')) {
		throw new Exception("Missing crypto helpers (company_data_key / enc_field / name_tag). Check includes/crypto.php");
	}
	
	$companyRef = (int)$ref;
	$dataKey = company_data_key($pdo, $companyRef);
	if (!$dataKey || strlen($dataKey) !== 32) {
		throw new Exception("Missing/invalid company data key for {$companyRef}");
	}
	
	$fnEnc = enc_field($first,  $dataKey);
	$mnEnc = enc_field($middle, $dataKey);  // OK if middle is ''
	$snEnc = enc_field($surname,$dataKey);
	$tag   = name_tag($first, $middle, $surname, $dataKey); // fits VARBINARY(32)

	// ---------- insert into _resources ----------
	$stmt = $pdo->prepare("
		INSERT INTO $tResources
			(SALUTATION, FIRSTNAME_ENC, MIDDLENAME_ENC, SURNAME_ENC, NAME_TAG, DOB, ROLE, USERKEY, DEPARTMENT, CONTRACT_TYPE)
		VALUES
			(NULL, :fn, :mn, :sn, :tag, '1980-01-01', NULL, NULL, :dept, 3)
	");
	$stmt->execute([
		':fn'   => $fnEnc,
		':mn'   => $mnEnc,
		':sn'   => $snEnc,
		':tag'  => $tag,
		':dept' => $dept
	]);

	$empKey = (int)$pdo->lastInsertId();

	// ---------- insert into _details ----------
	// For contractors, pension should not apply; set 0.000... (override default 0.04)
	$stmt2 = $pdo->prepare("
		INSERT INTO $tDetails
			(EMP_KEY, START_DATE, END_DATE, ANNUAL_SALARY, FTE, PENSION)
		VALUES
			(:emp, :sd, '9999-12-31', :sal, :fte, 0.000000000)
	");
	$stmt2->execute([
		':emp' => $empKey,
		':sd'  => $start,
		':sal' => $annualSalary,
		':fte' => $fte
	]);

	// return a friendly name for UI
	$name = $first;
	if ($middle !== '') $name .= " $middle";
	if ($surname !== '') $name .= " $surname";

	echo json_encode(['status' => 'success', 'resourceRef' => $empKey, 'name' => $name]);
	exit;

} catch (Throwable $e) {
	http_response_code(500);
	error_log('[createContractor] ' . $e->getMessage());
	echo json_encode(['status'=>'error','message'=>'Server error creating contractor']);
	exit;
}