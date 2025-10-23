<?php // Script to get the pay type from the database
session_start();
require_once('../includes/functions.php');

$user = checkUser(); // returns the user number if logged in, else returns blank and sends the user back to the homepage
// checkUser is in functions.php

if ($user != '') { // checks that the user number is not blank (see above checkUser() function)
	
	$ref = getUsersCompanyId($user);
	
	$table_paytype = $ref . '_paytype';
	$table_paytype_group = $ref . '_paytype_group';
	$x = 0;
	$id = '';
	$description = '';
	$category = '';
	$value = '';
	
	$q = queryMysql("SELECT 
		REF,
		DESCRIPTION, 
		VALUE, 
		PAYTYPE_GROUP_REF 
		FROM $table_paytype
		");
	$r = $q->fetchAll();
	
	foreach($r as $record => $row){
		
		$id = $row['REF'];
		$description = $row['DESCRIPTION'];
		$category = $row['PAYTYPE_GROUP_REF'];
		$value = $row['VALUE'];
		
		echo <<<_CREATEROWARRAY
			<script>
				typeList_$x = new Type('$id','$description','$category','$value');
				types.push(typeList_$x);
			</script>
		_CREATEROWARRAY;
		
		$x++;
	
	}
	
	// Extract all of the pay type groups
	$q = queryMysql("SELECT 
		REF,
		PAYTYPEGROUP,
		VALUE 
		FROM $table_paytype_group
	");
	
	$r = $q->fetchAll();
	
	$x = 0;
	$id = '';
	$payTypeGroup = '';
	$value = '';
	
	
	foreach($r as $record => $row){
		
		$id = $row['REF'];
		$payTypeGroup = $row['PAYTYPEGROUP'];
		$value = $row['VALUE'];
		
		echo <<<_CREATEGROUPARRAY
			<script>
				payTypeGroupList_$x = new PayTypeGroup('$id','$payTypeGroup', '$value');
				payTypeGroups.push(payTypeGroupList_$x);
			</script>
		_CREATEGROUPARRAY;
		
		$x++;
	}
	
	echo '<script>
	typeOptionString = "";
	
	currentSelection = document.getElementById("payType").value
	
	if (currentSelection == "totalCosts"){
		typeOptionString += \'<option value="totalCosts" selected="selected">Total Costs</option>\';
	}else{
		typeOptionString += \'<option value="totalCosts">Total Costs</option>\';
	}
	
	for (let a = 0; a < payTypeGroups.length; a++) {
		if (a === currentSelection) {
			typeOptionString += "<option value=\'" + payTypeGroups[a].value + "\' selected=\'selected\'>" + payTypeGroups[a].type + "</option>";
		} else {
			typeOptionString += "<option value=\'" + payTypeGroups[a].value + "\'>" + payTypeGroups[a].type + "</option>";
		}
	}
	
	document.getElementById("payType").innerHTML = typeOptionString;
	
	</script>';
}
?>