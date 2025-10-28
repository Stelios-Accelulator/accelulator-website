<script>

// -------------------------
// PEOPLE FORECAST FUNCTIONS
// -------------------------

// GLOBAL VARIABLES & ARRAYS

var month = []; // Array to store the number of months required
var monthOffset = []; 
var monthArray = [];
var actualMonths = []; // Array to store the months that are used for Actuals
var outturnMonths = []; // Array to store the months that are used for Outturn
var tempMonths = []; // Array to store the temporary months available
var aMonths = 7;
var oMonths = 6;
var tMonths = aMonths + oMonths;
var departments = [];
var types = [];
var payTypeGroups = [];
var roles = [];
var total = []; // variable to hold the total value of the table
var departmentOptions = ""; // variable to hold the string that can be used to populate the department selector

// setCookie("aMonths", aMonths, 1);
// setCookie("oMonths", oMonths, 1);

for(i = 1; i <= tMonths; i++){ // populates the month array with the number of months required e.g. Default: [1,2,3,4,5,6,7,8,9,10,11,12,13]; populates the monthOffset array with the offset position e.g. Default: [-6,-5,-4,-3,-2,-1,0,1,2,3,4,5,6]
	x = i - aMonths;
	month.push(i);
	monthOffset.push(x);
}

// ------------------------------
// FUNCTIONS
// ------------------------------

function clearTableBody(){ // TO BE DELETED Script to empty the Table so that it can be repopulated
	document.getElementById('tableResults').innerHTML = "";
}

function populateTableBody() { // TO BE DELETED Script to populate the cells in the table with the employees' details
// Acts as a refresh for the table
	
	selectedPayType = document.getElementById('payType').value; // Find out what the user wants to show
	offset = document.getElementById('months').value; // Find out what the offset should be (the value from the selector)
	generateMonthRange(offset);
	
	let columns = monthArray.length;
	let rows = objects.length;
	let x = 0;
	let column = -6;
	let y = 0;
	let row = 1;
	total = [0,0,0,0,0,0,0,0,0,0,0,0,0];
	actualTotal = 0;
	forecastTotal = 0;
	varianceTotal = 0;	
	while (y < rows) {
		while (x < columns - 1) {
			
			cellReference = "c" + column + "r" + row;
			actualReference = "actual_" + row;
			forecastReference = "forecast_" + row; // will get used when a Forecast has been built: will populate the Forecast value for the month
			
			
			if(objects[y][monthArray[x]] == null){ // if the employee doesn't have a property for the month return 0.00
				if(document.getElementById(cellReference)!=null){
					
					document.getElementById(cellReference).innerHTML = '0';
					total[x] = total[x] + 0;
				
				} else {
					
				}
			}else{ // if the property does exist, reference that property
				
				value = objects[y][monthArray[x]][selectedPayType];
				roundedValue = Math.round(value);
				if(document.getElementById(cellReference)!=null){
					
					document.getElementById(cellReference).innerHTML = roundedValue.toLocaleString();
					total[x] = total[x] + roundedValue;
					
				} else {
					
				}
			}
			
			if(column == 0){ // If the column = 0 then populate the Actual column, Forecast column, and Variance Column
				
				// sets the class of the column to be cMonth for CSS Purposes
				document.getElementById(cellReference).className = "valueColumn cMonth";
				
				if(objects[y][monthArray[x]] == null){
					if(document.getElementById(actualReference)!=null){
						document.getElementById(actualReference).innerHTML = '0';
						actual = 0;
					} else {
						
					}
				}else{
					roundedValue = Math.round(value);
					if(document.getElementById(actualReference)!=null){
						document.getElementById(actualReference).innerHTML = roundedValue.toLocaleString();
						actual = roundedValue;
					} else {
						
					}
				}
				forecastCell = "forecast_" + row;
				varianceCell = "variance_" + row;
				forecast = document.getElementById(forecastCell).innerHTML;
				forecast = Number(forecast.replace(/,/g, '')); // Regex to remove the "," in the value provided from the table (because it's formatted)
				// this will change once the forecasting functionality is actually built in as it should be taking it from the values extracted from the database
				
				variance = forecast - actual;
				
				document.getElementById(varianceCell).innerHTML = variance.toLocaleString();
				
				actualTotal = actualTotal + actual;
				forecastTotal = forecastTotal + forecast;
				varianceTotal = varianceTotal + variance;
				
			}
			x++;
			column++;
		}
		x = 0;
		column = -6;
		y++;
		row++;
	}
	y = 0;
	row = 1;
	
	for (z=0; z < tMonths; z++) {
		
		buc = z+1;
		
		if(total[z]==null){
			
		}else{
			idString = "month" + buc + "Total";
			document.getElementById(idString).innerHTML = total[z].toLocaleString();
		}
	}
	
	document.getElementById("actualTotal").innerHTML = actualTotal.toLocaleString();
	document.getElementById("forecastTotal").innerHTML = forecastTotal.toLocaleString();
	document.getElementById("varianceTotal").innerHTML = varianceTotal.toLocaleString();
	
	
}

function refreshTableBody(){ // TO BE DELETED
	clearTableBody();
	printObject(employeeDetailsTable);
	populateEmployeeOutturn();
	populateTableBody();
}

function printObject(data) { // TO BE DELETED Prints the user data within each employee into the table (not values)
	// NEED TO ADD DYNAMIC COLUMN NUMBER IN HERE
	let numberOfRecords = data.length;
	let i = 0;
	let r = 1;
	while (i < numberOfRecords) {
		result = `
		<tr>
			<td><input type='radio' id='record`+i+`' name='recordSelect' value='record`+i+`' onclick='createEmployeeMenu(`+i+`);')></td>
			<td>` + objects[i].id + `</td>
			<td>` + objects[i].firstname + ` ` + objects[i].surname + `</td>
			<td class='valueColumn'>` + Math.round(objects[i].annual_salary).toLocaleString() +`</td>
			<td class='valueColumn' id='actual_` + r + `'>2,000</td>
			<td class='valueColumn' id='forecast_` + r + `'>2,250</td>
			<td class='variance valueColumn' id='variance_` + r + `'>0</td>
			<td class='spacer'></td>
			<td class='valueColumn' id='c-6r` + r + `'>0.00</td>
			<td class='valueColumn' id='c-5r` + r + `'>0.00</td>
			<td class='valueColumn' id='c-4r` + r + `'>0.00</td>
			<td class='valueColumn' id='c-3r` + r + `'>0.00</td>
			<td class='valueColumn' id='c-2r` + r + `'>0.00</td>
			<td class='valueColumn' id='c-1r` + r + `'>0.00</td>
			<td class='valueColumn' id='c0r` + r + `' class='cMonth'>0.00</td>
			<td class='valueColumn' id='c1r` + r + `'>0.00</td>
			<td class='valueColumn' id='c2r` + r + `'>0.00</td>
			<td class='valueColumn' id='c3r` + r + `'>0.00</td>
			<td class='valueColumn' id='c4r` + r + `'>0.00</td>
			<td class='valueColumn' id='c5r` + r + `'>0.00</td>
			<td class='valueColumn' id='c6r` + r + `'>0.00</td>
		</tr>
		`
		$(result).appendTo("tbody");
		i++;
		r++;
	}
}

function populateMonthSelector(data){ // Populates the monthSelector selector with relevant month and values & determines which months are actuals and which are outturn
	
	select = document.getElementById("months");
	
	for(let i = 1; i <= data.length; i++){ // find the Actual/Outturn range and populate the relevant array with the month (mmm-yy)
		
		if(i<=7){
			actualMonths.push(monthArray[i-1]);
		}else{
			outturnMonths.push(monthArray[i-1]);
		}
		
		if(i==7){ // if function to determine which item is the current actual and have that preselected
			result = "<option value='"+data[i-1]+"' selected='selected'>"+monthArray[i-1]+"</option>"
		}else{
			result = "<option value='"+data[i-1]+"'>"+monthArray[i-1]+"</option>"
		}
		select.insertAdjacentHTML('beforeend',result);
	}
}

function populateActualMonth(){ // Populates the actualMonth value with the name of the month (mmm-yy)
	offsetValue = document.getElementById('months').value;
	bucket = 6 + Number(offsetValue);
	actualMonth = document.getElementById('months').options[bucket].text;
}

// 🗑️ CAN THIS BE DELETED? @ 26/10/2025
function populateEmployeeActuals(employee_id, date, baseValue, ersNIValue) { // Creates the actual values property and populates with the actuals for the month
// Called by getEmployeesFinancials.php
	
	temp = {base: baseValue, employersNI: ersNIValue, type:'actual'}; // creates a temporary object
	employee_id[date] = temp; // places the temporary object into a new property (date) in the object

}

function populateEmployeeOutturn(){ // Populates the employees with the outturn months and their relevant values
	
	determineMonthsInTable(); // Step through each of the months in the table and pull them into an array
	// Array is tempMonths
	repopulateOutturnMonths(); // Updates the outturnMonths array with new outturn months when the range extends
	// Array is outturnMonths
	
	for(let i = 0; i < objects.length; i++){
		endDate = objects[i]['end_date'];
		if (endDate == '') { // determine if the endDate is blank (which it will be unless an endDate has been determined)
			// if it is blank (i.e. the employee doesn't have a predicted end date) then populate it with a date FAR into the future
			eDate = new Date(9999,11,31);
		} else {
			// if it isn't blank, split the date into component parts (YYYY-MM-DD) and create a date with those parts
			splitEDate = endDate.split('-');
			eDate = new Date(splitEDate[0],splitEDate[1],splitEDate[2]);
		}
		
		startDate = objects[i]['start_date'];
		if (startDate == '') {
			sDate = new Date(1900,0,1);
		} else {
			splitSDate = startDate.split('-');
			sDate = new Date(splitSDate[0],splitSDate[1],splitSDate[2]);
		}
		
		annualSalary = objects[i]['annual_salary'];
		monthlySalary = annualSalary/12;
		monthlySalary = monthlySalary.toFixed(2);
		ersNI = calculateEmployersNationalInsurance(annualSalary, 0.138, 9100);
		miy = new monthsInYear();
		
		for(let x = 0; x < outturnMonths.length; x++){
			mult = 1; // Multiplier
			splitOutturnMonth = outturnMonths[x].split('-');
			currentMonth = new Date('20'+splitOutturnMonth[1],miy[splitOutturnMonth[0]],1);
			currentMonthDays = new Date('20'+splitOutturnMonth[1],miy[splitOutturnMonth[0]]+1,0);
			
			if (currentMonthDays < sDate){ // check if the employee has started yet
				
			}else {
				if (currentMonth > eDate){ // Find out if the employee has ended
					// Do nothing if they have
				} else {
					// If the employee has started and has not ended, then find out if either the start date or end date are in the current month (to determine whether we're going to have to take a fraction of the value)
					
					if (currentMonth.getMonth() == sDate.getMonth() && currentMonth.getFullYear() == sDate.getFullYear()) { // if the current month is the starting month, then calculate the starting pay
						
						workedDays = currentMonthDays.getDate() - sDate.getDate() + 1;
						allDays = currentMonthDays.getDate();
						mult = workedDays/allDays;
						adjustedSalary = monthlySalary / allDays * workedDays;
						adjustedErsNI = calculateEmployersNationalInsurance(adjustedSalary * 12, 0.138, 9100)
						temp = {base:adjustedSalary,employersNI: adjustedErsNI, type:'outturn'}
						
					} else if (currentMonth.getMonth() == eDate.getMonth() && currentMonth.getFullYear() == eDate.getFullYear()) { // if the current month is the leaving month, then calculate the ending pay
						
						workedDays = eDate.getDate();
						allDays = currentMonthDays.getDate();
						mult = workedDays/allDays;
						adjustedSalary = monthlySalary / allDays * workedDays;
						adjustedErsNI = calculateEmployersNationalInsurance(adjustedSalary * 12, 0.138, 9100)
						temp = {base:adjustedSalary,employersNI: adjustedErsNI, type:'outturn'}
						
					} else {
						
						temp = {base:monthlySalary,employersNI: ersNI, type:'outturn'}
						
					}
					
					objects[i][outturnMonths[x]] = temp;
				}
			}
		}
	}
}



function determineMonthsInTable(){ // Step through each of the months in the table and pull them into an array
// Created so that we can calculate the outturn for the additional months being shown 

	tempMonths = [];
	for(let i = 1; i<14;i++){
		t = document.getElementById('month' + i + 'TD').innerHTML;
		tempMonths.push(t);
	}
}

function repopulateOutturnMonths(){ // Updates the outturnMonths array with new outturn months when the range extends

	lastMonth = outturnMonths[outturnMonths.length-1];
	matchFound = 0;
	matchFoundIndex = 0
	for(i=0;i<tempMonths.length;i++){
		if(tempMonths[i]==lastMonth){
			matchFoundIndex = i;
			matchFound = 1;
		}
	}
	tx = matchFoundIndex + 1;
	if(matchFound!=0){
		for(x=tx;x<tempMonths.length;x++){
			outturnMonths.push(tempMonths[x]);
		}
	}
}

function calculateEmployersNationalInsurance(annualGrossPay,employersNIRate,annualNationalInsuranceThreshold){ // Calculates the Employer's NI payable for this month
// Is added as a property to the Employee
	
	monthlyThreshold = annualNationalInsuranceThreshold / 12;
	monthlyGrossPay = annualGrossPay /12;
	
	effectiveAmount = monthlyGrossPay - monthlyThreshold;
	if(effectiveAmount < 0){ // Check to make sure that negative effective amount can't occur
		effectiveAmount = 0;
	}
	result = effectiveAmount * employersNIRate;
	
	return result.toFixed(2);
	
}

function calculateLabourCapitalisation(basePay,labourCapPercentage){ // Calculates the Labour Capitalisation element for this month
// Is added as a property to the Employee
	
}

function calculateExceptionals(basePay,exceptionalsPercentage){ // Calculates the Exceptional element for this month
// Is added as a property to the Employee
	
}

function calculateCostOfSales(basePay,costOfSalesPercentage){ // Calculates the Cost of Sale element for this month
// Is added as a property to the Employee
	
}

function calculateOperatingCost(basePay, opexPercentage){ // Calculates the OPEX/SG&A element for this month
// Is added as a property to the Employee
	
	
}

function removeElement(elementName){
	var element = document.getElementById(elementName);
	element.parentNode.removeChild(element);
}

function addEmployeeMenu(){ // OLD - Get rid of
	
	removeElement("addEmployeeButton");
	
	var menuExists = document.getElementById("employeeMenu");
	if (menuExists != null){
		destroyEmployeeMenu();
	}
	employeeMenu = document.createElement("div");
	employeeMenu.id="employeeMenu";
	document.getElementById("contentView").appendChild(employeeMenu);
	document.getElementById("employeeMenu").innerHTML = ""+
	"<div id='draggableEmployeeMenuHeader'><strong>Add Employee</strong><button onClick='destroyEmployeeMenu();'><strong>X</strong></button></div>"+
	"<div><label>Forename:</label><input type='text' value ='' id='newFirstName'></div>"+
	"<div><label>Surname:</label><input type='text' value ='' id='newSurname'></div>"+
	"<div><label>Salary:</label><input type='text' value ='' id='newSalary'></div>"+
	"<div><label>Start Date:</label><input type='date' value ='' id='newStartDate'></div>"+
	"<button onclick='actionAddEmployee();destroyMenu(`employeeMenu`);'>Add Employee</button>";
	document.getElementById('newFirstName').focus();
}

function createEmployeeMenu(selectedEmployeeNumber){
//	applyRolesToEmployees();
	x = selectedEmployeeNumber;
	y = objects[x].id;
	var menuExists = document.getElementById("employeeMenu");
	if (menuExists != null) {
		destroyEmployeeMenu();
	}
	employeeMenu = document.createElement("div");
	employeeMenu.id="employeeMenu";
	
	departmentSelector = "<option value = '" + objects[x].departmentNumber + "'>" + objects[x].department + "</option>";
	
	for (a = 0; a < departments.length; a++) {
		if(departments[a].department != objects[x].department){
			departmentSelector = departmentSelector + "<option value='" + departments[a].ref + "'>" + departments[a].department + "</option>";
		}
	}
	document.getElementById("contentView").appendChild(employeeMenu);
	document.getElementById("employeeMenu").innerHTML = "<div id='draggableEmployeeMenuHeader'><strong>Employee Menu</strong> <button onClick='destroyEmployeeMenu();'><strong>X</strong></button></div>"+
	""+
	"<div><strong>" + objects[x].name + "</strong></div>"+
	"<div><strong>" + objects[x].jobTitle + "</strong></div>"+
	"<div><label>Department:</label><select name='department' id='department'>" + departmentSelector + "</select></div>" +
	"<div><label>Start Date:</label><input type='date' value='" + objects[x].start_date + "' id='start_date'></div>"+
	"<div><label>End Date:</label><input type='date' value='" + objects[x].end_date + "' id='end_date'></div>"+
	"<div><label>Annual Salary:</label><input type='number' value='" + objects[x].annual_salary + "' id='annual_salary'></div>"+
	"<div><label>Full Time Equivalent:</label><input type='number' value='" + objects[x].fte + "' id='fte'></div>"+
	"<div><button onclick='deleteEmployee(" + objects[x].id + ");'>Delete Employee</button><button id='saveEmployeeChanges' onclick='updateEmployee("+y+");'>Save</button></div>"+
	"<input type='number' id='arrayRef' value='" + x + "'>";
	
}

function formatDate(date) {
	if (!date) return '';
	const d = new Date(date);
	const year = d.getFullYear();
	const month = String(d.getMonth() + 1).padStart(2, '0'); // Months are 0-based
	const day = String(d.getDate()).padStart(2, '0');
	return `${year}-${month}-${day}`;
}

function showDepartmentMenu(){ // Creates and shows the Department menu
	
	var menuExists = document.getElementById("departmentMenu");
	if(menuExists != null){
		destroyMenu("departmentMenu");
	}
	departmentMenu = document.createElement("div");
	departmentMenu.id = "departmentMenu";
	departmentMenu.classList.add("menu");
	document.getElementById("contentView").appendChild(departmentMenu);
	document.getElementById("departmentMenu").innerHTML = ""+
	"<div id='draggableEmployeeMenuHeader'><strong>Department Menu</strong> <button onClick='destroyMenu(`departmentMenu`);'><strong>X</strong></button></div>"+
	"<div><input id='newDepartmentName' type='text' placeholder='New Department'></div>"+
	"<div><button onclick='actionAddDepartment();'>Add</button></div>";
	
}

function deselectRadioButtons(){
var elements = document.getElementsByTagName("input");

for (var i = 0; i < elements.length; i++) {
		if (elements[i].type == "radio") {
			elements[i].checked = false;
		}
	}
}

function destroyEmployeeMenu(){
	var employeeMenu = document.getElementById("employeeMenu");
	employeeMenu.parentNode.removeChild(employeeMenu);
	deselectRadioButtons();
	document.getElementById("addEmployee").innerHTML = '<button onclick="addEmployeeMenu();" id="addEmployeeButton">+</button>';
	
}

function applyRolesToEmployees(){
	for (x=0; x<roles.length; x++) {
		for (y=0; y<objects.length; y++) {
			if (roles[x].filledReference == objects[y].id) {
//				objects[y].departmentNumber = roles[x].department;
//				objects[y].department = departments[roles[x].department - 1].department;
				objects[y].jobTitle = roles[x].jobTitle;
			}
		}
	}
}

function applyDepartmentsToEmployees(){
	for (y=0; y < objects.length; y++){
		
		for (x=0; x < departments.length;x++){
			if(objects[y].departmentNumber == 0){
				objects[y].department = "Unallocated";
			}	else {
				if (objects[y].departmentNumber == departments[x].ref) {
					objects[y].department = departments[x].department;
				}
			}
		}	
	}
}

function refreshTable(){ // TO BE DELETED
// Latest function to refresh the contents of the table once a change has been made
	clearTableBody();
	printObject(employeeDetailsTable);
	populateTableBody();
	applyRolesToEmployees();
	applyDepartmentsToEmployees();
}

</script>