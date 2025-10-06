<script>
	// Create the employeeDetailsTable Array
	employeeDetailsTable = [];
	
	if(getCookie("department")!=null){
		selectedDepartment = getCookie("department");
	}else{
		selectedDepartment = 0;
	}
	
	// Use the empty div to load the php script
	// empty exists in order to run these scripts
	if (selectedDepartment > 0) {
		$('#empty').load("/scripts/getEmployeesFinancialsDepartment.php");
	}else {
		$('#empty').load("/scripts/getEmployeesFinancials.php");
	}
	
	$('#empty').load("/scripts/getPayType.php");
	
	// Load the default monthArray, using the current month as a baseline
	monthArray = generateMonthRange(0); // Calls the function to populate monthArray with 7 months prior and 6 months future; offset is set at zero
	
	// populate the header TDs with the months
	for (let i = 1; i <= month.length; i++) {
	  document.getElementById('month'+i+'TD').innerHTML = monthArray[i-1];
	}
	
	// populate the month selector with the months
	populateMonthSelector(monthOffset);
	offsetValue = document.getElementById('months').value;
	actualMonth = document.getElementById('months').options[6-offsetValue].text;
	
</script>
<!-- Create the dropdown menus for the user to be able to alter the output of the tables -->
<!-- calls to populateMonthArray(), populateActualMonth(), populateEmployeeOutturn(), populateTableBody() -->
<div class="padded">
	<div id="peopleForecastFilterContainer">
		<div id="monthFilter" class="peopleForecastFilters">
			<div>
				<label for="months"><strong>Month</strong></label>
			</div>
			<div>
				<select name="months" id="months" onchange="populateMonthArray(); populateActualMonth(); populateEmployeeOutturn(); populateTableBody();"></select>
			</div>
		</div>
		<div id="departmentFilter" class="peopleForecastFilters">
			<div>
				<label for="departmentDisplaySelector"><strong>Department</strong></label>
			</div>
			<div>
				<select name="departmentDisplaySelector" id="departmentDisplaySelector" onchange="changeDepartmentView();">
					<option value="0" selected="selected">All</option>
				</select>
			</div>
		</div>
		<div id="employeeTypeFilter" class="peopleForecastFilters">
			<div>
				<label><strong>Type</strong></label>
			</div>
			<div>
				<select name="employeeType" id="employeeType">
					<option>Permanent</option>
					<option>Fixed Term</option>
					<option>Contractor</option>
					<option>Temporary</option>
				</select>
			</div>
		</div>
		<div id="payTypeDiv" class="peopleForecastFilters">
			<div>
				<label for="payType"><strong>Pay element</strong></label>
			</div>
			<div>
				<select name="payType" id="payType" onchange="populateTableBody();">
					<option value="base" selected="selected">Base</option>
					<option value="employersNI">Er's NI</option>
				</select>
			</div>
		</div>
	</div>
	<h1>People Forecast</h1>
	<div><button onclick="showDepartmentMenu();">Add Department</button> <button onclick="addRoleMenu();">Add Role</button></div>
	<table>
		<thead id="tableHeader">
			<th></th>
			<th>REF</th>
			<th class='nameColumn'>Name</th>
			<th class='valueColumn'>Salary</th>
			<th class='valueColumn'>Actual</th>
			<th class='valueColumn'>Forecast</th>
			<th class='variance valueColumn'>Variance</th>
			<th class='spacer'></th>
			<th class="valueColumn" id='month1TD'></th>
			<th class="valueColumn" id='month2TD'></th>
			<th class="valueColumn" id='month3TD'></th>
			<th class="valueColumn" id='month4TD'></th>
			<th class="valueColumn" id='month5TD'></th>
			<th class="valueColumn" id='month6TD'></th>
			<th class="valueColumn cMonth" id='month7TD'></th>
			<th class="valueColumn" id='month8TD'></th>
			<th class="valueColumn" id='month9TD'></th>
			<th class="valueColumn" id='month10TD'></th>
			<th class="valueColumn" id='month11TD'></th>
			<th class="valueColumn" id='month12TD'></th>
			<th class="valueColumn" id='month13TD'></th>
		</thead>
		<tbody id="tableResults">
			
		</tbody>
		<tfoot>
			<td></td>
			<td></td>
			<td class='nameColumn'>TOTAL</td>
			<td class='valueColumn'></td>
			<td class='valueColumn' id="actualTotal"></td>
			<td class='valueColumn' id="forecastTotal"></td>
			<td class='variance valueColumn' id="varianceTotal"></td>
			<td class='spacer'></td>
			<td class="valueColumn" id='month1Total'></td>
			<td class="valueColumn" id='month2Total'></td>
			<td class="valueColumn" id='month3Total'></td>
			<td class="valueColumn" id='month4Total'></td>
			<td class="valueColumn" id='month5Total'></td>
			<td class="valueColumn" id='month6Total'></td>
			<td class="valueColumn cMonth" id='month7Total'></td>
			<td class="valueColumn" id='month8Total'></td>
			<td class="valueColumn" id='month9Total'></td>
			<td class="valueColumn" id='month10Total'></td>
			<td class="valueColumn" id='month11Total'></td>
			<td class="valueColumn" id='month12Total'></td>
			<td class="valueColumn" id='month13Total'></td>
			
			
	</table>
	<div id="addEmployee">
		<button onclick="addEmployeeMenu();" id="addEmployeeButton">+</button>
	</div>
</div>