<?php
session_start();
require_once('../includes/functions.php');
validateCsrfToken();
header('Content-Type: application/json');

$user = checkUser();
$currentUserRef = getCurrentUserRef($user);
$currentLevel = $_SESSION['userAccess'];

if (!in_array($currentLevel, [2, 9, 10])) {
		echo json_encode([
				"status" => "error",
				"message" => "Not authorised"
		]);
		exit;
}

if ($user != '') {

		$rawData = file_get_contents("php://input");
		$data = json_decode($rawData, true);

		if (!$data) {
				echo json_encode(["status" => "error", "message" => "Invalid or empty JSON"]);
				exit;
		}

		$userRef = (int)$data['userRef'];
		$newAccessLevel = (int)$data['newAccessLevel'];
		
		// Prevent modifying your own access level
		if ($userRef == $currentUserRef) {
				echo json_encode([
						"status" => "error",
						"message" => "Cannot modify your own access level"
				]);
				exit;
		}
		
		// OPTIONAL BUT RECOMMENDED: get company ID for safety
		$companyID = (int)getUsersCompanyId($user);
		
		try {
		
				$pdo->beginTransaction();
				
				$targetCompanyID = (int)getUsersCompanyId($userRef);
				
				if ($targetCompanyID !== $companyID) {
						$pdo->rollBack();
						echo json_encode([
								"status" => "error",
								"message" => "Invalid user"
						]);
						exit;
				}
				
				// 1️⃣ Update access level
				$stmt = $pdo->prepare("
						UPDATE user_access
						SET ACCESS_LEVEL = :newAccessLevel
						WHERE USERREF = :userRef
				");

				$stmt->execute([
						':userRef' => $userRef,
						':newAccessLevel' => $newAccessLevel,
				]);

				// 2️⃣ Cleanup if moving away from Dept/Functional Head
				if (!in_array($newAccessLevel, [7, 8])) {

						$deleteStmt = $pdo->prepare("
								DELETE FROM user_departments
								WHERE COMPANY_ID = :companyID
								AND USERREF = :userRef
						");

						$deleteStmt->execute([
								':companyID' => $companyID,
								':userRef'   => $userRef
						]);
				}

				$pdo->commit();

				echo json_encode([
						"status" => "success",
						"message" => "Update complete",
						"rows_affected" => $stmt->rowCount()
				]);

		} catch (Exception $e) {

				$pdo->rollBack();

				echo json_encode([
						"status" => "error",
						"message" => "Update failed"
				]);
		}
}
?>