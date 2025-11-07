<?php
session_start();
require_once('../includes/functions.php');
validateCsrfToken();
header('Content-Type: application/json');

try {
	global $pdo;

	// Ensure PDO throws so the catch runs
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$user = checkUser(); // may be an email or an array; normalise
	$email = is_array($user) ? ($user['email'] ?? $user['EMAIL'] ?? null) : $user;
	if (!$email) {
		http_response_code(400);
		echo json_encode(['error' => 'NO_EMAIL']);
		exit;
	}

	// 1) Look up the user's REF
	$stmt = $pdo->prepare('SELECT REF FROM users WHERE EMAIL = :email LIMIT 1');
	$stmt->execute([':email' => $email]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$row || !isset($row['REF'])) {
		http_response_code(404);
		echo json_encode(['error' => 'USER_NOT_FOUND']);
		exit;
	}
	$userRef = $row['REF'];

	// 2) Update demo flag and set the users' linked company to their company
	$stmt = $pdo->prepare('SELECT LINKED_COMPANY FROM user_details WHERE USER_ID = :userID LIMIT 1');
	$stmt->execute([':userID' => $userRef]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	if(!$row || !isset($row['LINKED_COMPANY'])) {
		http_response_code(404);
		echo json_encode(['error' => 'LINKED_COMPANY_NOT_FOUND']);
		exit;
	}
	$linkedCompany = $row['LINKED_COMPANY'];
	
	$stmt = $pdo->prepare('UPDATE user_details SET COMPANY_ID = :companyID WHERE USER_ID = :userRef');
	$stmt->execute([
		':userRef' => $userRef,
		':companyID'=>$linkedCompany
	]);
	
	setupTables($linkedCompany);
	
	$stmt = $pdo->prepare('UPDATE user_demo SET DEMO = 0 WHERE USERREF = :userRef');
	$stmt->execute([':userRef' => $userRef]);
	
	/* ============================
	   NEW: email site owner/admin
	   ============================ */
	$adminTo  = 'stelios.miltiadou@accelulator.com'; // or contact@...
	$subject  = 'Demo turned off: ' . $email;
	$htmlBody = "
		<h2>Demo Turned Off</h2>
		<p><strong>Email:</strong> {$email}</p>
		<p><strong>Company ID (new):</strong> {$linkedCompany}</p>
		<p><small>Turned off at " . date('Y-m-d H:i:s') . "</small></p>
	";
	// this uses the helper from functions.php
	sendHtmlMail($adminTo, $subject, $htmlBody);
	/* ========== end new block ========== */
	
	echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
	exit;

} catch (Throwable $e) {
	// Log the real error server-side so you can see it in error_log
	error_log('turnDemoOff.php error: ' . $e->getMessage());
	http_response_code(500);
	if (ob_get_length()) { ob_clean(); }
	echo json_encode(['error' => 'SERVER_ERROR']);
	exit;
}