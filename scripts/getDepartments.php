<?php // Script to add an employee into the database
session_start();
require_once('../includes/functions.php');


$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$aString = $ref . '_departments';
	$x = 0;
	$id = '';
	$department = '';
	
	$q = queryMysql("SELECT * FROM $aString");
	$r = $q->fetchAll();
	
	echo <<<_CLEARDEP
		<script>
			departments = [];
		</script>
	_CLEARDEP;
	
	foreach($r as $record => $row){
		
		$id = $row['REF'];
		$department = $row['DEPARTMENT'];
		
		echo <<<_CREATEROWARRAY
			<script>
				departmentList_$x = new Department('$id','$department');
				departments.push(departmentList_$x);
			</script>
		_CREATEROWARRAY;
		
		$x++;
		$id = '';
		$department = '';
	
	}
	
	echo <<<_APPLYDEPARTMENTSTOEMPLOYEES
		<script>
			applyDepartmentsToEmployees();
			populateDepartmentOptions();
		</script>
	_APPLYDEPARTMENTSTOEMPLOYEES;
	
}
?>