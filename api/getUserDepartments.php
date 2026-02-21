<?php
session_start();
require_once('../includes/functions.php');

validateCsrfToken();
header('Content-Type: application/json');

$userEmail = checkUser();
if ($userEmail === '') {
	echo json_encode(["status" => "error", "message" => "Not signed in"]);
	exit;
}

$currentLevel = getCurrentUserAccessLevel();
if (!in_array($currentLevel, [2, 9, 10], true)) {
	echo json_encode(["status" => "error", "message" => "Not authorised"]);
	exit;
}

$companyID = (int) getUsersCompanyId($userEmail);
if ($companyID <= 0) {
	echo json_encode(["status" => "error", "message" => "Company not found"]);
	exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$userRef = isset($data['userRef']) ? (int)$data['userRef'] : 0;
if ($userRef <= 0) {
	echo json_encode(["status" => "error", "message" => "Invalid userRef"]);
	exit;
}

try {
	global $pdo;

	// Same-company check
	$chk = $pdo->prepare("SELECT COMPANY_ID FROM user_details WHERE USER_ID = :userRef LIMIT 1");
	$chk->execute([':userRef' => $userRef]);
	$targetCompany = (int)($chk->fetchColumn() ?: 0);

	if ($targetCompany !== $companyID) {
		echo json_encode(["status" => "error", "message" => "Invalid user"]);
		exit;
	}

	$stmt = $pdo->prepare("
		SELECT DEPT_REF
		FROM user_departments
		WHERE COMPANY_ID = :companyID
			AND USERREF = :userRef
		ORDER BY DEPT_REF ASC
	");
	$stmt->execute([
		':companyID' => $companyID,
		':userRef' => $userRef
	]);

	$deptRefs = [];
	while ($v = $stmt->fetchColumn()) {
		$deptRefs[] = (int)$v;
	}

	echo json_encode(["status" => "success", "departments" => $deptRefs]);
} catch (Throwable $e) {
	echo json_encode(["status" => "error", "message" => "Failed to load user departments"]);
}