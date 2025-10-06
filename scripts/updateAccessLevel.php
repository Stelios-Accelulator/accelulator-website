<?php // Script to take the year end from the user and save it into the database
session_start();
require_once('../includes/functions.php');
validateCsrfToken(); // <--- protect this script
header('Content-Type: application/json');

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	// Extract the data from the passover
	$rawData = file_get_contents("php://input");
	$data = json_decode($rawData,true);
	
	if(!$data){
		echo json_encode(["status" => "error", "message" => "Invalid or empty JSON"]);
		exit;
	}
	
	$userRef = (int)$data['userRef']; // SET
	$newAccessLevel = (int)$data['newAccessLevel']; // SET
	
	// Update the companyYearEnd table for the changes
	$stmt = $pdo->prepare("
		UPDATE user_access 
		SET 
			`ACCESS_LEVEL`	=	:newAccessLevel
		WHERE 
			`USERREF`		=	:userRef
	");
	
	$stmt->execute([
		':userRef'		=>	$userRef,
		':newAccessLevel'	=>	$newAccessLevel,
	]);
	
	echo json_encode([
		"status" => "success", 
		"message" => "Update complete",
		"rows_affected" => $stmt->rowCount()
	]);
	
}

?>