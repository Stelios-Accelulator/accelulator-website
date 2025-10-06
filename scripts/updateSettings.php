<?php // Script to update the role reference in the databases
session_start();
require_once('../includes/functions.php');

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	$aMonths = $_COOKIE['aMonths'];
	$oMonths = $_COOKIE['oMonths'];
	
	$table_settings = $ref . '_settings';
	
	// Update the employees table for the role changes
	$q = queryMysql("UPDATE $table_settings SET `VALUE` = '$aMonths' WHERE `$table_settings`.`PREFERENCE` = 'aMonths'");
	
	// Update the filled reference for the employee being updated
	$q = queryMysql("UPDATE $table_settings SET `VALUE` = '$oMonths' WHERE `$table_settings`.`PREFERENCE` = 'oMonths'");
	
	echo "<script>alert('Settings Updated');</script>";
}

?>