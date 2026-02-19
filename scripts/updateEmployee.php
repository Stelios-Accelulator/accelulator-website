<?php
// updateEmployee.php - Update an employee (resource + details + roles) and backfill unknown actual departments

session_start();
require_once('../includes/functions.php');

validateCsrfToken(); // protect this script
header('Content-Type: application/json');

$user = checkUser(); // returns user number if logged in; otherwise redirects/returns blank

if ($user === '' || $user === null) {
	echo json_encode(["status" => "error", "message" => "Not authenticated"]);
	exit;
}

$ref = (int)getUsersCompanyId($user);
if ($ref <= 0) {
	echo json_encode(["status" => "error", "message" => "Invalid company reference"]);
	exit;
}

// Extract JSON payload
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

if (!is_array($data)) {
	echo json_encode(["status" => "error", "message" => "Invalid or empty JSON"]);
	exit;
}

// Pull inputs (with sane defaults)
$updateResource = isset($data['updateResource']) ? (int)$data['updateResource'] : 0;
$salary         = isset($data['annualSalary']) ? (float)$data['annualSalary'] : 0.0;
$startDate      = isset($data['startDate']) ? (string)$data['startDate'] : '';
$endDate        = isset($data['endDate']) && $data['endDate'] ? (string)$data['endDate'] : '9999-12-31';
$fte            = isset($data['fte']) ? (float)$data['fte'] : 0.0;
$department     = isset($data['departmentNumber']) ? (int)$data['departmentNumber'] : 0;
$contractType   = isset($data['contractType']) ? (int)$data['contractType'] : 0;

if ($updateResource <= 0) {
	echo json_encode(["status" => "error", "message" => "Missing or invalid updateResource"]);
	exit;
}

if ($startDate === '') {
	echo json_encode(["status" => "error", "message" => "Missing startDate"]);
	exit;
}

try {
	$pdo->beginTransaction();

	// 1) Update the resource (department + contract type)
	$stmt = $pdo->prepare("
		UPDATE {$ref}_resources
		SET
			`DEPARTMENT` = :department,
			`CONTRACT_TYPE` = :contractType
		WHERE `REF` = :ref
	");
	$stmt->execute([
		':ref'          => $updateResource,
		':department'   => $department,
		':contractType' => $contractType,
	]);

	// 2) Backfill historic actuals where department wasn't known at time of upload
	// Only fill blanks (DEPARTMENT = 0). Never overwrite real snapshots.
	if ($department > 0) {
		$stmt = $pdo->prepare("
			UPDATE {$ref}_actuals
			SET `DEPARTMENT` = :department
			WHERE `EMP_KEY` = :ref
				AND `DEPARTMENT` = 0
		");
		$stmt->execute([
			':ref'        => $updateResource,
			':department' => $department
		]);
	}

	// 3) Update the employee details (dates, salary, fte)
	$stmt = $pdo->prepare("
		UPDATE {$ref}_details
		SET
			`START_DATE` = :startDate,
			`END_DATE` = :endDate,
			`ANNUAL_SALARY` = :annualSalary,
			`FTE` = :fte
		WHERE `EMP_KEY` = :ref
	");
	$stmt->execute([
		':ref'         => $updateResource,
		':startDate'   => $startDate,
		':endDate'     => $endDate,
		':annualSalary'=> $salary,
		':fte'         => $fte
	]);

	// 4) Update the department reference for roles linked to this resource
	$stmt = $pdo->prepare("
		UPDATE {$ref}_roles
		SET `DEPARTMENT` = :department
		WHERE `FILLED_REFERENCE` = :ref
	");
	$stmt->execute([
		':ref'        => $updateResource,
		':department' => $department
	]);

	$pdo->commit();

	echo json_encode(["status" => "success", "message" => "Update complete"]);
	exit;

} catch (Throwable $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	error_log("updateEmployee.php error (company {$ref}, resource {$updateResource}): " . $e->getMessage());

	echo json_encode(["status" => "error", "message" => "Update failed"]);
	exit;
}