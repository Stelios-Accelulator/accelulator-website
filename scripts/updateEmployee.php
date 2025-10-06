<?php // Script to add an employee into the database
session_start();
require_once('../includes/functions.php');
validateCsrfToken(); // <--- protect this script
header('Content-Type: application/json');

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)

	$ref = getUsersCompanyId($user);
	$table_details = $ref . '_details';
	$table_roles = $ref . '_roles';
	
	// Extract the data from the passover
	$rawData = file_get_contents("php://input");
	$data = json_decode($rawData,true);
	
	if(!$data){
		echo json_encode(["status" => "error", "message" => "Invalid or empty JSON"]);
		exit;
	}
	
	$updateResource = (int)$data['updateResource']; // SET
	$salary = (float)$data['annualSalary']; // SET
	$startDate = $data['startDate']; // SET
	$endDate = $data['endDate'] ?? '9999-12-31'; // SET
	$fte = (float)$data['fte']; // SET
	$department = (int)$data['departmentNumber']; // SET
	$contractType = (int)$data['contractType']; // SET
	
	// Update the employees table for the Department changes
	$stmt = $pdo->prepare("
		UPDATE {$ref}_resources 
		SET 
			`DEPARTMENT` = :department,
			`CONTRACT_TYPE` = :contractType
		WHERE `REF` = :ref
	");
	
	$stmt->execute([
		':ref'			=> $updateResource,
		':department'   => $department,
		':contractType' => $contractType,
	]);
	
	// Update the details of the selected Employee
	$stmt = $pdo->prepare("
		UPDATE {$ref}_details 
		SET 
			`START_DATE` = :startDate,
			`END_DATE` = :endDate,
			`ANNUAL_SALARY` = :annualSalary,
			`FTE` = :fte 
			WHERE `EMP_KEY` = :ref
	");
	
	$stmt->execute([
		':ref'			=>	$updateResource,
		':startDate'	=>	$startDate,
		':endDate'		=>	$endDate,
		':annualSalary'	=>	$salary,
		':fte'			=>	$fte
	]);
	
	// Update the department reference for the employee being updated
	$stmt = $pdo->prepare("
		UPDATE {$ref}_roles 
		SET 
			`DEPARTMENT` = :department 
		WHERE `FILLED_REFERENCE` = :ref
	");
	
	$stmt->execute([
		':ref'			=>	$updateResource,
		':department'	=>	$department
	]);
	
	echo json_encode(["status" => "success", "message" => "Update complete"]);
	
}

?>