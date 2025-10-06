<?php // Script to add an employee into the database
session_start();
require_once('../includes/functions.php');


$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$destroyEmployee = $_COOKIE['destroyEmployee'];
	
	destroyCookie('destroyEmployee');
	
	$table_resources = $ref . '_resources';
	$table_details = $ref . '_details';
	
	$q = queryMysql("DELETE FROM $table_resources WHERE REF = '$destroyEmployee'");
	$q = queryMysql("DELETE FROM $table_details WHERE EMP_KEY = '$destroyEmployee'");

}
?>