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

$companyID = (int)($_SESSION['userCompany'] ?? 0);
if ($companyID <= 0) {
	echo json_encode(["status" => "error", "message" => "Company not found"]);
	exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$userRef = isset($data['userRef']) ? (int)$data['userRef'] : 0;
$deptRefs = isset($data['departments']) && is_array($data['departments']) ? $data['departments'] : [];

if ($userRef <= 0) {
	echo json_encode(["status" => "error", "message" => "Invalid userRef"]);
	exit;
}

// Normalise department refs: ints, unique, no zeros
$deptRefs = array_values(array_unique(array_filter(array_map('intval', $deptRefs), fn($v) => $v > 0)));

try {
	global $pdo;

	// Same-company check for target
	$chk = $pdo->prepare("SELECT LINKED_COMPANY FROM user_details WHERE USER_ID = :userRef LIMIT 1");
	$chk->execute([':userRef' => $userRef]);
	$targetCompany = (int)($chk->fetchColumn() ?: 0);

	if ($targetCompany !== $companyID) {
		echo json_encode(["status" => "error", "message" => "Invalid user"]);
		exit;
	}

	// Get target access level (must be 7 or 8)
	$al = $pdo->prepare("
		SELECT ACCESS_LEVEL
		FROM user_access
		WHERE USERREF = :userRef
		ORDER BY ACTIVE DESC, ACCESS_LEVEL DESC
		LIMIT 1
	");
	$al->execute([':userRef' => $userRef]);
	$targetLevel = (int)($al->fetchColumn() ?: 0);

	if (!in_array($targetLevel, [7, 8], true)) {
		echo json_encode(["status" => "error", "message" => "Target user is not a Department Head or Functional Head"]);
		exit;
	}

	// Level 7: allow empty (clear) OR exactly 1
	if ($targetLevel === 7 && count($deptRefs) > 1) {
		echo json_encode(["status" => "error", "message" => "Department Heads can only be assigned to one department"]);
		exit;
	}

	// Validate dept refs exist in company departments table
	$deptTable = $companyID . '_departments';

	if (count($deptRefs) > 0) {
		$placeholders = implode(',', array_fill(0, count($deptRefs), '?'));
		$val = $pdo->prepare("SELECT REF FROM `$deptTable` WHERE REF IN ($placeholders)");
		$val->execute($deptRefs);
		$found = $val->fetchAll(PDO::FETCH_COLUMN);

		$foundInts = array_map('intval', $found);
		sort($foundInts);

		$want = $deptRefs;
		sort($want);

		if ($foundInts !== $want) {
			echo json_encode(["status" => "error", "message" => "One or more departments are invalid"]);
			exit;
		}
	}

	$pdo->beginTransaction();

	// Clear existing
	$del = $pdo->prepare("
		DELETE FROM user_departments
		WHERE COMPANY_ID = :companyID
			AND USERREF = :userRef
	");
	$del->execute([
		':companyID' => $companyID,
		':userRef' => $userRef
	]);

	// Insert new
	if (count($deptRefs) > 0) {
		$ins = $pdo->prepare("
			INSERT INTO user_departments (COMPANY_ID, USERREF, DEPT_REF)
			VALUES (:companyID, :userRef, :deptRef)
		");

		foreach ($deptRefs as $d) {
			$ins->execute([
				':companyID' => $companyID,
				':userRef' => $userRef,
				':deptRef' => (int)$d
			]);
		}
	}

	$pdo->commit();

	echo json_encode(["status" => "success"]);
} catch (Throwable $e) {
	if (isset($pdo) && $pdo->inTransaction()) {
		$pdo->rollBack();
	}
	echo json_encode(["status" => "error", "message" => "Failed to save departments"]);
}