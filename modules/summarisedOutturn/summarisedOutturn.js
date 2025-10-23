window.peopleCostsData = {
	actuals: {},
	outturn: {},
	forecast: {}
};

function checkArrayExists(arrayNameAsString, callback) {
	if (typeof window[arrayNameAsString] !== 'undefined' && Array.isArray(window[arrayNameAsString])) {
		callback(window[arrayNameAsString]);
	} else {
		setTimeout(() => {
			checkArrayExists(arrayNameAsString, callback);
		}, 3000);
	}
}


// Chart Helpers
function syncChartHeightToSummary(){
  const table = document.querySelector('#summarisedOutturnView table');
  const card  = document.querySelector('.chart-card');
  if (!table || !card) return;

  const h = table.offsetHeight;               // total table height
  card.style.height = h + 'px';

  // Tell Chart.js to resize to the new container height
  if (window.peopleCostsChartInstance) {
	window.peopleCostsChartInstance.resize();
  }
}

// Debounce resize so we don't spam on window resizes
let _resizeTO;
window.addEventListener('resize', () => {
  clearTimeout(_resizeTO);
  _resizeTO = setTimeout(syncChartHeightToSummary, 150);
});
// -----------

function createSummaryTable(){
	
	// get the number of actual months
	let actualMonthsValue = Number(scrub(getCookie('aMonths')));
	
	// Check if the value is a valid value and, if not, set it to the default
	if(actualMonthsValue == null){
		actualMonthsValue = 7;
		setCookie('aMonths',7);
	}
	
	// get the number of outturn months
	let outturnMonths = Number(scrub(getCookie('oMonths')));
	
	// Check if the value is a valid value and, if not, set it to the default
	if(outturnMonths == null){
		outturnMonths = 6;
		setCookie('oMonths',6);
	}
	
	// get the contract_type
	let contractType = Number(scrub(getCookie('contractType')));
	
	// Check if the value is valid and, if not, set it to the default
	if(contractType == null){
		contractType = 0;
	}
	
	// run monthArray function to populate all of the months necessary
	let selectMonth = document.getElementById('months');
	let offset = selectMonth.value;
	
	let monthArray = [];
	monthArray = generateMonthArray(offset, actualMonthsValue, outturnMonths-1);
	
	// reset chart totals for this build
	window.peopleCostsData = {
	  actuals: {},
	  outturn: {},
	  forecast: {}
	};
	
	checkArrayExists('lib_resources',function(lib_resources){
		let data = lib_resources;
		
		const displayArea = document.getElementById('summarisedOutturnView');
		displayArea.innerHTML = ''; // Clear anything previously in the div
		
		if (data.length === 0 && roles.length === 0) {
			displayArea.innerHTML = '<p>No data available</p>';
			return;
		}
		
		// Create table
		const table = document.createElement('table');
		table.setAttribute('class','monthly-summary-table'); // Add class for styling if needed
		
		// Create header
		const thead = document.createElement('thead');
		thead.setAttribute('id','summaryTableHeader');
		const headerRow = document.createElement('tr');
		let headers = ['Department','Actual','Forecast','Variance'];
		
		monthArray.forEach(month => {
			headers.push(month);
		});
		
		let i = 1;
		let actualMonthSequenceNumber = 4 + Number(actualMonthsValue);
		
		headers.forEach(headerText => {
			const th = document.createElement('th');
			th.textContent = headerText;
			
			// Static Columns
			if (i == 2) {
				th.classList.add('actual');
			} else if (i == 3) {
				th.classList.add('forecast');
			} else if (i == 4) {
				th.classList.add('variance');
			}
			
			// Dynamic Month Columns
			if (i >= 5){
				if (i < actualMonthSequenceNumber) {
					th.classList.add('pMonth');
				} else if (i == actualMonthSequenceNumber) {
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
		tbody.id = 'summaryTableResults';
		
		let cumulativeActual = 0.00;
		let cumulativeForecast = 0.00;
		let cumulativeMonths = [];
		let firstRow = 1;
		
		// Determine which value should be taken from the month
		let payType = document.getElementById('payType').value;
		
		// Determine which month is the user selected month
		let currentMonthSelected = monthArray[actualMonthsValue-1];
		let currentMonthConstant = convertDateToMMMYY(eoMonth());
		
		
		departments.forEach(department => {
			
			
			const tr = document.createElement('tr');
			const tdDepartment = document.createElement('td');
			tdDepartment.textContent = department.department;
			tr.appendChild(tdDepartment);
			
			let depActual = 0.00;
			let depForecast = 0.00;
			let tempForecast = 0.00;
			let cMonthArrayReference = actualMonthsValue - 1;
			
			lib_resources.forEach(resource => {
				if(Number(resource.departmentNumber) == Number(department.ref)){
					if(contractType == 0||resource.contractType == contractType){
						
						// If the month is in the future, use outturn; if is in the current or past, use actual
						if (parseMonthYear(currentMonthSelected) <= parseMonthYear(currentMonthConstant)) {
							// Using optional chaining
							const maybeActual = resource.actuals?.[currentMonthSelected]?.[payType] ?? 0.00;
							
							if(maybeActual !== undefined) {
								depActual = depActual + maybeActual;
							}
							
						} else {
							
							const maybeActual = resource.outturn?.[currentMonthSelected]?.[payType] ?? 0.00;
							
							if(maybeActual !== undefined) {
								depActual = depActual + maybeActual;
							}
							
						}
						
						tempForecast = resource.forecast?.[monthArray[cMonthArrayReference]]?.[payType] ?? 0.00;
						depForecast = depForecast + Number(tempForecast);
						
					}
				}
			});
			
			roles.forEach(role => {
				if(Number(role.department) == Number(department.ref)){
					if((contractType == 0||role.contractType == contractType) && role.filledReference == 0){
						
						// If the month is in the future, use outturn; if is in the current or past, use actual
						if (parseMonthYear(currentMonthSelected) <= parseMonthYear(currentMonthConstant)) {
							// Using optional chaining
							const maybeActual = role.actuals?.[currentMonthSelected]?.[payType] ?? 0.00;
							
							if(maybeActual !== undefined) {
								depActual = depActual + maybeActual;
							}
						} else {
							
							const maybeActual = role.outturn?.[currentMonthSelected]?.[payType] ?? 0.00;
							
							if(maybeActual !== undefined) {
								depActual = depActual + maybeActual;
							}
						}
						
						tempForecast = role.forecast?.[monthArray[cMonthArrayReference]]?.[payType] ?? 0.00;
						depForecast = depForecast + Number(tempForecast);
						
					}
				}
			});
			
			// Populate the Actual column
			let tdThisMonth = document.createElement('td');
			let actual = depActual;
			let cMonthActual = Math.round(actual);
			cMonthActual = cMonthActual.toLocaleString();
			tdThisMonth.textContent = cMonthActual;
			tdThisMonth.classList.add('valueColumn');
			tdThisMonth.classList.add('actual');
			tr.appendChild(tdThisMonth);
			
			// Populate the Forecast column
			let tdForecast = document.createElement('td');
			let forecast = depForecast;
			let cMonthForecast = Number(forecast);
			tdForecast.textContent = Math.round(cMonthForecast).toLocaleString();
			tdForecast.classList.add('valueColumn');
			tdForecast.classList.add('forecast');
			tr.appendChild(tdForecast);
			
			cumulativeActual = Number(cumulativeActual) + Number(depActual);
			cumulativeForecast = Number(cumulativeForecast) + Number(depForecast);
			
			// Populate the Variance column
			let tdVariance = document.createElement('td');
			varianceValue = depForecast - depActual;
			varianceValue = Math.round(varianceValue).toLocaleString();
			tdVariance.textContent = varianceValue;
			tdVariance.classList.add('valueColumn');
			tdVariance.classList.add('variance');
			tr.appendChild(tdVariance);
			
			// run through the monthArray and push each to the row
			
			let counter = -actualMonthsValue + 1;
			i = 5;
			
			monthArray.forEach(month => {
				
				depActual = 0.00;
				depForecast = 0.00;
				let actualsOutturn = 'actuals';
				
				lib_resources.forEach(resource => {
					if(Number(resource.departmentNumber) === Number(department.ref)){
						if(contractType == 0||resource.contractType == contractType){
							
							actualsOutturn = 'actuals';
							
							// If the month is in the future, use outturn; if is in the current or past, use actual
							if (parseMonthYear(month) > parseMonthYear(currentMonthConstant)) {
								actualsOutturn = 'outturn';
							}
							
							let maybeActual = (actualsOutturn !== 'outturn')
								? (resource.actuals?.[month]?.[payType] ?? 0.00)
								: (resource.outturn?.[month]?.[payType] ?? 0.00);
							
							maybeActual = Number(maybeActual);
							
							depActual = depActual + maybeActual;
							
						}
					}
				});
				
				roles.forEach(role => {
					if(role.department == department.ref){
						if((contractType == 0||role.contractType == contractType) && role.filledReference == 0){
							actualsOutturn = 'actuals';
							
							// If the month is in the future, use outturn; if is in the current or past, use actual
							if (parseMonthYear(month) > parseMonthYear(currentMonthConstant)) {
								actualsOutturn = 'outturn';
							}
							
							if(actualsOutturn != 'outturn'){
								maybeActual = role.actuals?.[month]?.[payType] ?? 0.00;
							} else {
								maybeActual = role.outturn?.[month]?.[payType] ?? 0.00;
							}
							
							maybeActual = Number(maybeActual);
							
							depActual = depActual + maybeActual;
							
						}
					}
				});
				
				let td = document.createElement('td');
				let monthValue = depActual;
				monthValue = Math.round(monthValue).toLocaleString();
				td.textContent = monthValue;
				
				if (i == actualMonthSequenceNumber){
					td.classList.add('cMonth');
				}
				
				td.classList.add('valueColumn');
				tr.appendChild(td);
				counter++;
				i++;
				
			});
			
			tbody.appendChild(tr);
			
		});
		
		table.appendChild(tbody);
		
		// Create a tfoot
		let tfoot = document.createElement('tfoot');
		tfoot.setAttribute('id','tableFooter');
		
		const tr = document.createElement('tr');
		
		// Create tds for the left hand side
		let tfDepartment = document.createElement('td');
		tfDepartment.textContent = "Total";
		tr.appendChild(tfDepartment);
		
		let tfActual = document.createElement('td');
		tfActual.textContent = Math.round(Number(cumulativeActual)).toLocaleString();
		tfActual.classList.add('valueColumn');
		tfActual.classList.add('actual');
		tr.appendChild(tfActual);
		
		let tfForecast = document.createElement('td');
		tfForecast.textContent = Math.round(Number(cumulativeForecast)).toLocaleString();
		tfForecast.classList.add('valueColumn');
		tfForecast.classList.add('forecast');
		tr.appendChild(tfForecast);
		
		let tfVariance = document.createElement('td');
		tfVariance.textContent = Math.round(Number(cumulativeForecast) - Number(cumulativeActual)).toLocaleString();
		tfVariance.classList.add('valueColumn');
		tfVariance.classList.add('variance');
		tr.appendChild(tfVariance);
		
		let counter = -actualMonthsValue + 1;
		i = 5;
		
		// Iterate through the months
		monthArray.forEach(month => {
			
			let valueVariable = 0.00;
			let actualsOutturn = 'actuals';
			let maybeActual = '';
			let forecastChartValue = 0.00;
			
			// determine which month is the user selected month
			let currentMonthSelected = month;
			let currentMonthConstant = convertDateToMMMYY(eoMonth());
			
			// Fined out if the month is in the present or the future
			if (parseMonthYear(currentMonthSelected) > parseMonthYear(currentMonthConstant)){
				actualsOutturn = 'outturn';
			}
			
			lib_resources.forEach(resource => {
				if(contractType == 0||resource.contractType == contractType){
					if(actualsOutturn != 'outturn'){
						maybeActual = resource.actuals?.[currentMonthSelected]?.[payType] ?? 0.00;
					} else {
						maybeActual = resource.outturn?.[currentMonthSelected]?.[payType] ?? 0.00;
					}
					
					if (maybeActual !== undefined) {
						valueVariable = Number(valueVariable) + Number(maybeActual);
					} else {
						valueVariable = Number(valueVariable) + Number(0.00);
					}
					
					forecastChartValue = Number(forecastChartValue) + Number(resource.forecast?.[currentMonthSelected]?.[payType] ?? 0.00);
				}
			});
			
			roles.forEach (role =>{
				
				if(role['filledReference'] == '0'){
					if(contractType==0||role.contractType == contractType){
						if(actualsOutturn != 'outturn'){
							maybeActual = role.actuals?.[currentMonthSelected]?.[payType] ?? 0.00;
						} else {
							maybeActual = role.outturn?.[currentMonthSelected]?.[payType] ?? 0.00;
						}
						
						if (maybeActual !== undefined) {
							valueVariable = Number(valueVariable) + Number(maybeActual);
						} else {
							valueVariable = Number(valueVariable) + Number(0.00);
						}
						
						forecastChartValue = Number(forecastChartValue) + Number(role.forecast?.[currentMonthSelected]?.[payType] ?? 0.00);
							
					}
				}
			});
			
			// ‼️ Trying to get charts to work
			if (actualsOutturn === 'actuals') {
			  window.peopleCostsData.actuals[month] = valueVariable;
			} else {
			  window.peopleCostsData.outturn[month] = valueVariable;
			}
			window.peopleCostsData.forecast[month] = forecastChartValue;
			
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
		
		tfoot.appendChild(tr);
		
		table.appendChild(tfoot);
		
		displayArea.appendChild(table);
		
	});
	
	// ⬇️ Call this after the table and peopleCostsData have been built
	updatePeopleCostsChartFromSelection();
	syncChartHeightToSummary();
	
}

// Attach month selector change event
document.addEventListener('DOMContentLoaded', function () {
	const monthsSelect = document.getElementById('months');
	if (monthsSelect) {
		monthsSelect.addEventListener('change', function () {
			createSummaryTable();                  // rebuilds data
			updatePeopleCostsChartFromSelection(); // updates month range for chart
			renderPeopleCostsChart();              // redraws chart
		});
	}
});

createSummaryTable();