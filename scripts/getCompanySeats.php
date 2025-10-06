<?php
declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../includes/functions.php');

// If you're POSTing with an X-CSRF-Token header, leave this on.
// If you want to quickly test via GET in the browser, comment it out temporarily.
validateCsrfToken();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
	$userRef    = checkUser();
	$companyRef = getUsersCompanyId($userRef);

	// Pull the PDO handle the same way your other endpoints do
	global $pdo;

	// TEMP: surface an explicit message instead of a generic 500
	if (!isset($pdo) || !($pdo instanceof PDO)) {
		http_response_code(500);
		echo json_encode(['status' => 'error', 'message' => 'PDO not initialised']); // TEMP
		exit;
	}

	$stmt = $pdo->prepare("
	  SELECT 
		al.REF  AS ACCESS_LEVEL_REF,
		COALESCE(cs.SEATS_COMMITTED, 0) AS SEATS_COMMITTED
	  FROM access_level al
	  LEFT JOIN company_seats cs
			 ON cs.COMPANY_REF = :c
			AND cs.ACCESS_LEVEL_REF = al.REF
	  WHERE al.CODE <> 'SUPERUSER'       -- your rule to hide superusers
	  ORDER BY al.MRR DESC, al.REF ASC   -- pick the order you want
	");
	$stmt->execute([':c' => $companyRef]);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	echo json_encode(['status' => 'success', 'rows' => $rows], JSON_INVALID_UTF8_SUBSTITUTE);
	exit;

} catch (Throwable $e) {
	// TEMP: show the real error during setup
	http_response_code(500);
	echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
	exit;
}