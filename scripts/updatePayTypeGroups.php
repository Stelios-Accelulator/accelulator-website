<?php // Script to update the role reference in the databases
session_start();
require_once('../includes/functions.php');
validateCsrfToken(); // <--- protect this script
header('Content-Type: application/json');

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	$rawData = file_get_contents("php://input");
	$data = json_decode($rawData, true);
	
	$typeRef = $data['typeRef'] ?? [];
	$typeGroupRef = $data['typeGroupRef'] ?? [];
	
	$ref = getUsersCompanyId($user);
	$payTypeGroup = 0;
	$id = 0;
	
	$table_paytype = $ref . '_paytype';
	
	// Update the employees table for the role changes
	$stmt = $pdo->prepare("UPDATE {$ref}_paytype SET PAYTYPE_GROUP_REF = :groupRef WHERE REF = :ref");
	$stmt->execute([
		':groupRef' => $typeGroupRef,
		':ref' => $typeRef
	]);
	
	echo "<script>alert('Pay Type Updated');</script>";
}

?>