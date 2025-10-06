<?php // Script to get the details associated with each of the employees in the company
session_start();

// DEPENDENCIES
// - /includes/functions.php
// - /monthlyOutturn/monthlyOutturn.php
// - 

require_once('../../includes/functions.php');

// objects = []; is used but shouldn't be. Need to track down where this is being referenced so that I can remove the dependency and then remove it from here.
echo <<<_CREATEARRAYS
<script>
	var employeeLibrary = [], lib_resources = [], objects = [], departments = [], forecasts = [], userOutturn = [], niBands = [], niBandLookup = [];
</script>
_CREATEARRAYS;

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	$departmentSelector = 0; // Create a variable to flag if the user has a restriction on department or has selected a department
	
	if(isset($_COOKIE['department'])){
		$departmentSelector = $_COOKIE['department'];
	} else {
		$departmentSelector = 0;
	}
	
	$table_resources = $ref . '_resources';
	$table_details = $ref . '_details';
	$table_actuals = $ref . '_actuals';
	$table_roles = $ref . '_roles';
	$table_departments = $ref . '_departments';
	$table_forecasts = $ref . '_forecasts';
	$table_paytype = $ref . '_paytype';
	
	// Get the details for the company that the user has access rights to depending on which department they have selected
	$q = queryMysql("
		SELECT 
			$table_resources.REF AS RES_REF,
			$table_resources.FIRSTNAME,
			$table_resources.SURNAME,
			$table_details.START_DATE,
			$table_details.END_DATE,
			$table_details.ANNUAL_SALARY,
			$table_details.FTE,
			$table_resources.DEPARTMENT,
			$table_resources.CONTRACT_TYPE,
			$table_details.EMP_KEY
		FROM $table_resources
		LEFT JOIN $table_details ON $table_resources.REF = $table_details.EMP_KEY
		" . ($departmentSelector != 0 ? "WHERE DEPARTMENT = $departmentSelector" : "")
	);
	$r = $q->fetchAll();
	
	$id = '';
	$firstname = '';
	$surname = '';
	$start_date = '';
	$end_date = '';
	$annual_salary = '';
	$fte = '';
	$contractType = '';
	$x = 0;
	
	foreach($r as $record => $row){
		
		
		$id = $row['RES_REF'];
		$firstname = $row['FIRSTNAME'];
		$surname = $row['SURNAME'];
		$start_date = $row['START_DATE'];
		$end_date = $row['END_DATE'];
		$annual_salary = $row['ANNUAL_SALARY'];
		$fte = $row['FTE'];
		$department = $row['DEPARTMENT'];
		$contractType = $row['CONTRACT_TYPE'];
		
		if($row['REF']==10){
			echo <<<_TEST
				<script>
					alert("Name: '$firstname' '$surname'; Table Reference: '$id'");
				</script>
			_TEST;
		}
		
		// 
		echo <<<_CREATEROWARRAY
			<script>
				resource_$id = new Resource('$id','Unallocated','$firstname','$surname','$start_date','$end_date','$annual_salary','$fte','$x','$department','$contractType');
				lib_resources.push(resource_$id);
			</script>
		_CREATEROWARRAY;
		
		$id = '';
		$firstname = '';
		$surname = '';
		$start_date = '';
		$end_date = '';
		$annual_salary = '';
		$fte = '';
		$contractType = '';
		$x++;
	}
	
	// Fetch all of the financial records for the employees
	if($departmentSelector==0){
		$q = queryMysql("SELECT 
			$table_actuals.EMP_KEY as EMP_KEY,
			$table_actuals.DATE as DATE,
			$table_paytype.PAYTYPE_GROUP_REF as TYPE,
			$table_actuals.VALUE as VALUE 
			FROM $table_actuals
			LEFT JOIN $table_paytype 
			ON $table_actuals.TYPE = $table_paytype.REF
		");
	}else{
		$q = queryMysql("SELECT 
			$table_actuals.EMP_KEY as EMP_KEY,
			$table_actuals.DATE as DATE,
			$table_paytype.PAYTYPE_GROUP_REF as TYPE,
			$table_actuals.VALUE as VALUE 
			FROM $table_actuals 
			LEFT JOIN $table_paytype 
			ON $table_actuals.TYPE = $table_paytype.REF 
			LEFT JOIN $table_resources 
			ON $table_actuals.EMP_KEY = $table_resources.REF 
			WHERE DEPARTMENT = $departmentSelector
		");
	}
	$r = $q->fetchAll();
	
	$id = '';
	$date = '';
	$value = 0;
	$y = 0;
	$base = 0;
	$ersNI = 0;
	
	foreach($r as $record => $row){
		$id = $row['EMP_KEY'];
		$date = $row['DATE'];
		$type = $row['TYPE'];
		$value = $row['VALUE'];
			
		$date = dateToMMM_YY($date); // Function in functions.php; Converts a 2001-01-01 00:00:00 to JAN-01
		
		echo "<script>populateResourceActuals(resource_" . $id .", '$date', $type, $value);</script>";
		
		$id = '';
		$date = '';
		$value = 0;
		$base = 0;
		$ersNI = 0;
		$y++;
	}
	
	// Get the rows from the _roles table for this company and output into roles array
	if($departmentSelector == 0){
		$q = queryMysql("SELECT * FROM $table_roles");
	} else {
		$q = queryMysql("SELECT * FROM $table_roles WHERE DEPARTMENT = $departmentSelector");
	}
	$r = $q->fetchAll();
	
	$k = '';
	$jobTitle = '';
	$department = '';
	$filledReference = '';
	$status = '';
	$benchmarkFTE = '';
	$benchmarkSalary = '';
	$benchmarkProrataSalary = '';
	$startDate = '';
	$contractType = '';
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
		$startDate = $row['START_DATE'];
		$endDate = $row['END_DATE'];
		$contractType = $row['CONTRACT_TYPE'];
		
		echo <<<_POPROLES
			<script>
			role_$x = new Role($k,'$jobTitle','$department','$filledReference','$status','$benchmarkFTE','$benchmarkSalary','$benchmarkProrataSalary','$startDate','$endDate','$contractType','$x');
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
		$startDate = '';
		$endDate = '';
		$contractType = '';
		$x++;
	}
	
	// Get the departments and output into departments array
	$q = queryMysql("SELECT * FROM $table_departments");
	$r = $q->fetchAll();
	
	$id = '';
	$departmentName = '';
	$x = 0;
	
	foreach($r as $record => $row){
		
		$id = $row['REF'];
		$departmentName = $row['DEPARTMENT'];
		
		echo <<<_POPROLES
			<script>
			department_$x = new Department($id,'$departmentName');
			departments.push(department_$x);
			</script>
		_POPROLES;
		
		$id = '';
		$departmentName = '';
		$x++;
	}
	
	// Get the user's outturn requirements
	
	$stmt = $pdo->prepare("SELECT * FROM {$ref}_outturn");
	$stmt->execute();
	$userOutturn = $stmt->fetchAll(PDO::FETCH_ASSOC);
	$c = 0;
	
	foreach ($userOutturn as $outturn) {
		$rRef = $outturn['EMP_KEY'];
		$res_rol = $outturn['RES_ROL'];
		$date = dateToMMM_YY($outturn['DATE']); // format as MMM-YY
		$empKey = $outturn['EMP_KEY'];
		$type = $outturn['TYPE'];
		
		// ✅ Step 1: Lookup TYPE REF
		$typeLookupStmt = $pdo->prepare("
			SELECT VALUE 
			FROM {$ref}_paytype_group 
			WHERE REF = :type
			LIMIT 1
		");
		$typeLookupStmt->execute([
			':type'	=>	$type
		]);
		
		$typeRow = $typeLookupStmt->fetch(PDO::FETCH_ASSOC);
		
		$t = $typeRow['VALUE'];
		
		$value = $outturn['VALUE'];
	
		$library = ($res_rol === 'resource') ? 'lib_resources' : 'roles';
	
		echo "<script>
			if (!userOutturn[$c]) userOutturn[$c] = {};
			if (!userOutturn[$c].outturn) userOutturn[$c].outturn = {};
			if (!userOutturn[$c].outturn['$date']) userOutturn[$c].outturn['$date'] = {};
			
			userOutturn[$c].outturn['$date']['$t'] = $value;
			userOutturn[$c].library = '$library';
			userOutturn[$c].ref = $rRef;
		</script>";
		
		$c++;
	}
	
	// Get the names of the forecasts and output into forecast array
	$x = 0;
	$q = queryMysql("SELECT `ACTUAL_FORECAST`, `FORECAST_NAME`, `FORECAST_VERSION` FROM $table_forecasts GROUP BY `ACTUAL_FORECAST`, `FORECAST_NAME`, `FORECAST_VERSION`");
	$r = $q->fetchALL();
	
	foreach ($r as $row){
		$actualForecast = $row['ACTUAL_FORECAST'];
		$forecastName = $row['FORECAST_NAME'];
		$forecastVersion = $row['FORECAST_VERSION'];
		
		
		echo <<<_POPFLIST
			<script>
			temp = new ForecastList($x,'$actualForecast','$forecastName','$forecastVersion');
			forecasts.push(temp);
			</script>
		_POPFLIST;
		
		$x++;
	}
	
	try {
		$stmt = $pdo->prepare("SELECT 
			DATE_FORMAT(FROM_DATE, '%Y-%m-%d') AS FROM_DATE,
			DATE_FORMAT(TO_DATE, '%Y-%m-%d') AS TO_DATE,
			SECONDARY_THRESHOLD_MONTHLY, 
			RATE 
			FROM ni_employers_rates 
			ORDER BY FROM_DATE ASC
		");
		$stmt->execute();
		$niRates = $stmt->fetchAll(PDO::FETCH_ASSOC);
		
		foreach ($niRates as $row){
			$from_date = $row['FROM_DATE'];
			$to_date = $row['TO_DATE'];
			$secondary_threshold_monthly = $row['SECONDARY_THRESHOLD_MONTHLY'];
			$rate = $row['RATE'];
			echo <<<_NIBANDS
				<script>
					temp = {
						'FROM_DATE': '$from_date',
						'TO_DATE': '$to_date',
						'SECONDARY_THRESHOLD_MONTHLY': $secondary_threshold_monthly,
						'RATE': '$rate'
					};
					niBands.push(temp);	
				</script>
			_NIBANDS;
		}
		
		echo <<<_NIBANDLOOKUP
			<script>
				niBands.forEach(band => {
				const year = new Date(band.FROM_DATE).getFullYear();
				niBandLookup[year] = band;
				});
			</script>
		_NIBANDLOOKUP;
		
	} catch (PDOException $e) {
		http_response_code(500);
		echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
	}
	
	echo '<script src="/modules/monthlyOutturn/monthlyOutturn.js"></script>';
	
	echo <<<_OUTPUT
		<script>
			applyRolesToEmployees(), applyDepartments(), $('#empty').load("/scripts/getDepartments.php"), $('#empty').load("/scripts/getForecast.php"), allocateRoles(), populateForecastOptions(); createTable();
		</script>
	_OUTPUT;

}

?>