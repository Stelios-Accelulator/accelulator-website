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

function createSummaryTable() {
	// 1) actual / outturn month counts
	let actualMonthsValue = Number(scrub(getCookie('aMonths')));
	if (!actualMonthsValue) {
		actualMonthsValue = 7;
		setCookie('aMonths', 7);
	}

	let outturnMonths = Number(scrub(getCookie('oMonths')));
	if (!outturnMonths) {
		outturnMonths = 6;
		setCookie('oMonths', 6);
	}

	// 2) contract type
	let contractType = Number(scrub(getCookie('contractType')));
	if (!contractType) {
		contractType = 0;
	}

	// 3) offset (may not exist yet)
	const selectMonth = document.getElementById('months');
	const offset = selectMonth ? selectMonth.value : 0;

	// 4) build month array
	const monthArray = generateMonthArray(offset, actualMonthsValue, outturnMonths - 1);

	// 5) reset chart totals
	window.peopleCostsData = {
		actuals: {},
		outturn: {},
		forecast: {}
	};

	// 6) make sure we have our main arrays
	const resources = Array.isArray(window.lib_resources) ? window.lib_resources : [];
	const roles     = Array.isArray(window.roles) ? window.roles : [];
	let   deps      = Array.isArray(window.departments) ? window.departments : [];

	const displayArea = document.getElementById('summarisedOutturnView');
	if (!displayArea) {
		// nowhere to render
		return;
	}
	displayArea.innerHTML = '';

	// if we have no departments but we do have people, make a fake one
	if (deps.length === 0) {
		deps = [{ ref: 0, department: 'Unallocated' }];
	}

	// if we truly have no data at all
	if (resources.length === 0 && roles.length === 0) {
		displayArea.innerHTML = '<p>No data available</p>';
		// still try to update chart so it clears
		if (typeof updatePeopleCostsChartFromSelection === 'function') {
			updatePeopleCostsChartFromSelection();
		}
		if (typeof syncChartHeightToSummary === 'function') {
			syncChartHeightToSummary();
		}
		return;
	}

	// 7) pay type (may not exist)
	const payTypeEl = document.getElementById('payType');
	const payType   = payTypeEl ? payTypeEl.value : 'base';

	// 8) current month markers
	const currentMonthSelected = monthArray[actualMonthsValue - 1];
	const currentMonthConstant = convertDateToMMMYY(eoMonth());

	// --- build table ---
	const table = document.createElement('table');
	table.className = 'monthly-summary-table';

	// header
	const thead = document.createElement('thead');
	thead.id = 'summaryTableHeader';
	const headerRow = document.createElement('tr');

	const headers = ['Department', 'Actual', 'Forecast', 'Variance'];
	monthArray.forEach(m => headers.push(m));

	let i = 1;
	const actualMonthSequenceNumber = 4 + Number(actualMonthsValue);

	headers.forEach(text => {
		const th = document.createElement('th');
		th.textContent = text;

		if (i === 2) th.classList.add('actual');
		else if (i === 3) th.classList.add('forecast');
		else if (i === 4) th.classList.add('variance');

		if (i >= 5) {
			if (i < actualMonthSequenceNumber) th.classList.add('pMonth');
			else if (i === actualMonthSequenceNumber) th.classList.add('cMonth');
			else th.classList.add('oMonth');
		}

		headerRow.appendChild(th);
		i++;
	});

	thead.appendChild(headerRow);
	table.appendChild(thead);

	// body
	const tbody = document.createElement('tbody');
	tbody.id = 'summaryTableResults';

	let cumulativeActual   = 0;
	let cumulativeForecast = 0;

	deps.forEach(dep => {
		const tr = document.createElement('tr');

		// dept name
		const tdDepartment = document.createElement('td');
		tdDepartment.textContent = dep.department;
		tr.appendChild(tdDepartment);

		// current month totals for this dept
		let depActual = 0;
		let depForecast = 0;

		// resources
		resources.forEach(r => {
			if (Number(r.departmentNumber) !== Number(dep.ref)) return;
			if (!(contractType === 0 || r.contractType == contractType)) return;

			// actual vs outturn
			if (currentMonthSelected && parseMonthYear(currentMonthSelected) <= parseMonthYear(currentMonthConstant)) {
				depActual += Number(r.actuals?.[currentMonthSelected]?.[payType] ?? 0);
			} else if (currentMonthSelected) {
				depActual += Number(r.outturn?.[currentMonthSelected]?.[payType] ?? 0);
			}

			depForecast += Number(r.forecast?.[currentMonthSelected]?.[payType] ?? 0);
		});

		// roles (vacant only)
		roles.forEach(role => {
			if (Number(role.department) !== Number(dep.ref)) return;
			if (!(contractType === 0 || role.contractType == contractType)) return;
			if (role.filledReference && role.filledReference != 0) return;

			if (currentMonthSelected && parseMonthYear(currentMonthSelected) <= parseMonthYear(currentMonthConstant)) {
				depActual += Number(role.actuals?.[currentMonthSelected]?.[payType] ?? 0);
			} else if (currentMonthSelected) {
				depActual += Number(role.outturn?.[currentMonthSelected]?.[payType] ?? 0);
			}

			depForecast += Number(role.forecast?.[currentMonthSelected]?.[payType] ?? 0);
		});

		// Actual col
		const tdActual = document.createElement('td');
		tdActual.textContent = Math.round(depActual).toLocaleString();
		tdActual.classList.add('valueColumn', 'actual');
		tr.appendChild(tdActual);

		// Forecast col
		const tdForecast = document.createElement('td');
		tdForecast.textContent = Math.round(depForecast).toLocaleString();
		tdForecast.classList.add('valueColumn', 'forecast');
		tr.appendChild(tdForecast);

		cumulativeActual   += depActual;
		cumulativeForecast += depForecast;

		// Variance col
		const tdVariance = document.createElement('td');
		tdVariance.textContent = Math.round(depForecast - depActual).toLocaleString();
		tdVariance.classList.add('valueColumn', 'variance');
		tr.appendChild(tdVariance);

		// month-by-month columns
		let colIdx = 5;
		monthArray.forEach(month => {
			let monthActual = 0;
			let actualsOutturn = 'actuals';

			if (parseMonthYear(month) > parseMonthYear(currentMonthConstant)) {
				actualsOutturn = 'outturn';
			}

			// resources
			resources.forEach(r => {
				if (Number(r.departmentNumber) !== Number(dep.ref)) return;
				if (!(contractType === 0 || r.contractType == contractType)) return;

				const val = actualsOutturn !== 'outturn'
					? Number(r.actuals?.[month]?.[payType] ?? 0)
					: Number(r.outturn?.[month]?.[payType] ?? 0);

				monthActual += val;
			});

			// roles
			roles.forEach(role => {
				if (Number(role.department) !== Number(dep.ref)) return;
				if (!(contractType === 0 || role.contractType == contractType)) return;
				if (role.filledReference && role.filledReference != 0) return;

				const val = actualsOutturn !== 'outturn'
					? Number(role.actuals?.[month]?.[payType] ?? 0)
					: Number(role.outturn?.[month]?.[payType] ?? 0);

				monthActual += val;
			});

			// stash for chart
			if (actualsOutturn === 'actuals') {
				window.peopleCostsData.actuals[month] = (window.peopleCostsData.actuals[month] || 0) + monthActual;
			} else {
				window.peopleCostsData.outturn[month] = (window.peopleCostsData.outturn[month] || 0) + monthActual;
			}
			const monthFc = (resources.reduce((s, r) => {
				if (Number(r.departmentNumber) !== Number(dep.ref)) return s;
				if (!(contractType === 0 || r.contractType == contractType)) return s;
				return s + Number(r.forecast?.[month]?.[payType] ?? 0);
			}, 0)
			+ roles.reduce((s, role) => {
				if (Number(role.department) !== Number(dep.ref)) return s;
				if (!(contractType === 0 || role.contractType == contractType)) return s;
				if (role.filledReference && role.filledReference != 0) return s;
				return s + Number(role.forecast?.[month]?.[payType] ?? 0);
			}, 0));

			window.peopleCostsData.forecast[month] = (window.peopleCostsData.forecast[month] || 0) + monthFc;

			const tdMonth = document.createElement('td');
			tdMonth.textContent = Math.round(monthActual).toLocaleString();
			tdMonth.classList.add('valueColumn');
			if (colIdx === actualMonthSequenceNumber) {
				tdMonth.classList.add('cMonth');
			}
			tr.appendChild(tdMonth);

			colIdx++;
		});

		tbody.appendChild(tr);
	});

	table.appendChild(tbody);

	// footer (totals)
	const tfoot = document.createElement('tfoot');
	tfoot.id = 'tableFooter';

	const trF = document.createElement('tr');

	const tdTotal = document.createElement('td');
	tdTotal.textContent = 'Total';
	trF.appendChild(tdTotal);

	const tdTA = document.createElement('td');
	tdTA.textContent = Math.round(cumulativeActual).toLocaleString();
	tdTA.classList.add('valueColumn', 'actual');
	trF.appendChild(tdTA);

	const tdTF = document.createElement('td');
	tdTF.textContent = Math.round(cumulativeForecast).toLocaleString();
	tdTF.classList.add('valueColumn', 'forecast');
	trF.appendChild(tdTF);

	const tdTV = document.createElement('td');
	tdTV.textContent = Math.round(cumulativeForecast - cumulativeActual).toLocaleString();
	tdTV.classList.add('valueColumn', 'variance');
	trF.appendChild(tdTV);

	// month totals for footer + chart (we already filled peopleCostsData above, so this is mostly display)
	let colIdx = 5;
	monthArray.forEach(month => {
		const val =
			(window.peopleCostsData.actuals[month] || 0) +
			(window.peopleCostsData.outturn[month] || 0);
		const td = document.createElement('td');
		td.textContent = Math.round(val).toLocaleString();
		td.classList.add('valueColumn');
		if (colIdx === actualMonthSequenceNumber) {
			td.classList.add('cMonth');
		}
		trF.appendChild(td);
		colIdx++;
	});

	tfoot.appendChild(trF);
	table.appendChild(tfoot);

	displayArea.appendChild(table);

	// chart hooks – only call if present
	if (typeof updatePeopleCostsChartFromSelection === 'function') {
		updatePeopleCostsChartFromSelection();
	}
	if (typeof syncChartHeightToSummary === 'function') {
		syncChartHeightToSummary();
	}
}

// Attach month selector change event
document.addEventListener('DOMContentLoaded', function () {
	const monthsSelect = document.getElementById('months');
	if (monthsSelect) {
		monthsSelect.addEventListener('change', function () {
			window.createSummaryTable = createSummaryTable;
			createSummaryTable();                  // rebuilds data
			updatePeopleCostsChartFromSelection(); // updates month range for chart
			renderPeopleCostsChart();              // redraws chart
		});
	}
});

createSummaryTable();