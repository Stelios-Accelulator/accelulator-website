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

	$raw = file_get_contents('php://input');
	$data = json_decode($raw, true);
	if (!is_array($data)) {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
		exit;
	}

	// Cast to ints to avoid SQL injection via numeric fields
	$userReference = isset($data['reference']) ? (int)$data['reference'] : 0;

	if ($userReference <= 0) {
		http_response_code(422);
		echo json_encode(['status' => 'error', 'message' => 'resourceRef and roleRef must be positive integers']);
		exit;
	}
	
	$stmt = $pdo->prepare("
		SELECT 
			users.REF AS usersRef, 
			users.MAIN AS usersMain, 
			user_access.REF AS userAccessRef,
			user_demo.REF AS userDemoRef, 
			user_details.REF AS userDetailsRef,
			user_details.LINKED_COMPANY,
			user_details.COMPANY_ID 
		FROM 
			users
		LEFT JOIN 
			user_access ON users.REF = user_access.USERREF
		LEFT JOIN 
			user_demo ON users.REF = user_demo.USERREF
		LEFT JOIN 
			user_details ON users.REF = user_details.USER_ID 
		WHERE 
			REF = :ref 
		LIMIT 1
	");
	
	$stmt->execute([':ref' => $userReference]);
	
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	
	if (!$row) {
			http_response_code(404);
			echo json_encode(['status' => 'error', 'message' => 'User not found']);
			exit;
	}
	
	$usersKey       = (int)$row['usersRef'];
	$userAccessKey  = (int)$row['userAccessRef'];
	$userDemoKey    = (int)$row['userDemoRef'];
	$userDetailsKey = (int)$row['userDetailsRef'];
	$main           = (int)$row['usersMain'];
	
	// delete the row from users
	$stmt = $pdo->prepare("
		DELETE FROM users WHERE REF = :ref
	");
	
	$stmt->execute([
		':ref'	=>	$usersKey,
	]);
	
	// delete the row from user_access
	$stmt = $pdo->prepare("
		DELETE FROM user_access WHERE REF = :ref
	");
	
	$stmt->execute([
		':ref'	=>	$userAccessKey,
	]);
	
	// delete the row from user_demo
	$stmt = $pdo->prepare("
		DELETE FROM user_demo WHERE REF = :ref
	");
	
	$stmt->execute([
		':ref'	=>	$userDemoKey,
	]);
	
	// delete the row from user_details
	$stmt = $pdo->prepare("
		DELETE FROM user_details WHERE REF = :ref
	");
	
	$stmt->execute([
		':ref'	=>	$userDetailsKey,
	]);
	
	if ($main == 1) { // if this is the main user of the company: identify and delete the relevant company tables
		
		// list all company tables for that main user
		$companyTables = [
				"{$userReference}_actuals",
				"{$userReference}_categorisation",
				"{$userReference}_contract_type",
				"{$userReference}_departments",
				"{$userReference}_details",
				"{$userReference}_employees",
				"{$userReference}_forecasts",
				"{$userReference}_outturn",
				"{$userReference}_payroll_library",
				"{$userReference}_paytype",
				"{$userReference}_paytype_group",
				"{$userReference}_pay_rises",
				"{$userReference}_resources",
				"{$userReference}_roles",
				"{$userReference}_settings",
		];
		
		foreach ($companyTables as $tbl) {
				// backtick the table name to avoid weird characters (we control name anyway)
				$sql = "DROP TABLE IF EXISTS `{$tbl}`";
				$pdo->exec($sql);
		}
		
		// 📌 TO BE DONE AT A LATER POINT (NOT NOW)
		// Now delete all non-main users that are attached to this company
		// Now delete the company
		// Now send an email to each user that has had their log-in details deleted due to the company being removed
		
	}
	
	// 📌 TO BE DONE AT A LATER POINT (NOT NOW)
	// Now send an email to the user that has had their log-in removed
	
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