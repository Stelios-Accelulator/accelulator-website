<?php // Deletes the selected role from the database
session_start();
require_once('../includes/functions.php');


$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$destroyRole = $_COOKIE['destroyRole'];
	
	destroyCookie('destroyRole');
	
	$table_roles = $ref . '_roles';
	
	$q = queryMysql("DELETE FROM $table_roles WHERE REF = '$destroyRole'");

}
?>