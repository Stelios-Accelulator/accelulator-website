<?php
// /scripts/attachRole.php
session_start();
require_once(__DIR__ . '/../includes/functions.php');
header('Content-Type: application/json; charset=utf-8');

try { // Try to Attach the role to the resource
	
	validateCsrfToken(); // Ensures that the command has been issued by the site

	$user = checkUser(); // should return user id when logged in, '' when not

	// If NOT logged in => reject
	if ($user === '' || $user === null) {
		http_response_code(401);
		echo json_encode(['status' => 'error', 'message' => 'User not authenticated']);
		exit;
	}

	$ref = getUsersCompanyId($user);
	if (!$ref) {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Missing company reference']);
		exit;
	}

	$raw = file_get_contents('php://input');
	$data = json_decode($raw, true);
	if (!is_array($data)) {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
		exit;
	}

	// Cast to ints to avoid SQL injection via numeric fields
	$resourceRef = isset($data['resourceRef']) ? (int)$data['resourceRef'] : 0;
	$roleRef     = isset($data['roleRef']) ? (int)$data['roleRef'] : 0;

	if ($resourceRef <= 0 || $roleRef <= 0) {
		http_response_code(422);
		echo json_encode(['status' => 'error', 'message' => 'resourceRef and roleRef must be positive integers']);
		exit;
	}

	// Build table names (assumes $ref is safe/alphanumeric from your codebase)
	$table_resources = $ref . '_resources';
	$table_roles     = $ref . '_roles';

	// Update resource -> role
	queryMysql("UPDATE `$table_resources` SET `ROLE` = {$roleRef} WHERE `$table_resources`.`REF` = {$resourceRef}");

	// Update role -> filled reference
	queryMysql("UPDATE `$table_roles` SET `FILLED_REFERENCE` = {$resourceRef} WHERE `$table_roles`.`REF` = {$roleRef}");

	// All good
	echo json_encode(['status' => 'success']);
	exit;

} catch (Throwable $e) {
	// Catch *everything* and return JSON; also helpful while you’re still wiring it up
	http_response_code(500);
	echo json_encode([
		'status'  => 'error',
		'message' => 'Server exception',
		// Tip: log full error server-side instead of exposing it; show detail here only when debugging
		'detail'  => $e->getMessage()
	]);
	exit;
}