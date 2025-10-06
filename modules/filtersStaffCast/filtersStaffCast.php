<!-- THIS FILE IS NOT USED. THE FILTERS ARE ON THE STAFFCASTAPP.PHP PAGE -->


<div id="peopleForecastFilterContainer">
	
	<div id="monthFilter" class="peopleForecastFilters">
		<div>
			<label for="months"><strong>Month</strong></label>
		</div>
		<div>
			<select name="months" id="months" onchange="createTable();"></select>
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
	
	<div id="forecastFilter" class="peopleForecastFilters">
		<div>
			<label for="forecastSelect"><strong>Forecast</strong></label>
			<select name="forecastSelect" id="forecastSelect" onchange="changeForecastView();">
			</select>
		</div>	
	</div>
	
</div>