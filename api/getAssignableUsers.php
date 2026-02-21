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

$currentLevel = (int)getCurrentUserAccessLevel();
if (!in_array($currentLevel, [2, 9, 10], true)) {
	echo json_encode(["status" => "error", "message" => "Not authorised"]);
	exit;
}

$companyID = (int)($_SESSION['userCompany'] ?? 0);
if ($companyID <= 0) {
	echo json_encode(["status" => "error", "message" => "Company not found"]);
	exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true) ?: [];

// Optional inputs for later (your page has controls but isn’t sending them yet)
$search = isset($data['search']) ? trim((string)$data['search']) : '';
$roleFilter = isset($data['roleFilter']) ? (string)$data['roleFilter'] : 'all';
$showInactive = !empty($data['showInactive']); // default false

try {
	global $pdo;

	// Build WHERE safely
	$where = [];
	$params = [
		':companyID' => $companyID,
		':companyID_udm' => $companyID
	];

	$where[] = "ud.LINKED_COMPANY = :companyID";
	$where[] = "ua.ACCESS_LEVEL IN (7,8)";

	if (!$showInactive) {
		$where[] = "ua.ACTIVE = 1";
		$where[] = "(ua.PAID_UNTIL IS NULL OR ua.PAID_UNTIL >= NOW())";
	}

	if ($roleFilter === '7') {
		$where[] = "ua.ACCESS_LEVEL = 7";
	} elseif ($roleFilter === '8') {
		$where[] = "ua.ACCESS_LEVEL = 8";
	}

	if ($search !== '') {
		$where[] = "(ud.FIRSTNAME LIKE :search OR ud.SURNAME LIKE :search OR ud.USERNAME LIKE :search)";
		$params[':search'] = '%' . $search . '%';
	}

	$sql = "
		SELECT
			ud.USER_ID AS REF,
			ud.FIRSTNAME,
			ud.SURNAME,
			ud.USERNAME,
			ua.ACCESS_LEVEL,
			ua.ACTIVE,
			ua.PAID_UNTIL,
			(
				SELECT COUNT(*)
				FROM user_departments udm
				WHERE udm.COMPANY_ID = :companyID_udm
					AND udm.USERREF = ud.USER_ID
			) AS departmentCount
		FROM user_details ud
		INNER JOIN user_access ua
			ON ua.USERREF = ud.USER_ID
		WHERE " . implode(" AND ", $where) . "
		ORDER BY ud.SURNAME ASC, ud.FIRSTNAME ASC
	";

	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);

	$users = [];
	while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$level = (int)$r['ACCESS_LEVEL'];
		$users[] = [
			"REF" => (int)$r["REF"],
			"FIRSTNAME" => (string)$r["FIRSTNAME"],
			"SURNAME" => (string)$r["SURNAME"],
			"USERNAME" => (string)$r["USERNAME"],
			"ACCESS_LEVEL" => $level,
			"roleName" => ($level === 7 ? "Department Head" : "Functional Head"),
			"departmentCount" => (int)$r["departmentCount"]
		];
	}

	echo json_encode(["status" => "success", "users" => $users]);
} catch (Throwable $e) {
	echo json_encode(["status" => "error", "message" => "Failed to load users"]);
}