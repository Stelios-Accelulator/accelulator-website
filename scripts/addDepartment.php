<?php // Script to add a department into the database
session_start();
require_once('../includes/functions.php');

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php and is safe, uses $_SESSION rather than $_COOKIE

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)

	$ref = getUsersCompanyId($user);
	
	$newDepartment = $_COOKIE['newDepartment'];
	
	echo <<<_DESTROYCOOKIES
	<script>
		eraseCookie('newDepartment');
	</script>
	_DESTROYCOOKIES;
	
	$aString = $ref . '_departments';
	
	$q = queryMysql("SELECT `DEPARTMENT` FROM $aString");
	$r = $q->fetch(PDO::FETCH_ASSOC);
	
	$departmentExists = 0;
	if (!empty($r)) {
		foreach($r as $department => $row){
			if($department==$newDepartment){
				$departmentExists = 1;
				echo <<<_SETFAILURECOOKIE
				<script>
					setCookie('FAILFLAG',1,1);
				</script>
				_SETFAILURECOOKIE;
			}
		}
	}
	
	if($departmentExists == 0){
	// Fetch all of the financial records for the employees
	$q = queryMysql("INSERT INTO $aString (`DEPARTMENT`) VALUES ('$newDepartment')");
	
	}
} else {
	echo "You're not logged in so shouldn't have been able to reach this page. Please visit <a href=`https://accelulator.com`>Accelulator</a> and try again.";
}
?>