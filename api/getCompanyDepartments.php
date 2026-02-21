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

$table = $companyID . '_departments'; // company-specific table (safe because companyID is int)

try {
	global $pdo;

	$stmt = $pdo->query("SELECT REF, DEPARTMENT FROM `$table` ORDER BY DEPARTMENT ASC");
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$departments = array_map(function ($r) {
		return [
			"REF" => (int)$r["REF"],
			"DEPARTMENT" => (string)$r["DEPARTMENT"]
		];
	}, $rows);

	echo json_encode([
		"status" => "success",
		"departments" => $departments
	]);
} catch (Throwable $e) {
	echo json_encode(["status" => "error", "message" => "Failed to load departments"]);
}