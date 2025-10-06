<?php 
// Additional script to create roles where no employee has been allocated to it.
// Don't like this way of doing it as we have all of the roles in an array anyway so we should just be using that to populate the table
// Consider deleting this

session_start();
require_once('../includes/functions.php');


$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$table_resources = $ref . '_resources';
	$table_details = $ref . '_details';
	$table_actuals = $ref . '_actuals';
	$table_roles = $ref . '_roles';
	
	// Get the roles for the company that the user has access rights to
	$q = queryMysql("SELECT * FROM $table_roles WHERE FILLED_REFERENCE = 0;");
	$r = $q->fetchAll();
	
	$id = '';
	$jobTitle = '';
	$start_date = '';
	$end_date = '';
	$annual_salary = '';
	$fte = '';
	$department = '';
	$x = 0;
	
	foreach($r as $record => $row){
		$id = $row['REF'];
		$jobTitle = $row['JOB_TITLE'];
		$start_date = $row['START_DATE'];
		if (is_null($row['END_DATE'])) {
			$end_date = '9999-12-31';
		} else {
			$end_date = $row['END_DATE'];
		}
		$annual_salary = $row['BENCHMARK_SALARY'];
		$fte = $row['BENCHMARK_FTE'];
		$department = $row['DEPARTMENT'];
		
		// CONSIDER RE-WRITING THIS AT A LATER POINT AS IT DOESN'T NEED TO HAVE BOTH OBJECTS AND EMPLOYEEDETAILSTABLE
		echo <<<_CREATEROWARRAY
			<script>
				employeeLibrary.push('$id');
				employee_$id = new Employee('$id','$jobTitle','','$start_date','$end_date','$annual_salary','$fte','$x','$department');
				objects.push(employee_$id);
				employeeRow.push('$id');
				employeeRow.push('$jobTitle');
				employeeRow.push('');
				employeeRow.push('$start_date');
				employeeRow.push('$end_date');
				employeeRow.push('$annual_salary');
				employeeRow.push('$fte');
				employeeRow.push('$x');
				employeeRow.push('$department');
				employeeDetailsTable.push(employeeRow);
				employeeRow = [];
			</script>
		_CREATEROWARRAY;
		
		$id = '';
		$jobTitle = '';
		$start_date = '';
		$end_date = '';
		$annual_salary = '';
		$fte = '';
		$department = '';
		$x++;
	}
	
	echo <<<_OUTPUT
		<script>
			populateTableHead();
			clearTableBody();
			printObject(objects);
			populateTableBody();
			applyRolesToEmployees();
			$('#empty').load("/scripts/getDepartments.php");
		</script>
	_OUTPUT;

}

?>