<?php
// scripts/updateCompanyName.php
declare(strict_types=1);

header('Content-Type: application/json');

try {
	// Basic early guards
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		http_response_code(405);
		echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
		exit;
	}

	require_once __DIR__ . '/../includes/functions.php';
//	require_once __DIR__ . '/../includes/header.php'; // where $pdo, session, csrf helpers live

	// If you have a CSRF helper, call it here, e.g.:
	// verifyCsrfTokenFromHeader('X-CSRF-Token');

	// Identify user + company
	$user = checkUser();
	if (!$user) {
		http_response_code(401);
		echo json_encode(['status' => 'error', 'message' => 'Not authorised']);
		exit;
	}
	
	$companyID = (int) getUsersCompanyId($user);

	if ($companyID < 0) {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'No company found for user']);
		exit;
	}

	// Read JSON body
	$rawBody  = file_get_contents('php://input');
	$payload  = json_decode($rawBody, true) ?? [];
	$newNameRaw = $payload['companyName'] ?? '';

	// PHP-side cleaning (since scrub() is JS-only)
	$newName = trim((string)$newNameRaw);
	$newName = strip_tags($newName);

	if ($newName === '') {
		echo json_encode([
			'status'  => 'error',
			'message' => 'Company name cannot be empty.'
		]);
		exit;
	}

	global $pdo;

	// Fetch current name
	$stmt = $pdo->prepare(
		"SELECT COMPANY_NAME 
		   FROM companies 
		  WHERE REF = :companyID
		  LIMIT 1"
	);
	$stmt->execute([':companyID' => $companyID]);
	$currentName = (string)($stmt->fetchColumn() ?? '');

	// If unchanged, tell the client
	if ($newName === $currentName) {
		echo json_encode([
			'status'      => 'nochange',
			'companyName' => $currentName
		]);
		exit;
	}

	// Update it
	$stmt = $pdo->prepare(
		"UPDATE companies
			SET COMPANY_NAME = :name,
				LAST_UPDATE  = NOW()
		  WHERE REF = :companyID
		  LIMIT 1"
	);
	$stmt->execute([
		':name'       => $newName,
		':companyID'  => $companyID
	]);

	echo json_encode([
		'status'      => 'success',
		'companyName' => $newName
	]);
	exit;

} catch (Throwable $e) {
	// For debugging during dev you *can* temporarily echo $e->getMessage()
	// but keep it generic in production.
	http_response_code(500);
	echo json_encode([
		'status'  => 'error',
		'message' => 'Server error updating company name.'
		// ,'debug' => $e->getMessage() // TEMP if you need it
	]);
	exit;
}