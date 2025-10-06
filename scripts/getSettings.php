<?php 
// Script to get the settings for the user

session_start();
require_once(__DIR__ . '/../includes/functions.php');


$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$table_settings = $ref . '_settings'; // Set the table
	
	// Access the table
	$q = queryMysql("SELECT * FROM $table_settings;");
	$r = $q->fetchAll();
	
	foreach($r as $record => $row){
		
		setcookie($row['PREFERENCE'], $row['VALUE'], time()+3600, '/');
		
	}
	
}

?>