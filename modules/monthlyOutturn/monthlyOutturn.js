

function createTable() {
	
	populateResourceOutturn(); // Populates each of the lib_resources and roles items with outturn values
	
	// get the number of actual months
	let actualMonthsValue = Number(scrub(getCookie('aMonths')));
	
	// Check if the value is a valid value
	if(actualMonthsValue == null){ // If no cookie exists, or it is invalid, default it to 7
		actualMonthsValue = 7;
		setCookie('aMonths',7);
	}
	
	// get the number of outturn months
	let outturnMonths = Number(scrub(getCookie('oMonths')));
	
	// Check if the value is a valid value
	if(outturnMonths == null){ // If no cookie exists, or it is invalid, default it to 6
		outturnMonths = 6;
		setCookie('oMonths',6);
	}
	
	// get the contract_type
	let contractType = Number(scrub(getCookie('contractType')));
	
	// Check if the value is valid and, if not, set it to zero
	if(contractType == null){
		contractType = 0;
	}
	
	// run monthArray function to populate all of the months necessary
	let selectedMonth = document.getElementById('months');
	let offset = selectedMonth.value
	
	let monthArray = [];
	monthArray = generateMonthArray(offset, actualMonthsValue, outturnMonths-1);
	
	data = lib_resources;
	const displayArea = document.getElementById('monthlyOutturnView');
	displayArea.innerHTML = ''; // Clear anything previously in the div

	if (data.length === 0 && roles.length === 0) {
		displayArea.innerHTML = '<p>No data available.</p>';
		return;
	}

	// Create table
	const table = document.createElement('table');
	table.setAttribute('class', 'monthly-outturn-table'); // Add class for styling if needed

	// Create header
	const thead = document.createElement('thead');
	thead.setAttribute('id','tableHeader');
	const headerRow = document.createElement('tr');
	let headers = [' ', 'Name', 'Job Title', 'Start Date', 'End Date', 'Salary', 'FTE', 'Department','Actual','Forecast','Variance'];
	
	monthArray.forEach(month => {
		headers.push(month);
	});
	
	let i = 1;
	let actualMonthSequenceNumber = 11 + Number(actualMonthsValue);
	
	headers.forEach(headerText => {
		const th = document.createElement('th');
		th.textContent = headerText;
	
		// Static columns
		if (i == 9) {
			th.classList.add('actual');
		} else if (i == 10) {
			th.classList.add('forecast');
		} else if (i == 11) {
			th.classList.add('variance');
		}
	
		// Dynamic month columns
		if (i >= 12) {
			if (i < actualMonthSequenceNumber) {
				th.classList.add('pMonth');
			} else if (i === actualMonthSequenceNumber) {
				th.classList.add('cMonth');
			} else if (i > actualMonthSequenceNumber) {
				th.classList.add('oMonth');
			}
		}
	
		headerRow.appendChild(th);
		i++;
	});
	
	thead.appendChild(headerRow);
	table.appendChild(thead);

	// Create body
	const tbody = document.createElement('tbody');
	tbody.id='tableResults';
	
	let cumulativeActual = 0.00;
	let cumulativeForecast = 0.00;
	let cumulativeMonths = [];
	let firstRow = 1;

	lib_resources.forEach(resource => {
		
		if (contractType == 0||resource.contractType == contractType){
		
			const formattedStartDate = new Date(resource.start_date).toLocaleDateString('en-GB');
			
			if(resource.end_date == '9999-12-31'||resource.end_date == null){
				correctedEndDate = 'n/a';
			} else {
				correctedEndDate = new Date(resource.end_date).toLocaleDateString('en-GB');
			}
			
			const tr = document.createElement('tr');
			
			const tdRef = document.createElement('td');
			tdRef.innerHTML = `<input type='radio' id='record` + resource.ref + `' name='recordSelect' value='record` + resource.ref +`' onclick='createResourceMenu(` + resource.rowNumber + `,"resources");')>`;
			tr.appendChild(tdRef);
			
			const tdName = document.createElement('td');
			tdName.textContent = resource.firstname + " " + resource.surname;
			tr.appendChild(tdName);
			
			const tdJobTitle = document.createElement('td');
			tdJobTitle.textContent = resource.jobTitle;
			tr.appendChild(tdJobTitle);
	
			const tdStart = document.createElement('td');
			tdStart.textContent = formattedStartDate;
			tr.appendChild(tdStart);
	
			const tdEnd = document.createElement('td');
			tdEnd.textContent = correctedEndDate;
			tr.appendChild(tdEnd);
	
			const tdSalary = document.createElement('td');
			tdSalary.textContent = Math.round(resource.annual_salary).toLocaleString();
			tdSalary.classList.add('valueColumn');
			tr.appendChild(tdSalary);
	
			const tdFTE = document.createElement('td');
			tdFTE.textContent = (resource.fte).toLocaleString();
			tdFTE.classList.add('valueColumn');
			tr.appendChild(tdFTE);
	
			const tdDept = document.createElement('td');
			tdDept.textContent = resource.departmentName; // Or a lookup for department name if available
			tr.appendChild(tdDept);
			
			let payType = document.getElementById('payType').value;
			
			// determine which month is the user selected month
			let currentMonthSelected = monthArray[actualMonthsValue-1];
			let currentMonthConstant = convertDateToMMMYY(eoMonth());
			
			
			// if it is in the future, use outturn; if is in the current or past, use actual
			if (parseMonthYear(currentMonthSelected) <= parseMonthYear(currentMonthConstant)) {
				// Using optional chaining
				const maybeActual = resource.actuals?.[currentMonthSelected]?.[payType];
			
				if (maybeActual !== undefined) {
					actualColumnValue = maybeActual;
				} else {
					actualColumnValue = 0.00;
				}
			} else {
				const maybeOutturn = resource.outturn?.[currentMonthSelected]?.[payType];
			
				if (maybeOutturn !== undefined) {
					actualColumnValue = maybeOutturn;
				} else {
					actualColumnValue = 0.00;
				}
			}
			
			// Populate the Actual column
			let tdThisMonth = document.createElement('td');
			let cMonthArrayReference = actualMonthsValue - 1;
			let cMonthActual = resource.actuals?.[cMonthArrayReference]?.[payType] ?? 0.00;
			let actual = actualColumnValue;
			cMonthActual = Math.round(actual);
			cMonthActual = cMonthActual.toLocaleString();
			tdThisMonth.textContent = cMonthActual;
			tdThisMonth.classList.add('valueColumn');
			tdThisMonth.classList.add('actual');
			tr.appendChild(tdThisMonth);
			
			cumulativeActual = Number(cumulativeActual) + Number(actualColumnValue);
			
			// Populate the Forecast column
			let tdForecast = document.createElement('td');
			let cMonthForecast = resource.forecast?.[monthArray[cMonthArrayReference]]?.[payType] ?? 0.00;
			forecastValue = Number(cMonthForecast);
			tdForecast.textContent = Math.round(forecastValue).toLocaleString();
			tdForecast.classList.add('valueColumn');
			tdForecast.classList.add('forecast');
			tr.appendChild(tdForecast);
			
			cumulativeForecast = Number(cumulativeForecast) + Number(forecastValue);
			
			// Populate the Variance column
			let tdVariance = document.createElement('td');
			varianceValue = forecastValue - actual;
			varianceValue = Math.round(varianceValue).toLocaleString();
			tdVariance.textContent = varianceValue;
			tdVariance.classList.add('valueColumn');
			tdVariance.classList.add('variance');
			tr.appendChild(tdVariance);
			
			// run through the monthArray and push each to the row
			
			let counter = -actualMonthsValue + 1;
			i = 12; // What is this doing?
			
			monthArray.forEach(month => {
				
				let valueVariable = 0.00;
				let actualsOutturn = 'actuals';
				
				let currentMonthSelected = month;
				let currentMonthConstant = convertDateToMMMYY(eoMonth());
				
				// find out if the month is in the present or the future
				if (parseMonthYear(currentMonthSelected) > parseMonthYear(currentMonthConstant)){
					actualsOutturn = 'outturn';
				}
				
				
				if(actualsOutturn != 'outturn'){
					maybeActual = resource.actuals?.[currentMonthSelected]?.[payType];
				} else {
					maybeActual = resource.outturn?.[currentMonthSelected]?.[payType];
				}
				
				if (maybeActual !== undefined) {
					valueVariable = Number(valueVariable) + Number(maybeActual);
				} else {
					valueVariable = Number(valueVariable) + Number(0.00);
				}
				
				let tdActual = document.createElement('td');
				let monthActual = valueVariable;
				monthActual = Math.round(monthActual);
				monthActual = monthActual.toLocaleString();
				tdActual.textContent = monthActual;
				
				if(i == actualMonthSequenceNumber){
					tdActual.classList.add('cMonth');
				}
				tdActual.classList.add('valueColumn');
					
				tr.appendChild(tdActual);
				counter++;
				i++;
				
			});
			
			tbody.appendChild(tr);
		}	
	});
	
	roles.forEach(role => {
		if(contractType == 0||role.contractType == contractType){
			if (role.filledReference == 0) { // Check to see if the role has been filled (0 means it has not)
				const tr = document.createElement('tr');
				
				const formattedStartDate = new Date(role.startDate).toLocaleDateString('en-GB');
				
				if(role.end_date == '9999-12-31'||role.end_date == null){
					correctedEndDate = 'n/a';
				} else {
					correctedEndDate = new Date(role.end_date).toLocaleDateString('en-GB');
				}
				
				const tdRef = document.createElement('td');
				tdRef.innerHTML = "<input type='radio' id='record" + role.ref + "' name='recordSelect' value='record" + role.ref + "' onclick='createResourceMenu(" + role.tableRef + ",`role`);')>";
				tr.appendChild(tdRef);
				
				const tdName = document.createElement('td');
				tdName.textContent = 'Vacant';
				tr.appendChild(tdName);
				
				const tdJobTitle = document.createElement('td');
				tdJobTitle.textContent = role.jobTitle;
				tr.appendChild(tdJobTitle);
				
				const tdStart = document.createElement('td');
				tdStart.textContent = formattedStartDate;
				tr.appendChild(tdStart);
				
				const tdEnd = document.createElement('td');
				tdEnd.textContent = correctedEndDate;
				
				tr.appendChild(tdEnd);
				
				const tdSalary = document.createElement('td');
				tdSalary.textContent = Math.round(role.benchmarkSalary).toLocaleString();
				tdSalary.classList.add('valueColumn');
				tr.appendChild(tdSalary);
				
				const tdFTE = document.createElement('td');
				tdFTE.textContent = role.benchmarkFTE.toLocaleString();
				tdFTE.classList.add('valueColumn');
				tr.appendChild(tdFTE);
				
				const tdDept = document.createElement('td');
				tdDept.textContent = role.departmentName; // Or a lookup for department name if available
				tr.appendChild(tdDept);
				
				let payType = document.getElementById('payType').value;
				
				// determine which month is the user selected month
				let currentMonthSelected = monthArray[actualMonthsValue-1];
				// determine whether this month is in the future or the past
				// get the current month
				let currentMonthConstant = convertDateToMMMYY(eoMonth());
				
				
				// if it is in the future, use outturn; if is in the current or past, use actual
				if (parseMonthYear(currentMonthSelected) <= parseMonthYear(currentMonthConstant)) {
					// Using optional chaining
					const maybeActual = role.actuals?.[currentMonthSelected]?.[payType];
				
					if (maybeActual !== undefined) {
						actualColumnValue = maybeActual;
					} else {
						actualColumnValue = 0.00;
					}
				} else {
					const maybeOutturn = role.outturn?.[currentMonthSelected]?.[payType];
				
					if (maybeOutturn !== undefined) {
						actualColumnValue = maybeOutturn;
					} else {
						actualColumnValue = 0.00;
					}
				}
				
				// Populate the Actual column
				let tdThisMonth = document.createElement('td');
				let cMonthArrayReference = actualMonthsValue - 1;
				let cMonthActual = role.actuals?.[cMonthArrayReference]?.[payType] ?? 0.00;
				let actual = actualColumnValue;
				cMonthActual = Math.round(actual);
				cMonthActual = cMonthActual.toLocaleString();
				tdThisMonth.textContent = cMonthActual;
				tdThisMonth.classList.add('valueColumn');
				tdThisMonth.classList.add('actual');
				tr.appendChild(tdThisMonth);
				
				cumulativeActual = Number(cumulativeActual) + Number(actualColumnValue);
				
				// Populate the Forecast column
				let tdForecast = document.createElement('td');
				let cMonthForecast = role.forecast?.[monthArray[cMonthArrayReference]]?.[payType] ?? 0.00;
				forecastValue = Number(cMonthForecast);
				tdForecast.textContent = Math.round(forecastValue).toLocaleString();
				tdForecast.classList.add('valueColumn');
				tdForecast.classList.add('forecast');
				tr.appendChild(tdForecast);
				
				cumulativeForecast = Number(cumulativeForecast) + Number(forecastValue);
				
				// Populate the Variance column
				let tdVariance = document.createElement('td');
				varianceValue = forecastValue - actual;
				varianceValue = Math.round(varianceValue).toLocaleString();
				tdVariance.textContent = varianceValue;
				tdVariance.classList.add('valueColumn');
				tdVariance.classList.add('variance');
				tr.appendChild(tdVariance);
				
				// run through the monthArray and push each to the row
				
				let counter = -actualMonthsValue + 1;
				i = 12; // What is this doing?
				
				monthArray.forEach(month => {
					
					let valueVariable = 0.00;
					let actualsOutturn = 'actuals';
					
					let currentMonthSelected = month;
					let currentMonthConstant = convertDateToMMMYY(eoMonth());
					
					// find out if the month is in the present or the future
					if (parseMonthYear(currentMonthSelected) > parseMonthYear(currentMonthConstant)){
						actualsOutturn = 'outturn';
					}
					
					
					if(actualsOutturn != 'outturn'){
						maybeActual = role.actuals?.[currentMonthSelected]?.[payType];
					} else {
						maybeActual = role.outturn?.[currentMonthSelected]?.[payType];
					}
					
					if (maybeActual !== undefined) {
						valueVariable = Number(valueVariable) + Number(maybeActual);
					} else {
						valueVariable = Number(valueVariable) + Number(0.00);
					}
					
					let tdActual = document.createElement('td');
					let monthActual = valueVariable;
					monthActual = Math.round(monthActual);
					monthActual = monthActual.toLocaleString();
					tdActual.textContent = monthActual;
					
					if(i == actualMonthSequenceNumber){
						tdActual.classList.add('cMonth');
					}
					tdActual.classList.add('valueColumn');
						
					tr.appendChild(tdActual);
					counter++;
					i++;
					
				});
				
				tbody.appendChild(tr);
			}
		}	
	})
	
	table.appendChild(tbody);
	
	
	// Create a tfoot
	let tfoot = document.createElement('tfoot');
	tfoot.setAttribute('id','tableFooter');
	
	const tr = document.createElement('tr');
	
	// Create tds for the left hand side
	let tfRef = document.createElement('td');
	tfRef.innerHTML = ``;
	tr.appendChild(tfRef);
	
	let tfName = document.createElement('td');
	tfName.innerHTML = `Total`;
	tr.appendChild(tfName);
	
	let tfJobTitle = document.createElement('td');
	tfJobTitle.innerHTML = ``;
	tr.appendChild(tfJobTitle);
	
	let tfStartDate = document.createElement('td');
	tfStartDate.innerHTML = ``;
	tr.appendChild(tfStartDate);
	
	let tfEndDate = document.createElement('td');
	tfEndDate.innerHTML = ``;
	tr.appendChild(tfEndDate);
	
	let tfSalary = document.createElement('td');
	tfSalary.innerHTML = ``;
	tr.appendChild(tfSalary);
	
	let tfFTE = document.createElement('td');
	tfFTE.innerHTML = ``;
	tr.appendChild(tfFTE);
	
	let tfDepartment = document.createElement('td');
	tfDepartment.innerHTML = ``;
	tr.appendChild(tfDepartment);
	
	let tfActual = document.createElement('td');
	tfActual.innerHTML = Number(Math.round(cumulativeActual)).toLocaleString();
	tfActual.classList.add('valueColumn');
	tfActual.classList.add('actual');
	
	tr.appendChild(tfActual);
	
	let tfForecast = document.createElement('td');
	tfForecast.innerHTML = Number(Math.round(cumulativeForecast)).toLocaleString();
	tfForecast.classList.add('valueColumn');
	tfForecast.classList.add('forecast');
	tr.appendChild(tfForecast);
	
	let tfVariance = document.createElement('td');
	tfVariance.innerHTML = Math.round(Number(cumulativeForecast) - Number(cumulativeActual)).toLocaleString();
	tfVariance.classList.add('valueColumn');
	tfVariance.classList.add('variance');
	tr.appendChild(tfVariance);
	
	let counter = -actualMonthsValue + 1;
	i = 12;
	
	// Iterate through the months
	monthArray.forEach(month => {
		
		let valueVariable = 0.00;
		let actualsOutturn = 'actuals';
		let maybeActual = '';
		
		// determine which month is the user selected month
		let currentMonthSelected = month;
		let currentMonthConstant = convertDateToMMMYY(eoMonth());
		
		// find out if the month is in the present or the future
		if (parseMonthYear(currentMonthSelected) > parseMonthYear(currentMonthConstant)){
			actualsOutturn = 'outturn';
		}
		
		lib_resources.forEach (resource =>{
			if(contractType == 0||resource.contractType == contractType){
				if(actualsOutturn != 'outturn'){
					maybeActual = resource.actuals?.[currentMonthSelected]?.[payType.value];
				} else {
					maybeActual = resource.outturn?.[currentMonthSelected]?.[payType.value];
				}
				
				if (maybeActual !== undefined) {
					valueVariable = Number(valueVariable) + Number(maybeActual);
				} else {
					valueVariable = Number(valueVariable) + Number(0.00);
				}
			}
		});
		
		roles.forEach (role =>{
			
			if(role['filledReference'] == '0'){
				if(contractType==0||role.contractType == contractType){
					if(actualsOutturn != 'outturn'){
						maybeActual = role.actuals?.[currentMonthSelected]?.[payType.value];
					} else {
						maybeActual = role.outturn?.[currentMonthSelected]?.[payType.value];
					}
					
					if (maybeActual !== undefined) {
						valueVariable = Number(valueVariable) + Number(maybeActual);
					} else {
						valueVariable = Number(valueVariable) + Number(0.00);
					}
				}
			}
		});
		
		let tfMonth = document.createElement('td');
		if (i == actualMonthSequenceNumber){
			tfMonth.classList.add('cMonth');
		}
		
		tfMonth.classList.add('valueColumn');
		
		tfMonth.innerHTML = Math.round(Number(valueVariable)).toLocaleString();
		tr.appendChild(tfMonth);
		
		counter++;
		i++;
		
	});
	
	// Iterate through the resources, then the roles
	// If the month is historic, populate with the actuals, if future, populate with outturn
	
	tfoot.appendChild(tr);
	
	table.appendChild(tfoot);
	
	displayArea.appendChild(table);
}


function allocateRoles(){ // Function to allocate the roles to the employees (where they're linked)
	roles.forEach(role => { // Go through each of the roles
		lib_resources.forEach(resource => { // Go through each of the resources
			if(role.filledReference == resource.ref){ // if the role matches the resource's role, fill in the Job Title
				resource.jobTitle = role.jobTitle;
			}
		});
		if(role.jobTitle == null){ // If there has been no match, set the Job Title to Unallocated
			role.jobTitle = 'Unallocated';
		}
	});
}

function allocateForecast() {
	for (let type in forecastRows) {
		let dataBlock = forecastRows[type]; // resource or role object with references as keys

		for (let reference in dataBlock) {
			// Convert string to number for comparison
			const refNum = Number(reference);

			// Find matching resource/role by its .ref property
			let match;
			if (type === "resource") {
				match = lib_resources.find(r => Number(r.ref) === refNum);
			} else {
				match = roles.find(r => Number(r.ref) === refNum);
			}

			if (match) {
				// Store the forecast data on the object
				match.forecast = dataBlock[reference];
			}
		}
	}
}

function applyDepartments(){ // Function to allocate the departments to the employees
	lib_resources.forEach(resource => { // Go through each of the resources in the library
		departments.forEach(department => {
			if(resource.departmentNumber == department.ref){
				resource.departmentName = department.department;
			}
		});
		if(resource.departmentName == null){
			resource.departmentName = 'Unallocated';
		}
	});
	
	roles.forEach(role => {
		departments.forEach(department => {
			if(role.department == department.ref){
				role.departmentName = department.department;
			}
		});
		if(role.departmentName == null){
			role.departmentName = 'Unallocated';
		}
	});
}

function deselectRadioButton(ref){
	reference = "record" + ref;
	selectedRadioButton = document.getElementById(reference);
	selectedRadioButoon.checked = false;
}

function createResourceMenu(selectedResourceNumber, type){
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null){
		destroyMenu('menuContainer');
	};
	
	// Preliminary setting of variables
	let x = selectedResourceNumber;
	let y = 0;
	let dep = 0;
	let depName = '';
	let name = '';
	let jobTitle = '';
	let annualSalary = 0;
	let fte = 1;
	let typeText = 'Employee';
	let contractType = '1';
	let resource = '';
	let arrayName = '';
	
	// Set the variables depending on whether the record is a resource or a role
	if(type == 'resources'){
		y = lib_resources[x].ref;
		dep = lib_resources[x].departmentNumber;
		depName = lib_resources[x].departmentName;
		name = lib_resources[x].firstname + " " + lib_resources[x].surname;
		jobTitle = lib_resources[x].jobTitle;
		startDate = lib_resources[x].start_date;
		endDate = lib_resources[x].end_date;
		annualSalary = lib_resources[x].annual_salary;
		fte = lib_resources[x].fte;
		contractType = lib_resources[x].contractType;
		resource += `lib_resources[${x}]`;
		arrayName = 'lib_resources';
		
	} else {
		typeText = 'Role';
		y = roles[x].ref;
		dep = roles[x].department;
		depName = roles[x].departmentName;
		name = 'Vacant';
		jobTitle = roles[x].jobTitle;
		startDate = roles[x].startDate;
		if (roles[x].endDate != null){
			endDate = roles[x].endDate;
		} else {
			endDate = "9999-12-31";
		}
		annualSalary = roles[x].benchmarkSalary;
		fte = roles[x].benchmarkFTE;
		contractType = roles[x].contractType;
		resource = `roles[${x}]`;
		arrayName = 'roles';
	}
	
	departmentSelector = "<option value = '" + dep + "' selected>" + depName + "</option>";
	
	for (a = 0; a < departments.length; a++) { // I do need this as it populates the rest of the dropdown list so that I can change the department
		if(departments[a].ref != dep){
			departmentSelector = departmentSelector + "<option value='" + departments[a].ref + "'>" + departments[a].department + "</option>";
		}
	}
	
	let contractTypes = [];
	contractTypes.push('Permanent');
	contractTypes.push('Fixed Term');
	contractTypes.push('Contractor');
	contractTypes.push('Temporary');
	
	contractTypeSelector = "<option value = '" + contractType + "'>" + contractTypes[contractType - 1] + "</option>"
	
	for (a = 1; a <= contractTypes.length; a++) {
		if(contractType != a){
			contractTypeSelector = contractTypeSelector + "<option value='" + a + "'>" + contractTypes[a - 1] + "</option>";
		}
	}
	
	// CREATE THE MENU
	resourceMenu = document.createElement("div");
	resourceMenu.id = "menuContainer";
	
	
	contentView.appendChild(resourceMenu);
	resourceMenu.innerHTML = `
	<div class='menuHeader'>
		<strong>Update Menu</strong>
		<button onClick='destroyMenu("menuContainer"); deselectRadioButton(${y});'>X</button>
	</div>
	<div class='menuRow'><strong>${name}</strong></div>
	<div class='menuRow'><strong>${jobTitle}</strong></div>
	<div class='menuRow'><label>Contract Type</label><select id='contract_type'>${contractTypeSelector}</select></div>
	<div class='menuRow'><label>Department</label><select id='department'>${departmentSelector}</select></div>
	<div class='menuRow'><label>Start Date</label><input type='date' value='${startDate}' id='start_date'></div>
	<div class='menuRow'><label>End Date</label><input type='date' value='${endDate}' id='end_date'></div>
	<div class='menuRow'><label>Annual Salary</label><input type='number' value='${annualSalary}' id='annual_salary'></div>
	<div class='menuRow'><label>Full Time Equivalent</label><input type='number' value='${fte}' id='fte'></div>
	<div class='buttonGroup'>
		<button onclick='deleteRoleResource(${y}, "${type}");'>Delete ${typeText}</button>
		<button id='advancedEdit' onclick='advancedEmployeeEdit(${resource},${x},"${arrayName}");'>Advanced Edit</button>
		<button id='saveEmployeeChanges' onclick='updateEmployee(${y}, "${type}");'>Save</button>
	</div>
	<input type='hidden' id='arrayRef' value='${x}'>`;
	
	makeDraggable(resourceMenu);
}

function deselectRadioButton(ref){
	let reference = "record" + ref;
	let selectedRadioButton = document.getElementById(reference);
	selectedRadioButton.checked = false;
}

function maintainOneHundredPercent(currentSelection) { // Ensures that the three percentages all equate to 100%
	let opex = document.getElementById('opexPercentage');
	let exceptional = document.getElementById('exceptionalPercentage');
	let capex = document.getElementById('capitalisationPercentage');

	let opexValue = Number(scrub(opex.value));
	let exceptionalValue = Number(scrub(exceptional.value));
	let capexValue = Number(scrub(capex.value));

	let total = opexValue + exceptionalValue + capexValue;
	let overflow = total - 100;

	// If the total is already 100, nothing to do
	if (overflow === 0) return;

	// Prioritised adjustment order
	let fields = [
		{ key: 'exceptional', element: exceptional, value: exceptionalValue },
		{ key: 'capex', element: capex, value: capexValue },
		{ key: 'opex', element: opex, value: opexValue }
	];

	// Don't reduce the one currently being edited
	fields = fields.filter(field => field.key !== currentSelection);

	// Reduce overflow by adjusting other fields in order
	for (let field of fields) {
		if (overflow === 0) break;

		let adjust = Math.min(field.value, overflow);
		field.value -= adjust;
		overflow -= adjust;
	}

	// Set new values (rounded to one decimal place)
	exceptional.value = Math.max(0, (fields.find(f => f.key === 'exceptional')?.value ?? exceptionalValue)).toFixed(1);
	capex.value = Math.max(0, (fields.find(f => f.key === 'capex')?.value ?? capexValue)).toFixed(1);
	opex.value = Math.max(0, (fields.find(f => f.key === 'opex')?.value ?? opexValue)).toFixed(1);
}

function updateActualsAdvancedEdit(resource){
	let month = scrub(document.getElementById('actualSelect').value);
	let base = document.getElementById("baseValue");
	let overtime = document.getElementById("overtimeValue");
	let onCall = document.getElementById("onCallValue");
	let bonus = document.getElementById("bonusValue");
	let other = document.getElementById("otherValue");
	let welfare = document.getElementById("welfareValue");
	let pension = document.getElementById("pensionValue");
	let statutoryPay = document.getElementById("statutoryPayValue");
	let employersNI = document.getElementById("employersNIValue");
	let commission = document.getElementById("commissionValue");
	let employeeCosts = document.getElementById("employeeCostsValue");
	let r = resource;
	
	base.value = (Math.round((r['actuals'][month]['base'] ?? 0.00)*100)/100).toFixed(2);
	overtime.value = (Math.round((r['actuals'][month]['overtime'] ?? 0.00)*100)/100).toFixed(2);
	onCall.value = (Math.round((r['actuals'][month]['onCall'] ?? 0.00)*100)/100).toFixed(2);
	bonus.value = (Math.round((r['actuals'][month]['bonus'] ?? 0.00)*100)/100).toFixed(2);
	other.value = (Math.round((r['actuals'][month]['other'] ?? 0.00)*100)/100).toFixed(2);
	welfare.value = (Math.round((r['actuals'][month]['welfare'] ?? 0.00)*100)/100).toFixed(2);
	pension.value = (Math.round((r['actuals'][month]['pension'] ?? 0.00)*100)/100).toFixed(2);
	statutoryPay.value = (Math.round((r['actuals'][month]['statutoryPay'] ?? 0.00)*100)/100).toFixed(2);
	employersNI.value = (Math.round((r['actuals'][month]['employersNI'] ?? 0.00)*100)/100).toFixed(2);
	commission.value = (Math.round((r['actuals'][month]['commission'] ?? 0.00)*100)/100).toFixed(2);
	employeeCosts.value = (Math.round((r['actuals'][month]['employeeCosts'] ?? 0.00)*100)/100).toFixed(2);
}

function updateOutturnAdvancedEdit(resource, months) {
	let payType = document.getElementById('outturnSelect').value;

	months.forEach(month => {
		let value = resource['outturn'][month]?.[payType] ?? 0.00;
		let rounded = Math.round(value * 100) / 100;
		document.getElementById(`input_${month}`).value = rounded.toFixed(2);
	});
}

function updateAdvancedActuals(resourceRef, arrayName, value, type){
	monthValue = document.getElementById('actualSelect').value; // Get the month that the 
	
	let dataArray = window[arrayName];
	dataArray[resourceRef]['actuals'][monthValue][type] = value;
	let tableRef = Number(dataArray[resourceRef].ref);
	value = Number(value);
	
	fetch("/scripts/updateActuals.php", {
		method: "POST",
		headers: {
			"Content-Type": "application/json",
			"X-CSRF-Token": window.csrfToken // <-- include the token
		},
		body: JSON.stringify({
			resourceRef: tableRef,
			month: monthValue,
			type: type,
			value: value
		})
	})
}

function updateAdvancedOutturn(resourceRef, arrayName, month){
	
	console.log(arrayName);
	
	let res_rol = 'resource';
	console.log(arrayName);
	if(arrayName == 'roles'){
		res_role = 'roles';
	}
	
	let type = document.getElementById('outturnSelect').value; // Get the type that the user has selected
	let value = scrub(document.getElementById('input_' + month).value);
	
	let dataArray = window[arrayName];
	dataArray[resourceRef]['outturn'][month][type] = value;
	let tableRef = Number(dataArray[resourceRef].ref);
	value = Number(value);
	console.log(`resourceRef: ${tableRef}; arrayName: ${arrayName}; value: ${value}; month: ${month}; type: ${type}`);
	
	fetch("/scripts/updateOutturn.php", {
		method: "POST",
		headers: {
			"Content-Type": "application/json",
			"X-CSRF-Token": window.csrfToken // <-- include the token
		},
		body: JSON.stringify({
			res_rol: res_rol,
			resourceRef: tableRef,
			month: month,
			type: type,
			value: value
		})
	})
}

function advancedEmployeeEdit(resource, arrayRef, arrayName) {
	
	let oValue = resource['opex'] ?? 100;
	let eValue = resource['exceptional'] ?? 0;
	let cValue = resource['capex'] ?? 0;

	let actualMonths = Object.keys(resource['actuals']);
	let outturnMonths = Object.keys(resource['outturn']);

	contentView.innerHTML = `
		<div class='padded'>
			<div>
				<h1>Advanced Edit - ${resource.firstname} ${resource.surname}</h1>
			</div>

			<div>
				<h2>Categorisation</h2>
				<div>
					<label for='opexPercentage'>Operating Costs</label>
					<input name='opexPercentage' id='opexPercentage' type='number' placeholder='100' value='${oValue}' min='0' max='100' step='25' onchange='maintainOneHundredPercent("opex");'>%
				</div>
				<div>
					<label for='exceptionalPercentage'>Exceptional Costs</label>
					<input name='exceptionalPercentage' id='exceptionalPercentage' type='number' placeholder='0' value='${eValue}' min='0' max='100' step='25' onchange='maintainOneHundredPercent("exceptional");'>%
				</div>
				<div>
					<label for='capitalisationPercentage'>Labour Capitalisation</label>
					<input name='capitalisationPercentage' id='capitalisationPercentage' type='number' placeholder='0' value='${cValue}' min='0' max='100' step='25' onchange='maintainOneHundredPercent("capex");'>%
				</div>
			</div>
			<div id='advancedAdjustmentSection'>
				<div id='actualsAdvancedAdjustmentSection'>
					<h2>Actuals</h2>
					<div id="actualsAdvancedEdit">
						<select id="actualSelect"></select>
						<div id="baseRow" class="flexRow">
							<label for="baseValue">Base</label>
							<input type="number" name="baseValue" id="baseValue" />
						</div>
						<div id="overtimeRow" class="flexRow">
							<label for="overtimeValue">Overtime</label>
							<input type="number" name="overtimeValue" id="overtimeValue" />
						</div>
						<div id="onCallRow" class="flexRow">
							<label for="onCallValue">On Call</label>
							<input type="number" name="onCallValue" id="onCallValue" />
						</div>
						<div id="bonusRow" class="flexRow">
							<label for="bonusValue">Bonus</label>
							<input type="number" name="bonusValue" id="bonusValue" />
						</div>
						<div id="otherRow" class="flexRow">
							<label for="otherValue">Other</label>
							<input type="number" name="otherValue" id="otherValue" />
						</div>
						<div id="welfareRow" class="flexRow">
							<label for="welfareValue">Welfare</label>
							<input type="number" name="welfareValue" id="welfareValue" />
						</div>
						<div id="pensionRow" class="flexRow">
							<label for="pensionValue">Pension</label>
							<input type="number" name="pensionValue" id="pensionValue" />
						</div>
						<div id="statutoryPayRow" class="flexRow">
							<label for="statutoryPayValue">Statutory Pay</label>
							<input type="number" name="statutoryPayValue" id="statutoryPayValue" />
						</div>
						<div id="employersNIRow" class="flexRow">
							<label for="employersNIValue">Employers NI</label>
							<input type="number" name="employersNIValue" id="employersNIValue" />
						</div>
						<div id="commissionRow" class="flexRow">
							<label for="commissionValue">Commission</label>
							<input type="number" name="commissionValue" id="commissionValue" />
						</div>
						<div id="employeeCostsRow" class="flexRow">
							<label for="employeeCostsValue">Employee Costs</label>
							<input type="number" name="employeeCostsValue" id="employeeCostsValue" />
						</div>
					</div>
				</div>
				<div id='outturnAdvancedAdjustmentSection'>
					<h2>Outturn</h2>
					<div id='outturnAdvancedEdit'>
						<select id='outturnSelect'></select>
						<div id='outturnCategoryMonthly'>
						</div>
					</div>
				</div>
			</div>
		</div>
	`;

	// Create and populate the month selector
	let actualSelect = document.getElementById('actualSelect');

	actualMonths.forEach(month => {
		let option = document.createElement('option');
		option.value = month;
		option.textContent = month;
		actualSelect.appendChild(option);
	});

	// Attach proper event listener for month change
	actualSelect.addEventListener('change', () => updateActualsAdvancedEdit(resource));

	// Populate base field with the first month's value
	let firstMonth = actualSelect.value;
	
	// set each of the variables for the inputs
	baseInput = document.getElementById('baseValue');
	overtimeInput = document.getElementById('overtimeValue');
	onCallInput = document.getElementById('onCallValue');
	bonusInput = document.getElementById('bonusValue');
	otherInput = document.getElementById('otherValue');
	welfareInput = document.getElementById('welfareValue');
	pensionInput = document.getElementById('pensionValue');
	statutoryPayInput = document.getElementById('statutoryPayValue');
	employersNIInput = document.getElementById('employersNIValue');
	commissionInput = document.getElementById('commissionValue');
	employeeCostsInput = document.getElementById('employeeCostsValue');
	
	baseInput.value = (Math.round((resource['actuals'][firstMonth]?.['base'] ?? 0)*100)/100).toFixed(2);
	overtimeInput.value = (Math.round((resource['actuals'][firstMonth]?.['overtime'] ?? 0)*100)/100).toFixed(2);
	onCallInput.value = (Math.round((resource['actuals'][firstMonth]?.['onCall'] ?? 0)*100)/100).toFixed(2);
	bonusInput.value = (Math.round((resource['actuals'][firstMonth]?.['bonus'] ?? 0)*100)/100).toFixed(2);
	otherInput.value = (Math.round((resource['actuals'][firstMonth]?.['other'] ?? 0)*100)/100).toFixed(2);
	welfareInput.value = (Math.round((resource['actuals'][firstMonth]?.['welfare'] ?? 0)*100)/100).toFixed(2);
	pensionInput.value = (Math.round((resource['actuals'][firstMonth]?.['pension'] ?? 0)*100)/100).toFixed(2);
	statutoryPayInput.value = (Math.round((resource['actuals'][firstMonth]?.['statutoryPay'] ?? 0)*100)/100).toFixed(2);
	employersNIInput.value = (Math.round((resource['actuals'][firstMonth]?.['employersNI'] ?? 0)*100)/100).toFixed(2);
	commissionInput.value = (Math.round((resource['actuals'][firstMonth]?.['commission'] ?? 0)*100)/100).toFixed(2);
	employeeCostsInput.value = (Math.round((resource['actuals'][firstMonth]?.['employeeCosts'] ?? 0)*100)/100).toFixed(2);
	
	// Set a listener for each of the fields
	baseInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, baseInput.value, 'base'));
	overtimeInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, overtimeInput.value, 'overtime'));
	onCallInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, onCallInput.value, 'onCall'));
	bonusInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, bonusInput.value, 'bonus'));
	otherInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, otherInput.value, 'other'));
	welfareInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, welfareInput.value, 'welfare'));
	pensionInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, pensionInput.value, 'pension'));
	statutoryPayInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, statutoryPayInput.value, 'statutoryPay'));
	employersNIInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, employersNIInput.value, 'employersNI'));
	commissionInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, commissionInput.value, 'commission'));
	employeeCostsInput.addEventListener('change', () => updateAdvancedActuals(arrayRef, arrayName, employeeCostsInput.value, 'employeeCosts'));
	
	// Create and populate the outturn selector
	let outturnSelect = document.getElementById('outturnSelect');
	
	payTypeGroups.forEach(payTypeGroup =>{
		let option = document.createElement('option');
		option.value = payTypeGroup.value;
		option.textContent = payTypeGroup.type;
		outturnSelect.appendChild(option);
	})
	
	let payType = document.getElementById('outturnSelect').value;
	let outturnContent = document.getElementById('outturnCategoryMonthly');
	let actualMonth = actualMonths[actualMonths.length - 1];
	let indexOfOutturn = outturnMonths.indexOf(actualMonth);
	
	outturnSelect.addEventListener('change', () => {
		payType = outturnSelect.value;
		outturnArrayIndex = 0;
		outturnMonths.forEach(month => {
			if (outturnArrayIndex > indexOfOutturn){
				let value = resource['outturn'][month]?.[payType] ?? 0;
				let rounded = Math.round(value*100)/100;
				let input = document.getElementById(`input_${month}`);
				input.value = rounded.toFixed(2);
			}
			outturnArrayIndex++;
		});
	});
	
	let outturnArrayIndex = 0;
	
	outturnMonths.forEach(month => {
		if (outturnArrayIndex > indexOfOutturn){
			let row = document.createElement('div');
			row.classList.add('flexRow');
			let value = resource['outturn'][month]?.[payType] ?? 0;
			let library = 'roles';
			
			
			if (resource.firstname.length > 0) {
				library = 'lib_resources';
			}
			
			row.innerHTML = `
				<label for='input_${month}'>${month}</label>
				<input type='number' name='input_${month}' id='input_${month}' value='${value}' onchange='updateAdvancedOutturn(${arrayRef}, "${library}", "${month}");'>
			`;
			outturnContent.appendChild(row);
		}
		outturnArrayIndex++;
	})
	
}