<?php // Script to add a role into the database
session_start();
require_once('../includes/functions.php');

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$newRole = $_COOKIE['newRole'];
	$department = $_COOKIE['department'];
	$fte = $_COOKIE['fte'];
	$salary = $_COOKIE['salary'];
	$proRataSalary = $_COOKIE['proRataSalary'];
	$startDate = $_COOKIE['startDate'];
	
	if(empty($_COOKIE['endDate'])){
		$endDate = "";
	}else{
		$endDate = $_COOKIE['endDate'];
	};
	
	if(isset($_COOKIE['contractType'])){
		$contractType = $_COOKIE['contractType'];
	} else {
		$contractType = 1;
	}
	
	destroyCookie('newRole');
	destroyCookie('department');
	destroyCookie('fte');
	destroyCookie('salary');
	destroyCookie('proRataSalary');
	destroyCookie('startDate');
	destroyCookie('endDate');
	destroyCookie('contractType');
	
	$table_roles = $ref . '_roles';
	
	$q = queryMysql("SELECT `JOB_TITLE` FROM $table_roles");
	$r = $q->fetch(PDO::FETCH_ASSOC);
	
	// add the new role into the database
	if ($endDate == ""){
		$q = queryMysql("INSERT INTO $table_roles (`JOB_TITLE`,`DEPARTMENT`,`BENCHMARK_FTE`,`BENCHMARK_SALARY`,`BENCHMARK_PRORATA_SALARY`,`START_DATE`,`CONTRACT_TYPE`) VALUES ('$newRole','$department','$fte','$salary','$proRataSalary','$startDate','$contractType')");
	}else{
		$q = queryMysql("INSERT INTO $table_roles (`JOB_TITLE`,`DEPARTMENT`,`BENCHMARK_FTE`,`BENCHMARK_SALARY`,`BENCHMARK_PRORATA_SALARY`,`START_DATE`,`END_DATE`,`CONTRACT_TYPE`) VALUES ('$newRole','$department','$fte','$salary','$proRataSalary','$startDate','$endDate','$contractType')");
	}
}
?>