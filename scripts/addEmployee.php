<?php // Script to add an employee into the database
session_start();
require_once('../includes/functions.php');

global $pdo;

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)

	$ref = getUsersCompanyId($user);
	
	$newFirstName = sanitizeString($_COOKIE['newFirstName']);
	$newSurname = sanitizeString($_COOKIE['newSurname']);
	$newSalary = sanitizeString($_COOKIE['newSalary']);
	$newStartDate = sanitizeString($_COOKIE['newStartDate']);
	
	echo <<<_DESTROYCOOKIES
	<script>
		eraseCookie('newFirstName');
		eraseCookie('newSurname');
		eraseCookie('newSalary');
		eraseCookie('newStartDate');
	</script>
	_DESTROYCOOKIES;
	
	$aString = $ref . '_resources';
	$bString = $ref . '_details';
	
	// Fetch all of the financial records for the employees
	$sql = "INSERT INTO $aString (`FIRSTNAME`,`SURNAME`) VALUES (:firstName, :surname)";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([
		':firstName' => $newFirstName,
		':surname' => $newSurname,
	]);
	
	$lastRef = $pdo->lastInsertId();
	
	$sql = "INSERT INTO $bString (`EMP_KEY`,`START_DATE`,`ANNUAL_SALARY`) VALUES (:emp_key, :start_date,:annual_salary)";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([
		':emp_key' => $lastRef,
		':start_date' => $newStartDate,
		':annual_salary' => $newSalary,
	]);
}
?>