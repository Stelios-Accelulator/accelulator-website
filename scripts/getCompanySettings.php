<?php
declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../includes/functions.php');
validateCsrfToken();

// Needs further work as want to extracct all of the relevant settings

header('Content-Type: application/json; charset=utf-8');

// Never show notices/warnings in API responses
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// ini_set('error_log', '/path/to/php-error.log'); // set a real path

try {
	$userRef = checkUser(); // should return a scalar (int/string) ! Actually returns the user email
	$companyRef = getUsersCompanyId($userRef);
	
	$stmt = $pdo->prepare("
		SELECT 
			MONTHNO as VALUE,
			MONTH as MONTH, 
			YEAR as YEAR
		FROM companyYearEnd
		WHERE COMPANY = :companyRef 
		LIMIT 1
	");
	$stmt->execute([':companyRef' => $companyRef]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	// Clear any accidental prior output (BOM/whitespace)
	if (ob_get_length()) { ob_clean(); }

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'status'=>	'success',
		'value'	=>	$row['VALUE'],
		'month'	=>	$row['MONTH'],
		'year'	=>	$row['YEAR'],
	]);
	
	exit;
} catch (Throwable $e) {
	// Return a JSON error payload (still valid JSON)
	http_response_code(500);
	if (ob_get_length()) { ob_clean(); }
	echo json_encode(['error' => 'SERVER_ERROR']);
	exit;
}