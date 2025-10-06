<?php
session_start();
require_once('../includes/functions.php');
validateCsrfToken();
header('Content-Type: application/json');

$user = checkUser();
if ($user == '') {
	echo json_encode(['status' => 'error', 'message' => 'User not authenticated']);
	exit;
}

$ref = getUsersCompanyId($user);
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);
$departmentSelector = $data['department'] ?? 0;

$table_resources		= $ref . '_resources';
$table_details			= $ref . '_details';
$table_actuals			= $ref . '_actuals';
$table_roles			= $ref . '_roles';
$table_departments		= $ref . '_departments';
$table_forecasts		= $ref . '_forecasts';
$table_outturn			= $ref . '_outturn';
$table_paytype_group	= $ref . '_paytype_group';
$table_paytype			= $ref . '_paytype';

$response = [];

try {
	// 1. RESOURCES
	$stmt = $pdo->prepare("
		SELECT 
			r.REF AS RES_REF,
			r.FIRSTNAME,
			r.SURNAME,
			d.START_DATE,
			d.END_DATE,
			d.ANNUAL_SALARY,
			d.FTE,
			r.DEPARTMENT,
			r.CONTRACT_TYPE
		FROM $table_resources r
		LEFT JOIN $table_details d ON r.REF = d.EMP_KEY
		" . ($departmentSelector ? "WHERE r.DEPARTMENT = :dept" : "")
	);
	if ($departmentSelector) $stmt->execute([':dept' => $departmentSelector]);
	else $stmt->execute();
	$response['resources'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// 2. ACTUALS (with JOIN to get PAYTYPE_GROUP_REF as TYPE)
	$stmt = $pdo->prepare("
		SELECT 
			a.EMP_KEY, 
			a.DATE, 
			g.VALUE AS TYPE, 
			a.VALUE
		FROM $table_actuals a
		LEFT JOIN $table_paytype p ON a.TYPE = p.REF
		LEFT JOIN $table_paytype_group g ON p.PAYTYPE_GROUP_REF = g.REF
		" . ($departmentSelector ? "LEFT JOIN $table_resources r ON a.EMP_KEY = r.REF WHERE r.DEPARTMENT = :dept" : "")
	);
	if ($departmentSelector) {
		$stmt->execute([':dept' => $departmentSelector]);
	} else {
		$stmt->execute();
	}
	$response['actuals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// 3. ROLES
	$stmt = $pdo->prepare("SELECT * FROM $table_roles " . ($departmentSelector ? "WHERE DEPARTMENT = :dept" : ""));
	if ($departmentSelector) $stmt->execute([':dept' => $departmentSelector]);
	else $stmt->execute();
	$response['roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// 4. DEPARTMENTS
	$stmt = $pdo->prepare("SELECT REF, DEPARTMENT FROM $table_departments");
	$stmt->execute();
	$response['departments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// 5. FORECASTS
	$stmt = $pdo->prepare("
		SELECT ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
		FROM $table_forecasts
		GROUP BY ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
	");
	$stmt->execute();
	$response['forecasts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// 6. OUTTURNS
	$stmt = $pdo->prepare("SELECT * FROM $table_outturn");
	$stmt->execute();
	$outturnsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$outturns = [];
	foreach ($outturnsRaw as $row) {
		$typeLookup = $pdo->prepare("SELECT VALUE FROM $table_paytype_group WHERE REF = :type LIMIT 1");
		$typeLookup->execute([':type' => $row['TYPE']]);
		$typeRow = $typeLookup->fetch(PDO::FETCH_ASSOC);

		$outturns[] = [
			'library' => $row['RES_ROL'] === 'resource' ? 'lib_resources' : 'roles',
			'ref' => $row['EMP_KEY'],
			'date' => dateToMMM_YY($row['DATE']),
			'type' => $typeRow['VALUE'] ?? $row['TYPE'],
			'value' => $row['VALUE']
		];
	}
	$response['outturns'] = $outturns;

	// 7. NI BANDS
	$stmt = $pdo->prepare("
		SELECT 
			DATE_FORMAT(FROM_DATE, '%Y-%m-%d') AS FROM_DATE,
			DATE_FORMAT(TO_DATE, '%Y-%m-%d') AS TO_DATE,
			SECONDARY_THRESHOLD_MONTHLY, 
			RATE 
		FROM ni_employers_rates
		ORDER BY FROM_DATE ASC
	");
	$stmt->execute();
	$response['niBands'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// ✅ SUCCESS
	echo json_encode(['status' => 'success'] + $response);

} catch (PDOException $e) {
	http_response_code(500);
	echo json_encode([
		'status' => 'error',
		'message' => 'Database error: ' . $e->getMessage()
	]);
}