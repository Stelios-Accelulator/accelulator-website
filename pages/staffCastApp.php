<?php
// /pages/staffCastApp.php
$inject = isset($_GET['inject']) && $_GET['inject'] == '1';
if($inject == 1){
	
}else{
	require_once("../includes/header.php");
	require_once("../includes/functions.php");
}

?>

<script>
	
	
	
</script>
<div class="padded">
	<div id="peopleForecastFilterContainer">
		
		<div id="monthFilter" class="peopleForecastFilters">
			<div>
				<label for="months"><strong>Month</strong></label>
			</div>
			<div>
				<select name="months" id="months" onchange="createTable(); createSummaryTable();"></select>
			</div>
		</div>
		
		<div id="departmentFilter" class="peopleForecastFilters">
			<div>
				<label for="departmentDisplaySelector"><strong>Department</strong></label>
			</div>
			<div>
				<select id="departmentDisplaySelector" name="departmentDisplaySelector">
					<option value="0" selected="selected">All</option>
				</select>
			</div>
		</div>
		
		<div id="employeeTypeFilter" class="peopleForecastFilters">
			<div>
				<label for="contractType"><strong>Type</strong></label>
			</div>
			<div>
				<select name="contractType" id="contractType" onchange="changeContractTypeView();">
					<option value="0">All</option>
					<option value="1">Permanent</option>
					<option value="2">Fixed Term</option>
					<option value="3">Contractor</option>
					<option value="4">Temporary</option>
				</select>
			</div>
		</div>
		
		<div id="payTypeDiv" class="peopleForecastFilters">
			<div>
				<label for="payType"><strong>Pay element</strong></label>
			</div>
			<div>
				<select name="payType" id="payType" onchange="createTable(); createSummaryTable();">
				</select>
			</div>
		</div>
		
		<div id="forecastFilter" class="peopleForecastFilters">
			<div>
				<label for="forecastSelect"><strong>Forecast</strong></label>
				<select name="forecastSelect" id="forecastSelect" onchange="changeForecastView();">
				</select>
			</div>	
		</div>
	</div>
	<div id="dynamicToolbarView"></div>
	<h1>StaffCast</h1>
	<div>
		<h2>Departmental Summary</h2>
		<div style="display:flex; gap:20px; align-items:flex-start;">
			
			<div class="summary-row">
			  
			  <!-- LEFT: Departmental Summary -->
			  <div class="table-wrap summary-wrap">
			  	<div id="summarisedOutturnView" class="summary-left"></div>
			  </div>
			
			  <!-- RIGHT: Chart -->
			  <aside class="summary-right">
				<div class="chart-card">
				  <canvas id="peopleCostsChart" aria-label="People costs chart"></canvas>
				</div>
			  </aside>
			  
			</div>
			
			<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
		
		</div>
	</div>
	<div>
		<h2>Detailed Table</h2>
		<div class="table-wrap summary-wrap">
			<div id="monthlyOutturnView"></div>
		</div>
	</div>
</div>

<script>

	// Ensure #empty exists
	if (!document.getElementById('empty')) {
		const emptyDiv = document.createElement('div');
		emptyDiv.id = 'empty';
		emptyDiv.style.display = 'none'; // keep it invisible if it's just a loader container
		document.body.appendChild(emptyDiv);
	}
	
	// Now it's safe to load into it
	$('#empty').load("/scripts/getPayType.php");
	
	// Load the default monthArray, using the current month as a baseline
	monthArray = generateMonthRange(0); // Calls the function to populate monthArray with 7 months prior and 6 months future; offset is set at zero
	
	// populate the month selector with the months
	populateMonthSelector(monthOffset);
	offsetValue = document.getElementById('months').value;
	actualMonth = document.getElementById('months').options[6-offsetValue].text;
	fadeLoadContent("dynamicToolbarView","/modules/dynamicToolbar/dynamicToolbar.php",200,300); // function in javascript_functions.js
	fadeLoadContent("monthlyOutturnView","/modules/monthlyOutturn/monthlyOutturn.php",200,300); // function in javascript_functions.js
	fadeLoadContent("summarisedOutturnView","/modules/summarisedOutturn/summarisedOutturn.php",200,300); // function in javascript_functions.js
	if(getCookie("department")!=null){
		selectedDepartment = getCookie("department");
	}else{
		selectedDepartment = 0;
	}
	
	
	
</script>