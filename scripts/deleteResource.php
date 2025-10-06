<?php // Deletes the selected resource from the database
session_start();
require_once('../includes/functions.php');


$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$destroyResource = $_COOKIE['destroyResource'];
	
	destroyCookie('destroyResource');
	
	$table_resources = $ref . '_resources';
	$table_details = $ref . '_details';
	
	$q = queryMysql("DELETE FROM $table_resources WHERE REF = '$destroyResource'");
	$q = queryMysql("DELETE FROM $table_details WHERE EMP_KEY = '$destroyResource'");

}
?>