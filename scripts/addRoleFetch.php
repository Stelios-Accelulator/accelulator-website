<?php // Script to add a role into the database
// scripts/addRoleFetch.php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';

if (function_exists('ob_get_level') && ob_get_level() === 0) { ob_start(); }

$input 			=	json_decode(file_get_contents('php://input'), true) ?? [];
$newRole		=	$input['newRole'] ?? '';
$department		=	$input['department'] ?? '';
$fte			=	$input['fte'] ?? 1.0;
$proRataSalary	=	$input['proRataSalary'] ?? 0;
$salary			=	$input['salary'] ?? 0;
$startDate		=	$input['startDate'] ?? '';
$endDate		=	$input['endDate'] ?? '';
$contractType	=	$input['contractType'] ?? 1;

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$table_roles = $ref . '_roles';
	
	$q = queryMysql("SELECT `JOB_TITLE` FROM $table_roles");
	$r = $q->fetch(PDO::FETCH_ASSOC);
	
	// add the new role into the database
	if ($endDate == ""){
		$q = queryMysql("INSERT INTO $table_roles (`JOB_TITLE`,`DEPARTMENT`,`BENCHMARK_FTE`,`BENCHMARK_SALARY`,`BENCHMARK_PRORATA_SALARY`,`START_DATE`,`CONTRACT_TYPE`) VALUES ('$newRole','$department','$fte','$salary','$proRataSalary','$startDate','$contractType')");
	}else{
		$q = queryMysql("INSERT INTO $table_roles (`JOB_TITLE`,`DEPARTMENT`,`BENCHMARK_FTE`,`BENCHMARK_SALARY`,`BENCHMARK_PRORATA_SALARY`,`START_DATE`,`END_DATE`,`CONTRACT_TYPE`) VALUES ('$newRole','$department','$fte','$salary','$proRataSalary','$startDate','$endDate','$contractType')");
	}
	
	$response = json_encode(['status' => 'success']);
	echo $response;
}
?>