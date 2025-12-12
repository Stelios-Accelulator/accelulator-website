<?php
// fetchDepartments.php
if (session_status() !== PHP_SESSION_ACTIVE) {session_start();}
require_once('../includes/functions.php');
header('Content-Type: application/json');

$user = checkUser();

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$tDepartments = $ref . '_departments';
	
	$stmt = $pdo->prepare(
		"SELECT * FROM $tDepartments"
	);
	$stmt->execute();
	$resRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
	echo json_encode([
		'status'	=>	'success',
		'rows'		=>	$resRows
	]);
	
}
?>