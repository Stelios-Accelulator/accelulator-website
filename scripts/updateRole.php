<?php // Script to update a role in the database
session_start();
require_once('../includes/functions.php');
validateCsrfToken(); // <--- protect this script
header('Content-Type: application/json');

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)

	$ref = getUsersCompanyId($user);
	
	// Extract the data from the passover
	$rawData = file_get_contents("php://input");
	$data = json_decode($rawData,true);
	
	if(!$data){
		echo json_encode(["status" => "error", "message" => "Invalid or empty JSON"]);
		exit;
	}
	
	$updateRole = $data['updateRole']; // Set
	$department = $data['departmentNumber']; // Set
	$fte = $data['fte']; // Set
	$annualSalary = $data['annualSalary']; // Set
	$proRataSalary = $annualSalary / $fte;
	$startDate = $data['startDate']; // Set
	$endDate = $data['endDate'] ?? '9999-12-31'; // Set
	$contractType = $data['contractType']; // Set
	
	// Update the role
	$stmt = $pdo->prepare("
		UPDATE {$ref}_roles 
		SET 
			`DEPARTMENT` = :department, 
			`BENCHMARK_FTE` = :benchmarkFTE, 
			`BENCHMARK_SALARY` = :benchmarkSalary, 
			`BENCHMARK_PRORATA_SALARY` = :benchmarkProrataSalary, 
			`START_DATE` = :startDate, 
			`END_DATE` = :endDate, 
			`CONTRACT_TYPE` = :contractType 
		WHERE `REF` = :ref
	");
	
	$stmt->execute([
		':ref'						=>	$updateRole,
		':department'				=>	$department,
		':benchmarkFTE'				=>	$fte,
		':benchmarkSalary'			=>	$annualSalary,
		':benchmarkProrataSalary'	=>	$proRataSalary,
		':startDate'				=>	$startDate,
		':endDate'					=>	$endDate,
		':contractType'				=>	$contractType
	]);
}
?>