<script>
function IsSafari() { // Function to check if the userAgent is safari

	if (navigator.userAgent.toLowerCase().indexOf('safari/') >= 0) { // Checks if the userAgent includes "safari" and, if it does, sets the variable to true
			is_safari = true;
	} else {
		is_safari = false;
	}
	
	return is_safari; // Outputs either true or false
}

if (IsSafari() === true) { // Runs the IsSafari function and checks if it's TRUE
	document.write('<link rel="icon" type="image/png" href="/assets/favicon_original.png"/>');
} else {
	document.write('<link rel="icon" type="image/png" href="/assets/favicon_original.svg"/>');
}

let signedIn = 0; // a variable to ascertain whether the user is signed in or not

// ------------------------------
// ON DOCUMENT LOAD ROUTINE
// ------------------------------

$(document).ready(function(){ // Scripts to run when the document has fully loaded
	
	getContent = function(location){ // Is this used at any point?
		$.ajax({
			url: location,
			success:function(data){
				document.write(data);
			}
		});
	}
	
	dynamicContentLoad = function(identifier,resource){
		getContent = function(location){
			$.ajax({
				url: location,
				success:function(data){
					document.write(data);
				}
			});
		}
		$(identifier).html(getContent(resource))
	}
	
	$("#goHome").click(function(){
		signIn = getCookie('signedIn');
		if(signIn == 1){
			$("#contentView").load("/main/dashboard.php");
			$("#logOutLink").show();
		} else {
			$("#contentView").load("/main/home.php");
			$("#logInLink").show();
			$("#registerLink").show();
		}
	});
	
	$("#logIn").click(function(){
		$("#contentView").load("/includes/login.php");
	});
	
	$("#register").click(function(){
		$("#contentView").load("/includes/register.php");
	});
	
	$("#logOut").click(function(){
		$("#empty").load("./scripts/destroySession.php");
		$("#logInLink").show();
		$("#registerLink").show();
		$("#logOutLink").hide();
		window.location.href = window.location.href.split('#')[0];
	});
	
});

// ------------------------------
// END OF ON DOCUMENT LOAD ROUTINE
// ------------------------------

function setCookie(name,value,days) { // setCookie function to install a cookie
    var expires = "";
    if (days) {
        var date = new Date();
        date.setTime(date.getTime() + (days*24*60*60*1000));
        expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "")  + expires + "; path=/";
}

function eraseCookie(name) { // eraseCookie function to remove a cookie
    document.cookie = name +'=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;';
}

function getCookie(name) {
	var dc = document.cookie;
	var prefix = name + "=";
	var begin = dc.indexOf("; " + prefix);
	if (begin == -1) {
		begin = dc.indexOf(prefix);
		if (begin != 0) return null;
	}
	else
	{
		begin += 2;
		var end = document.cookie.indexOf(";", begin);
		if (end == -1) {
		end = dc.length;
		}
	}
	// because unescape has been deprecated, replaced with decodeURI
	//return unescape(dc.substring(begin + prefix.length, end));
	return decodeURI(dc.substring(begin + prefix.length, end));
}

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

for(i = 1; i <= tMonths; i++){
	x = i - aMonths;
	month.push(i);
	monthOffset.push(x);
}

// FUNCTIONS

function findPriorMonth(d){ // calculates the array position of the month prior to this
// required as, when the current month is January (0), I want to find December (11)

	if(d < 1){ // if the current month number is 0 (January)
		x = d+11; // the value that I need is 11 (December)
	}else{ // if it's not zero, just return the prior position (current - 1)
		x = d-1;
	}
	return x; // Output the derived number
}

function findNextMonth(d){ // calculates the array position of the month following this
// required as, when the current month is December (11), I want to find January (0)

	if(d < 11){ // if the current month number is not 11 (December)
		x = d + 1; // Just return the next position (current + 1)
	}else{ // if it is 11, return 0 (e.g. 11 - 11)
		x = d - 11;
	}
	return x; // Output the derived number
}

function populateMonthArray(){ // Populates the cells with the month name (MMM-YY)

	
	var x = document.getElementById('months');
	y = Number(x.value);
	
	monthArray = generateMonthRange(y);
	var iterator = 1;
	
	for (let i = 0; i < monthArray.length-1; i++) {
		document.getElementById('month'+iterator+'TD').innerHTML = monthArray[i];
		iterator++;
	}
}

function generateMonthRange(offset) { // Populates the monthArray array with the relevant months using an offset determined from the selector option
	
	if(isNaN(offset)){
		offset = 0
	}
	const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
	const currentDate = new Date();
	const startMonthIndex = -7 + offset; // 7 months prior (includes current reporting month, being last month)
	const endMonthIndex = 6 + offset;   // 6 months into the future
	const monthArray = [];

	for (let i = startMonthIndex; i <= endMonthIndex; i++) {
		const newDate = new Date(currentDate.getFullYear(), currentDate.getMonth() + i, 1); // Adjust month
		const formattedMonth = `${months[newDate.getMonth()]}-${newDate.getFullYear().toString().slice(-2)}`;
		monthArray.push(formattedMonth);
	}

	return monthArray;
}

function populateTableHead() { // Example usage to populate thead

	const monthArray = generateMonthRange();
	const thead = document.querySelector('thead');
	const row = document.createElement('tr');
	
	monthArray.forEach(month => {
		const th = document.createElement('th');
		th.textContent = month;
		row.appendChild(th);
	});

	thead.appendChild(row);
}

function populateTableBody() { // Script to populate the cells in the table with the employees' details
	
	selectedPayType = document.getElementById('payType').value; // Find out what the user wants to show
	offset = document.getElementById('months').value; // Find out what the offset should be (the value 
	generateMonthRange(offset);
	
	let columns = monthArray.length;
	let rows = objects.length;
	let x = 0;
	let column = -6;
	let y = 0;
	let row = 1;
	while (y < rows) {
		while (x < columns - 1) {
			
			cellReference = "c" + column + "r" + row;
			actualReference = "actual_" + row;
			forecastReference = "forecast_" + row; // will get used when a Forecast has been built: will populate the Forecast value for the month
			
			
			if(objects[y][monthArray[x]] == null){ // if the employee doesn't have a property for the month return 0.00
				document.getElementById(cellReference).innerHTML = '0';
			}else{ // if the property does exist, reference that property
				value = objects[y][monthArray[x]][selectedPayType];
				roundedValue = Math.round(value);
				document.getElementById(cellReference).innerHTML = roundedValue.toLocaleString();
			}
			
			if(column == 0){ // If the column = 0 then populate the Actual column
				if(objects[y][monthArray[x]] == null){
					document.getElementById(actualReference).innerHTML = '0';
				}else{
					roundedValue = Math.round(value);
					document.getElementById(actualReference).innerHTML = roundedValue.toLocaleString();
				}	
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
}

function Employee(id,firstname,surname,start_date,end_date,annual_salary,fte,actuals){ // Object to be used as template for employees
    this.id = id;
    this.firstname = firstname;
    this.surname = surname;
	this.start_date = start_date;
    this.end_date = end_date;
	this.annual_salary = annual_salary;
	this.fte = fte;
	this.name = firstname + " " + surname;
}

function monthlyValues(month,base){ // DEPRECATED? Object to be used as template for month and base
	this.month = month;
	this.base = base;
}

function printObject(data) { // Prints the user data within each employee into the table (not values)
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
			<td class='variance valueColumn'>250</td>
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

function populateEmployeeActuals(employee_id, date, baseValue, ersNIValue) { // Creates the actual values property and populates with the actuals for the month
// Called by getEmployeesFinancials.php
	
	temp = {base: baseValue, employersNI: ersNIValue, type:'actual'}; // creates a temporary object
	employee_id[date] = temp; // places the temporary object into a new property (date) in the object

}

function populateEmployeeOutturn(){ // Populates the employees with the outturn months and their relevant values
	
	determineMonthsInTable();
	repopulateOutturnMonths();
	
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

function createAddEmployeeForm(){ // adds a form to allow the user to add an employee
// Runs on clicking the "Add Employee" button
	
	removeElement("addEmployeeButton");
	
	var childElement = "";
	var firstNameField = '<td><input type = "text" placeholder = "First Name" id="newFirstName"></td>';
	var surnameField = '<td><input type = "text" placeholder = "Surname" id="newSurname"></td>';
	var salaryField = '<td><input type = "number" placeholder = "0.00" id="newSalary"></td>';
	var startDateField = '<td><input type = "date" id="newStartDate"></td>';
	var actionButton = '<button id="actionAddEmployee" onclick="actionAddEmployee();">Save</button>';
	childElement = '<table><tr>' + firstNameField + surnameField + salaryField + startDateField + '</tr></table><br>' + actionButton;
	document.getElementById("addEmployee").innerHTML = childElement;
}

function actionAddEmployee(){
	
	setCookie('newFirstName',document.getElementById("newFirstName").value,0);
	setCookie('newSurname',document.getElementById("newSurname").value,0);
	setCookie('newSalary',document.getElementById("newSalary").value,0);
	setCookie('newStartDate',document.getElementById("newStartDate").value,0);
	document.getElementById("addEmployee").innerHTML = '<button onclick="createAddEmployeeForm()" id="addEmployeeButton">Add employee</button>';
	$('#empty').load("/scripts/addEmployee.php");
	$("#contentView").load("/main/peopleForecast.php");
}

function monthsInYear(){
    this.Jan=1;
    this.Feb=2;
    this.Mar=3;
    this.Apr=4;
    this.May=5;
    this.Jun=6;
    this.Jul=7;
    this.Aug=8;
    this.Sep=9;
    this.Oct=10;
    this.Nov=11;
    this.Dec=12;
}

function createEmployeeMenu(selectedEmployeeNumber){
	x = selectedEmployeeNumber;
	y = objects[x].id;
	var menuExists = document.getElementById("employeeMenu");
	if (menuExists != null) {
		destroyEmployeeMenu();
	}
	employeeMenu = document.createElement("div");
	employeeMenu.id="employeeMenu";
	document.getElementById("contentView").appendChild(employeeMenu);
	document.getElementById("employeeMenu").innerHTML = `
	<div id='draggableEmployeeMenuHeader'>
		<strong>Employee Menu</strong>
		<button onClick='destroyEmployeeMenu();'>
			<strong>X</strong>
		</button>
	</div>
	<div>
		<label for='rName'>Name:</label>
		<input name='rName' type='text' value='${objects[x].name}'>
	</div>
	<div>
		<label for='startDate'>Start Date:</label>
		<input name='startDate' type='date' value='${objects[x].start_date}' id='start_date' onfocusout = 'updateEmployee(${y},${y});'>
	</div>
	<div>
		<label for='endDate'>End Date:</label>
		<input name='endDate' type='date' value='${objects[x].end_date}' id='end_date' onfocusout = 'updateEmployee(${y},${y});'>
	</div>
	<div>
		<label for='annualSalary'>Annual Salary:</label>
		<input name='annualSalary' type='number' value='${objects[x].annual_salary}' id='annual_salary' onfocusout = 'updateEmployee(${y},${y});'>
	</div>
	<div>
	<label for'fte'>Full Time Equivalent:</label>
		<input name='fte' type='number' value='${objects[x].fte}' id='fte' onfocusout = 'updateEmployee(${y},${y});'>
	</div>
	<div>
	<button onclick='deleteEmployee(${objects[x].id});'>Delete Employee</button><input type='reset' value='Reset'>
	</div>
	`;
	
}

function destroyEmployeeMenu(){
	var employeeMenu = document.getElementById("employeeMenu");
	employeeMenu.parentNode.removeChild(employeeMenu);
	
}

function deleteEmployee(selectedEmployeeNumber){
	setCookie('destroyEmployee',selectedEmployeeNumber,1);
	$("#contentView").load("/scripts/deleteEmployee.php");
	destroyEmployeeMenu();
	alert("employee removed");
	$("#contentView").load("/main/peopleForecast.php");
	
}

</script>
