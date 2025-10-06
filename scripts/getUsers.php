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
		users.REF AS REF,
		users.EMAIL AS EMAIL, 
		user_details.FIRSTNAME AS FIRSTNAME, 
		user_details.SURNAME AS SURNAME, 
		user_access.ACCESS_LEVEL AS ACCESS_LEVEL,
		user_access.ACTIVE AS ACTIVE,
		user_access.PAID_UNTIL AS PAID_UNTIL           -- <-- add this line
	  FROM users
	  LEFT JOIN user_details ON users.REF = user_details.USER_ID
	  LEFT JOIN user_access ON users.REF = user_access.USERREF
	  WHERE user_details.LINKED_COMPANY = :companyRef
	  ORDER BY users.REF, user_access.ACCESS_LEVEL DESC
	");
	
	$stmt->execute([':companyRef' => $companyRef]);
	
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
	if (ob_get_length()) { ob_clean(); }
	header('Content-Type: application/json; charset=utf-8');
	
	echo json_encode(
		[
			'status'	=>	'success',
			'users'		=>	$rows
		], 
		JSON_INVALID_UTF8_SUBSTITUTE
	);
	exit;
	
} catch (Throwable $e) {
	// Return a JSON error payload (still valid JSON)
	http_response_code(500);
	if (ob_get_length()) { ob_clean(); }
	echo json_encode(['error' => 'SERVER_ERROR']);
	exit;
}