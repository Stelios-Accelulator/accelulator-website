<?php // Script to get the details associated with each of the employees in the company

session_start();
require_once('../includes/functions.php');


$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user); // Provides the company reference that can be used to access the relevant tables
	
	$dep = sanitizeString($_COOKIE['department']); // grab the department that should be displayed
	// DO NOT DESTROY THE COOKIE, IT'S CLEARED LATER IN THE PROCESS BY JAVASCRIPT; TRIED IT AND IT MEANT THAT THE DEPARTMENT DEFAULTED BACK TO ALL EACH TIME
	
	// Set the tables that need to be used
	$table_resources = $ref . '_resources';
	$table_details = $ref . '_details';
	$table_actuals = $ref . '_actuals';
	$table_roles = $ref . '_roles';
	
	// 
	$q = queryMysql("SELECT * FROM $table_resources LEFT JOIN $table_details ON " . $table_resources . ".REF = " . $table_details . ".EMP_KEY WHERE DEPARTMENT = '$dep' ");
	$r = $q->fetchAll();
	
	$id = '';
	$firstname = '';
	$surname = '';
	$start_date = '';
	$end_date = '';
	$annual_salary = '';
	$fte = '';
	$x = 0;
	
	echo <<<_CREATEARRAY
	<script>
		employeeRow = [];
		employeeLibrary = [];
		objects = [];
	</script>
	_CREATEARRAY;
	
	foreach($r as $record => $row){
		$id = $row['REF'];
		$firstname = $row['FIRSTNAME'];
		$surname = $row['SURNAME'];
		$start_date = $row['START_DATE'];
		$end_date = $row['END_DATE'];
		$annual_salary = $row['ANNUAL_SALARY'];
		$fte = $row['FTE'];
		$department = $row['DEPARTMENT'];
		
		// CONSIDER RE-WRITING THIS AT A LATER POINT AS IT DOESN'T NEED TO HAVE BOTH OBJECTS AND EMPLOYEEDETAILSTABLE
		echo <<<_CREATEROWARRAY
			<script>
				employeeLibrary.push('$id');
				employee_$id = new Employee('$id','$firstname','$surname','$start_date','$end_date','$annual_salary','$fte','$x','$department');
				objects.push(employee_$id);
				employeeRow.push('$id');
				employeeRow.push('$firstname');
				employeeRow.push('$surname');
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
		$firstname = '';
		$surname = '';
		$start_date = '';
		$end_date = '';
		$annual_salary = '';
		$fte = '';
		$x++;
	}
	
	// Fetch all of the financial records for the employees
	$q = queryMysql("SELECT * FROM $table_actuals");
	$r = $q->fetchAll();
	
	$id = '';
	$date = '';
	$value = 0;
	$y = 0;
	$base = 0;
	$ersNI = 0;
	
	echo "<script>tempArray = []</script>";
	
	foreach($r as $record => $row){
		$id = $row['EMP_KEY'];
		$date = $row['DATE'];
		$type = $row['TYPE'];
		$value = $row['VALUE'];
		switch ($type) {
			case 1:
				$base = $value;
				break;
			case 9:
				$ersNI = $value;
				break;
			default:
				$base = $value;
		}
			
		$date = date("M-y",strtotime($date));
		$dateParts = explode("-",$date);
		$dateSafe = $dateParts[0] . "_" . $dateParts[1];
		
		
		echo "<script>populateEmployeeActuals(employee_" . $id .", '$date', $base, $ersNI);</script>";
		echo "<script>populateEmployeeOutturn();</script>";
		
		$id = '';
		$date = '';
		$value = 0;
		$base = 0;
		$ersNI = 0;
		$y++;
	}
	
	// Get the details for the company that the user has access rights to
	$q = queryMysql("SELECT * FROM $table_roles");
	$r = $q->fetchAll();
	
	$k = '';
	$jobTitle = '';
	$department = '';
	$filledReference = '';
	$status = '';
	$benchmarkFTE = '';
	$benchmarkSalary = '';
	$benchmarkProrataSalary = '';
	$x = 0;
	
	foreach($r as $record => $row){
		
		$k = $row['REF'];
		$jobTitle = $row['JOB_TITLE'];
		$department = $row['DEPARTMENT'];
		$filledReference = $row['FILLED_REFERENCE'];
		$creationDate = $row['CREATION_DATE'];
		$status = $row['STATUS'];
		$benchmarkFTE = $row['BENCHMARK_FTE'];
		$benchmarkSalary = $row['BENCHMARK_SALARY'];
		$benchmarkProrataSalary = $row['BENCHMARK_PRORATA_SALARY'];
		
		echo <<<_POPROLES
			<script>
			role_$x = new Role($k,'$jobTitle','$department','$filledReference','$status','$benchmarkFTE','$benchmarkSalary','$benchmarkProrataSalary');
			roles.push(role_$x);
			</script>
		_POPROLES;
		
		$k = '';
		$jobTitle = '';
		$department = '';
		$filledReference = '';
		$status = '';
		$benchmarkFTE = '';
		$benchmarkSalary = '';
		$benchmarkProrataSalary = '';
		$x++;
	}
	
	echo <<<_OUTPUT
		<script>
			clearTableBody();
			printObject(employeeDetailsTable);
			populateTableBody();
			applyRolesToEmployees();
			$('#empty').load("/scripts/getDepartments.php");
		</script>
	_OUTPUT;

}

?>