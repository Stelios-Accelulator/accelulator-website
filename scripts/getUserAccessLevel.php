<?php
declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../includes/functions.php');
validateCsrfToken();

header('Content-Type: application/json; charset=utf-8');

// Never show notices/warnings in API responses
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// ini_set('error_log', '/path/to/php-error.log'); // set a real path

try {
	$userRef = checkUser(); // should return a scalar (int/string) ! Actually returns the user email
	
	$stmt = $pdo->prepare("
		SELECT user_access.ACCESS_LEVEL as ACCESS_LEVEL
		FROM user_access
		LEFT JOIN users ON user_access.USERREF = users.REF
		WHERE users.EMAIL = :userRef
		LIMIT 1
	");
	$stmt->execute(['userRef' => $userRef]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	// Clear any accidental prior output (BOM/whitespace)
	if (ob_get_length()) { ob_clean(); }

	echo json_encode($row ? (int)$row['ACCESS_LEVEL'] : null, JSON_UNESCAPED_UNICODE);
	exit;
} catch (Throwable $e) {
	// Return a JSON error payload (still valid JSON)
	http_response_code(500);
	if (ob_get_length()) { ob_clean(); }
	echo json_encode(['error' => 'SERVER_ERROR']);
	exit;
}