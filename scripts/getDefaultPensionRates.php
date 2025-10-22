<?php
declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../includes/functions.php');
validateCsrfToken();

header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$empKey = $input['resourceReference'];

try {
	$userRef = checkUser(); // should return a scalar (int/string) ! Actually returns the user email
	$companyRef = getUsersCompanyId($userRef);
	
	$stmt = $pdo->prepare("
		SELECT 
			PENSION
		FROM $companyRef."_details"
		WHERE EMP_KEY = :empKey 
		LIMIT 1
	");
	$stmt->execute([':empKey' => $empKey]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	// Clear any accidental prior output (BOM/whitespace)
	if (ob_get_length()) { ob_clean(); }

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'status'      => 'success',
		'pensionRate' => isset($row['PENSION']) ? (float)$row['PENSION'] : 0.0
	]);
	
	exit;
} catch (Throwable $e) {
	// Return a JSON error payload (still valid JSON)
	http_response_code(500);
	if (ob_get_length()) { ob_clean(); }
	echo json_encode(['error' => 'SERVER_ERROR']);
	exit;
}