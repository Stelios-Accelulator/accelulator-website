// cache built in your step 3 (loadPayRises)
window.risesByResource ||= {}; // { "123": [ {REF,RESOURCE_REF,EFFECTIVE_DATE,RISE_KIND,VALUE,NOTE,APPLIED_FLAG}, ... ] }

function endOfMonth(d){ return new Date(d.getFullYear(), d.getMonth()+1, 0); }
function esc(s=''){ return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function fmtRise(r){
  if (r.RISE_KIND === 'PCT') return `${Number(r.VALUE).toFixed(2)}%`;
  if (r.RISE_KIND === 'ABS') return `£${Number(r.VALUE).toLocaleString()}`;
  return `£${Number(r.VALUE).toLocaleString()} (new)`;
}

// Renders list + wires actions. Call after createResourceMenu and after any add/delete/apply.
async function renderPayRiseRows(resourceRef){
  // ensure latest cache
  if (typeof loadPayRises === 'function') await loadPayRises();

  const wrap = document.getElementById('payRiseRows');
  if (!wrap) return;
  wrap.innerHTML = '';

  const list = (window.risesByResource[String(resourceRef)] || []).slice()
	.sort((a,b)=> a.EFFECTIVE_DATE.localeCompare(b.EFFECTIVE_DATE));

  const todayEom = endOfMonth(new Date());

  list.forEach(r => {
	const overdue = (!Number(r.APPLIED_FLAG)) && (new Date(r.EFFECTIVE_DATE) <= todayEom);
	const row = document.createElement('div');
	row.className = 'flexRow payrise-row' + (overdue ? ' overdue' : '');

	row.innerHTML = `
	  <span class="date">${esc(r.EFFECTIVE_DATE)}</span>
	  <span class="kind">${esc(r.RISE_KIND)}</span>
	  <span class="val">${esc(fmtRise(r))}</span>
	  <span class="note">${esc(r.NOTE || '')}</span>
	  <span class="status">${Number(r.APPLIED_FLAG) ? 'applied' : (overdue ? 'overdue' : 'scheduled')}</span>
	  <button class="pr-apply" data-id="${r.REF}" ${Number(r.APPLIED_FLAG) ? 'disabled':''}>Mark applied</button>
	  <button class="pr-del" data-id="${r.REF}">Delete</button>
	`;

	wrap.appendChild(row);
  });

  // Add action handlers (event delegation)
  wrap.onclick = async (e) => {
	const id = e.target?.dataset?.id;
	if (!id) return;

	const headers = { 'Content-Type':'application/json', 'X-CSRF-Token': window.csrfToken };
	if (e.target.classList.contains('pr-del')) {
	  await fetch('/scripts/deletePayRise.php', { method:'POST', headers, body: JSON.stringify({ id }) });
	} else if (e.target.classList.contains('pr-apply')) {
	  await fetch('/scripts/markPayRiseApplied.php', { method:'POST', headers, body: JSON.stringify({ id }) });
	}
	await loadPayRises();
	renderPayRiseRows(resourceRef);
	// Recompute tables with new flags applied
	loadMonthlyOutturn();
  };

  // “Add pay rise” button
  const addBtn = document.getElementById('pr_add');
  if (addBtn) addBtn.onclick = async () => {
	const payload = {
	  resourceRef,
	  effectiveDate: document.getElementById('pr_date').value,
	  kind: document.getElementById('pr_kind').value,
	  value: Number(scrub(document.getElementById('pr_value').value)),
	  note: document.getElementById('pr_note').value
	};
	const headers = { 'Content-Type':'application/json', 'X-CSRF-Token': window.csrfToken };
	await fetch('/scripts/upsertPayRise.php', { method:'POST', headers, body: JSON.stringify(payload) });
	await loadPayRises();
	renderPayRiseRows(resourceRef);
	loadMonthlyOutturn();
  };
}

/** Clear current state (same globals you already use) */
function clearMonthlyOutturnState() {
	const resetArr = (key) => {
		if (Array.isArray(window[key])) {
			window[key].length = 0;        // mutate existing array
		} else {
			window[key] = [];              // first run / safety
		}
	};

	resetArr('employeeLibrary');
	resetArr('lib_resources');
	resetArr('objects');
	resetArr('departments');
	resetArr('forecasts');
	resetArr('userOutturn');
	resetArr('niBands');
	resetArr('niBandLookup');
	resetArr('roles');

	// Remove old resource_* globals so no stale rows linger
	Object.keys(window).forEach(k => {
		if (k.startsWith('resource_')) {
			try { delete window[k]; } catch (_) {}
		}
	});
}

function renderMonthlyOutturn() { // Recreate the page
	try {
		applyRolesToEmployees();
		applyDepartments();
		allocateForecast();
		allocateRoles();
		populateForecastOptions();
		createTable();
		createSummaryTable();
	} catch (err) {
		console.error('[monthlyOutturn] render error:', err);
	}
}

/** Hydrate the JS arrays from API data */
function hydrateMonthlyOutturn(data) {
  // resources
	
	data.resources.forEach((r, idx) => {
		const res = new Resource(
			String(r.id), 
			'Unallocated',
			r.first.replace(/'/g,"\\'"), 
			r.last.replace(/'/g,"\\'"),
			r.start, 
			r.end,
			String(r.salary), 
			String(r.fte), 
			String(r.pension),
			String(idx), 
			String(r.department), 
			String(r.contractType)
		);
		
		window.lib_resources.push(res);         // <— was lib_resources.push
		window['resource_' + r.id] = res;       // keep legacy access for existing funcs
	});
	
	// roles
	
	
	data.roles.forEach((r, i) => {
		const role = new Role(
			r.id, (r.jobTitle || '').replace(/'/g,"\\'"),
			r.department, r.filledRef, r.status, r.benchFte,
			r.benchSalary, r.benchProrataSalary, r.start, r.end,
			r.contractType, r.pensionRate, i
		);
		window.roles.push(role);                // <— use window.roles
	});
	
	// departments
	data.departments.forEach((d) => {
		window.departments.push(new Department(d.id, (d.name || '').replace(/'/g,"\\'")));
	});
	
	// ---- REPLACE this whole "actuals" block ----
	// ---- ACTUALS (bucket per month + running totalCosts), replaces previous block
	data.actuals.forEach(a => {
		
		// set the resource to resource_x
		const res = window['resource_' + a.emp];
		if (!res) return;
		
		const month = a.date;
		const type = a.type;
		
		// Write the value (accumulate in case DB returns multiple rows same month/key)
		const val = Number(a.val) || 0;
		
		populateResourceActuals(res,month,type,val);
		
	});
	
	// forecasts
	data.forecasts.forEach((f, x) => {
		window.forecasts.push(new ForecastList(x, f.af, f.name, f.ver));
	});
	
	// NI
	
	
	data.ni.forEach(b => {
		window.niBands.push({
			FROM_DATE: b.from, TO_DATE: b.to,
			SECONDARY_THRESHOLD_MONTHLY: b.threshold, RATE: b.rate
		});
		const y = new Date(b.from).getFullYear();
		window.niBandLookup[y] = window.niBands[window.niBands.length - 1];
	});
}

// ensure these are globally callable if needed
window.loadMonthlyOutturn   = loadMonthlyOutturn;
window.renderMonthlyOutturn = renderMonthlyOutturn;

/** Main loader – call this any time you need a refresh */
async function loadMonthlyOutturn() {
	// cancel any in-flight runs if user clicks fast
	if (loadMonthlyOutturn._inflight) loadMonthlyOutturn._inflight.abort?.();
	const ctrl = new AbortController();
	loadMonthlyOutturn._inflight = ctrl;

	try {
		// Find the department selector safely (support both id/name variants)
		const depEl = document.getElementById('departmentDisplaySelector');
		const dep   = depEl ? depEl.value : (getCookie('department') || 0);
		setCookie('department', dep);

		clearMonthlyOutturnState();

		const res = await fetch('/scripts/getMonthlyOutturnData.php', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			body: JSON.stringify({ dep }),
			signal: ctrl.signal
		});
		if (!res.ok) throw new Error(await res.text());
		const data = await res.json();
		if (!data.ok) throw new Error(data.error || 'Unknown error');
		
		hydrateMonthlyOutturn(data);
		renderMonthlyOutturn(); // this calls createTable() for you
	} catch (e) {
		console.error('[monthlyOutturn] reload failed:', e);
	} finally {
		if (loadMonthlyOutturn._inflight === ctrl) loadMonthlyOutturn._inflight = null;
	}
}

/** Hook up department dropdown (delegated so it works if the toolbar is injected later) */
document.addEventListener('change', (e) => {
	const sel = e.target && e.target.id === 'departmentDisplaySelector'
						 ? e.target
						 : null;
	if (!sel) return;

	// When you're ready:
	setCookie('department', sel.value);
	loadMonthlyOutturn();
});

// Optional: export for other modules to call after an edit/save:
window.reloadMonthlyOutturn = loadMonthlyOutturn;

function createTable() {
	// 0. hydrate outturns the way your old code did
	if (typeof populateResourceOutturn === 'function') {
		try {
			populateResourceOutturn(); // Populates each of the lib_resources and roles items with outturn values
		} catch (e) {
			console.warn('[createTable] populateResourceOutturn failed:', e);
		}
	}

	// make sure we have arrays
	if (!Array.isArray(lib_resources)) lib_resources = [];
	if (!Array.isArray(roles)) roles = [];

	// get actual/outturn month counts from cookies
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

	// contract type filter
	let contractType = Number(scrub(getCookie('contractType')));
	if (!contractType) contractType = 0;

	// selected month offset (may not exist)
	let offset = 0;
	const selectedMonthEl = document.getElementById('months');
	if (selectedMonthEl && selectedMonthEl.value !== undefined) {
		offset = selectedMonthEl.value;
	}

	// run monthArray function to populate all of the months necessary
	let monthArray = generateMonthArray(offset, actualMonthsValue, outturnMonths - 1);

	const displayArea = document.getElementById('monthlyOutturnView');
	if (!displayArea) {
		console.warn('[createTable] #monthlyOutturnView missing – nothing to render into');
		return;
	}
	displayArea.innerHTML = '';

	// if literally nothing to show
	if (lib_resources.length === 0 && roles.length === 0) {
		displayArea.innerHTML = '<p>No data available.</p>';
		return;
	}

	// pay type (may not exist yet)
	const payTypeElement = document.getElementById('payType');
	const payTypeValue = payTypeElement ? payTypeElement.value : 'base';

	// Create table
	const table = document.createElement('table');
	table.setAttribute('class', 'monthly-outturn-table');

	// Create header
	const thead = document.createElement('thead');
	thead.setAttribute('id', 'tableHeader');
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

	// ========= RESOURCES =========
	lib_resources.forEach(resource => {

		if (contractType !== 0 && resource.contractType != contractType) return;

		const formattedStartDate = resource.start_date
			? new Date(resource.start_date).toLocaleDateString('en-GB')
			: '';

		let correctedEndDate;
		if (resource.end_date == '9999-12-31' || resource.end_date == null) {
			correctedEndDate = 'n/a';
		} else {
			correctedEndDate = new Date(resource.end_date).toLocaleDateString('en-GB');
		}

		const tr = document.createElement('tr');

		// Overdue pay rise marker
		if (window.risesByResource?.[String(resource.ref)]) {
			const todayEom = endOfMonth(new Date());
			const overdue = window.risesByResource[String(resource.ref)].some(
				r => !Number(r.APPLIED_FLAG) && new Date(r.EFFECTIVE_DATE) <= todayEom
			);
			if (overdue) {
				tr.classList.add('has-overdue-rise');
			}
		}

		// radio
		const tdRef = document.createElement('td');
		tdRef.innerHTML = `<input type='radio' id='record${resource.ref}' name='recordSelect' value='record${resource.ref}' onclick='createResourceMenu(${resource.rowNumber},"resources");'>`;
		tr.appendChild(tdRef);

		// name
		const tdName = document.createElement('td');
		tdName.textContent = (resource.firstname || '') + " " + (resource.surname || '');
		tr.appendChild(tdName);

		// job title
		const tdJobTitle = document.createElement('td');
		tdJobTitle.textContent = resource.jobTitle || '';
		tr.appendChild(tdJobTitle);

		// start
		const tdStart = document.createElement('td');
		tdStart.textContent = formattedStartDate;
		tr.appendChild(tdStart);

		// end
		const tdEnd = document.createElement('td');
		tdEnd.textContent = correctedEndDate;
		tr.appendChild(tdEnd);

		// salary
		const tdSalary = document.createElement('td');
		tdSalary.textContent = Math.round(Number(resource.annual_salary || 0)).toLocaleString();
		tdSalary.classList.add('valueColumn');
		tr.appendChild(tdSalary);

		// fte
		const tdFTE = document.createElement('td');
		tdFTE.textContent = Number(resource.fte || 0).toLocaleString();
		tdFTE.classList.add('valueColumn');
		tr.appendChild(tdFTE);

		// dept
		const tdDept = document.createElement('td');
		tdDept.textContent = resource.departmentName || 'Unallocated';
		tr.appendChild(tdDept);

		// determine “current” month
		const currentMonthSelected = monthArray[actualMonthsValue - 1];
		const currentMonthConstant = convertDateToMMMYY(eoMonth());

		// ACTUAL column (single value)
		let actualColumnValue = 0.00;
		if (currentMonthSelected && parseMonthYear(currentMonthSelected) <= parseMonthYear(currentMonthConstant)) {
			const maybeActual = resource.actuals?.[currentMonthSelected]?.[payTypeValue];
			actualColumnValue = maybeActual !== undefined ? Number(maybeActual) : 0.00;
		} else if (currentMonthSelected) {
			// 👇 this is where your outturns matter
			const maybeOutturn = resource.outturn?.[currentMonthSelected]?.[payTypeValue];
			actualColumnValue = maybeOutturn !== undefined ? Number(maybeOutturn) : 0.00;
		}

		const tdThisMonth = document.createElement('td');
		tdThisMonth.textContent = Math.round(actualColumnValue).toLocaleString();
		tdThisMonth.classList.add('valueColumn','actual');
		tr.appendChild(tdThisMonth);
		cumulativeActual += actualColumnValue;

		// FORECAST column
		const monthKeyForForecast = monthArray[actualMonthsValue - 1];
		let forecastValue = 0.00;
		if (monthKeyForForecast) {
			forecastValue = Number(resource.forecast?.[monthKeyForForecast]?.[payTypeValue] || 0);
		}
		const tdForecast = document.createElement('td');
		tdForecast.textContent = Math.round(forecastValue).toLocaleString();
		tdForecast.classList.add('valueColumn','forecast');
		tr.appendChild(tdForecast);
		cumulativeForecast += forecastValue;

		// VARIANCE
		const tdVariance = document.createElement('td');
		tdVariance.textContent = Math.round(forecastValue - actualColumnValue).toLocaleString();
		tdVariance.classList.add('valueColumn','variance');
		tr.appendChild(tdVariance);

		// month-by-month cells
		let colIdx = 12;
		monthArray.forEach(month => {
			let valueVariable = 0.00;
			let actualsOutturn = 'actuals';
			const currentMonthConstant2 = convertDateToMMMYY(eoMonth());

			if (parseMonthYear(month) > parseMonthYear(currentMonthConstant2)) {
				actualsOutturn = 'outturn';
			}

			let maybe = 0;
			if (actualsOutturn !== 'outturn') {
				maybe = resource.actuals?.[month]?.[payTypeValue];
			} else {
				maybe = resource.outturn?.[month]?.[payTypeValue];
			}
			valueVariable += Number(maybe || 0);

			const tdActual = document.createElement('td');
			tdActual.textContent = Math.round(valueVariable).toLocaleString();
			if (colIdx == actualMonthSequenceNumber) {
				tdActual.classList.add('cMonth');
			}
			tdActual.classList.add('valueColumn');
			tr.appendChild(tdActual);

			colIdx++;
		});

		tbody.appendChild(tr);
	});

	// ========= ROLES (vacant) =========
	if (Array.isArray(roles) && roles.length > 0) {
		roles.forEach(role => {
			if (contractType !== 0 && role.contractType != contractType) return;
			if (role.filledReference != 0) return;

			const tr = document.createElement('tr');

			const formattedStartDate = role.startDate
				? new Date(role.startDate).toLocaleDateString('en-GB')
				: '';

			let correctedEndDate;
			if (role.end_date == '9999-12-31' || role.end_date == null) {
				correctedEndDate = 'n/a';
			} else {
				correctedEndDate = new Date(role.end_date).toLocaleDateString('en-GB');
			}

			const tdRef = document.createElement('td');
			tdRef.innerHTML = `<input type='radio' id='record${role.ref}' name='recordSelect' value='record${role.ref}' onclick='createResourceMenu(${role.tableRef},"role");'>`;
			tr.appendChild(tdRef);

			const tdName = document.createElement('td');
			tdName.textContent = 'Vacant';
			tr.appendChild(tdName);

			const tdJobTitle = document.createElement('td');
			tdJobTitle.textContent = role.jobTitle || '';
			tr.appendChild(tdJobTitle);

			const tdStart = document.createElement('td');
			tdStart.textContent = formattedStartDate;
			tr.appendChild(tdStart);

			const tdEnd = document.createElement('td');
			tdEnd.textContent = correctedEndDate;
			tr.appendChild(tdEnd);

			const tdSalary = document.createElement('td');
			tdSalary.textContent = Math.round(Number(role.benchmarkSalary || 0)).toLocaleString();
			tdSalary.classList.add('valueColumn');
			tr.appendChild(tdSalary);

			const tdFTE = document.createElement('td');
			tdFTE.textContent = Number(role.benchmarkFTE || 0).toLocaleString();
			tdFTE.classList.add('valueColumn');
			tr.appendChild(tdFTE);

			const tdDept = document.createElement('td');
			tdDept.textContent = role.departmentName || 'Unallocated';
			tr.appendChild(tdDept);

			// current month
			const currentMonthSelected = monthArray[actualMonthsValue - 1];
			const currentMonthConstant = convertDateToMMMYY(eoMonth());

			let actualColumnValue = 0.00;
			if (currentMonthSelected && parseMonthYear(currentMonthSelected) <= parseMonthYear(currentMonthConstant)) {
				const maybeActual = role.actuals?.[currentMonthSelected]?.[payTypeValue];
				actualColumnValue = maybeActual !== undefined ? Number(maybeActual) : 0.00;
			} else if (currentMonthSelected) {
				const maybeOutturn = role.outturn?.[currentMonthSelected]?.[payTypeValue];
				actualColumnValue = maybeOutturn !== undefined ? Number(maybeOutturn) : 0.00;
			}

			const tdThisMonth = document.createElement('td');
			tdThisMonth.textContent = Math.round(actualColumnValue).toLocaleString();
			tdThisMonth.classList.add('valueColumn','actual');
			tr.appendChild(tdThisMonth);
			cumulativeActual += actualColumnValue;

			const monthKeyForForecast = monthArray[actualMonthsValue - 1];
			let forecastValue = 0.00;
			if (monthKeyForForecast) {
				forecastValue = Number(role.forecast?.[monthKeyForForecast]?.[payTypeValue] || 0);
			}
			const tdForecast = document.createElement('td');
			tdForecast.textContent = Math.round(forecastValue).toLocaleString();
			tdForecast.classList.add('valueColumn','forecast');
			tr.appendChild(tdForecast);
			cumulativeForecast += forecastValue;

			const tdVariance = document.createElement('td');
			tdVariance.textContent = Math.round(forecastValue - actualColumnValue).toLocaleString();
			tdVariance.classList.add('valueColumn','variance');
			tr.appendChild(tdVariance);

			// month columns
			let colIdx = 12;
			monthArray.forEach(month => {
				let valueVariable = 0.00;
				let actualsOutturn = 'actuals';
				const currentMonthConstant2 = convertDateToMMMYY(eoMonth());

				if (parseMonthYear(month) > parseMonthYear(currentMonthConstant2)) {
					actualsOutturn = 'outturn';
				}

				let maybe = 0;
				if (actualsOutturn !== 'outturn') {
					maybe = role.actuals?.[month]?.[payTypeValue];
				} else {
					maybe = role.outturn?.[month]?.[payTypeValue];
				}
				valueVariable += Number(maybe || 0);

				const tdActual = document.createElement('td');
				tdActual.textContent = Math.round(valueVariable).toLocaleString();
				if (colIdx == actualMonthSequenceNumber) {
					tdActual.classList.add('cMonth');
				}
				tdActual.classList.add('valueColumn');
				tr.appendChild(tdActual);

				colIdx++;
			});

			tbody.appendChild(tr);
		});
	}

	table.appendChild(tbody);

	// ===== FOOTER =====
	let tfoot = document.createElement('tfoot');
	tfoot.setAttribute('id','tableFooter');

	const tr = document.createElement('tr');

	// left cells
	const lhs = ['', 'Total', '', '', '', '', '', ''];
	lhs.forEach(txt => {
		const td = document.createElement('td');
		td.innerHTML = txt;
		tr.appendChild(td);
	});

	// totals
	let tfActual = document.createElement('td');
	tfActual.innerHTML = Math.round(cumulativeActual).toLocaleString();
	tfActual.classList.add('valueColumn','actual');
	tr.appendChild(tfActual);

	let tfForecast = document.createElement('td');
	tfForecast.innerHTML = Math.round(cumulativeForecast).toLocaleString();
	tfForecast.classList.add('valueColumn','forecast');
	tr.appendChild(tfForecast);

	let tfVariance = document.createElement('td');
	tfVariance.innerHTML = Math.round(cumulativeForecast - cumulativeActual).toLocaleString();
	tfVariance.classList.add('valueColumn','variance');
	tr.appendChild(tfVariance);

	// month totals
	let colIdx = 12;
	const currentMonthConstant3 = convertDateToMMMYY(eoMonth());
	monthArray.forEach(month => {
		let valueVariable = 0.00;
		let actualsOutturn = 'actuals';

		if (parseMonthYear(month) > parseMonthYear(currentMonthConstant3)) {
			actualsOutturn = 'outturn';
		}

		// resources
		lib_resources.forEach(resource => {
			if (contractType !== 0 && resource.contractType != contractType) return;
			let maybe = 0;
			if (actualsOutturn !== 'outturn') {
				maybe = resource.actuals?.[month]?.[payTypeValue];
			} else {
				maybe = resource.outturn?.[month]?.[payTypeValue];
			}
			valueVariable += Number(maybe || 0);
		});

		// vacant roles
		if (Array.isArray(roles)) {
			roles.forEach(role => {
				if (role.filledReference != 0) return;
				if (contractType !== 0 && role.contractType != contractType) return;

				let maybe = 0;
				if (actualsOutturn !== 'outturn') {
					maybe = role.actuals?.[month]?.[payTypeValue];
				} else {
					maybe = role.outturn?.[month]?.[payTypeValue];
				}
				valueVariable += Number(maybe || 0);
			});
		}

		let tfMonth = document.createElement('td');
		tfMonth.innerHTML = Math.round(valueVariable).toLocaleString();
		if (colIdx === actualMonthSequenceNumber) tfMonth.classList.add('cMonth');
		tfMonth.classList.add('valueColumn');
		tr.appendChild(tfMonth);

		colIdx++;
	});

	tfoot.appendChild(tr);
	table.appendChild(tfoot);

	displayArea.appendChild(table);
}


function allocateRoles() { // ‼️ Chat GPT Generated
  // if we don't have resources, nothing to map onto
  if (!Array.isArray(lib_resources) || lib_resources.length === 0) {
	return;
  }

  // no roles? just make sure resources have *some* jobTitle field
  if (!Array.isArray(roles) || roles.length === 0) {
	lib_resources.forEach(function (res) {
	  if (!res) return;
	  if (typeof res.jobTitle === 'undefined' || res.jobTitle === null) {
		res.jobTitle = '';
	  }
	});
	return;
  }

  // normal path
  roles.forEach(function (role) {
	if (!role) return;

	// attach to matching resource
	lib_resources.forEach(function (res) {
	  if (!res) return;
	  if (role.filledReference == res.ref) {
		res.jobTitle = role.jobTitle || '';
	  }
	});

	// give the role itself something sensible
	if (role.jobTitle == null) {
	  role.jobTitle = 'Unallocated';
	}
  });
}

function allocateForecast() { // ‼️ Chat GPT Generated
  // if there's literally no forecast data, just reset and bail
  if (!window.forecastRows || typeof window.forecastRows !== 'object' || Object.keys(window.forecastRows).length === 0) {
	// still clear existing forecast so stale data doesn’t show
	if (Array.isArray(lib_resources)) {
	  for (const r of lib_resources) r.forecast = {};
	}
	if (Array.isArray(roles)) {
	  for (const rl of roles) rl.forecast = {};
	}
	return;
  }

  // if employee/role arrays aren't there yet, also bail
  if (!Array.isArray(lib_resources)) lib_resources = [];
  if (!Array.isArray(roles))        roles        = [];

  // reset previous forecast so we don't add to it on subsequent loads/selections
  for (const r of lib_resources) r.forecast = {};
  for (const rl of roles)        rl.forecast = {};

  // map incoming keys to our canonical property names
  const toKey = (t) => {
	const s = String(t || '').toLowerCase().trim();
	switch (s) {
	  case 'base': return 'base';
	  case 'overtime': return 'overtime';
	  case 'on call':
	  case 'oncall': return 'onCall';
	  case 'bonus': return 'bonus';
	  case 'other': return 'other';
	  case 'welfare': return 'welfare';
	  case 'pension': return 'pension';
	  case 'statutory pay':
	  case 'statutorypay': return 'statutoryPay';
	  case "employer's ni":
	  case 'employers ni':
	  case 'employersni': return 'employersNI';
	  case 'commission': return 'commission';
	  case 'employee costs':
	  case 'employeecosts': return 'employeeCosts';
	  case 'paye': return 'paye';
	  case 'totalcosts': return 'totalCosts';
	  default: return null;
	}
  };

  // ensure the per-month forecast object has all expected keys
  const ensureBucket = (obj, month) => {
	if (!obj.forecast) obj.forecast = {};
	if (!obj.forecast[month]) {
	  obj.forecast[month] = {
		totalCosts: 0,
		base: 0, overtime: 0, onCall: 0, bonus: 0, other: 0,
		welfare: 0, pension: 0, statutoryPay: 0,
		employersNI: 0, commission: 0, employeeCosts: 0,
		type: 'forecast'
	  };
	}
	return obj.forecast[month];
  };

  const recomputeTotalCosts = (bucket) => {
	const inc = ['base','overtime','onCall','bonus','other','welfare','pension','employersNI','commission'];
	bucket.totalCosts = inc.reduce((s, k) => s + (Number(bucket[k]) || 0), 0);
  };

  // main merge
  for (const type in window.forecastRows) {
	const dataBlock = window.forecastRows[type];
	if (!dataBlock || typeof dataBlock !== 'object') continue;

	for (const reference in dataBlock) {
	  const refNum = Number(reference);

	  // match the resource/role
	  let match = null;
	  if (type === 'resource' || type === 'resources') {
		match = lib_resources.find(r => Number(r.ref) === refNum);
	  } else {
		// default to roles for anything else (e.g. 'role', 'roles')
		match = roles.find(r => Number(r.ref) === refNum);
	  }

	  if (!match) continue;

	  const monthsObj = dataBlock[reference];
	  if (!monthsObj || typeof monthsObj !== 'object') continue;

	  // merge months into the object with computed totalCosts
	  for (const month in monthsObj) {
		const bucket = ensureBucket(match, month);
		const src    = monthsObj[month];
		if (!src || typeof src !== 'object') continue;

		for (const k in src) {
		  const key = toKey(k);
		  const val = Number(src[k]) || 0;
		  if (key && key in bucket) {
			bucket[key] += val; // accumulate
		  }
		}

		recomputeTotalCosts(bucket);
	  }
	}
  }
}

function applyDepartments() { // ‼️ Chat GPT Generated
  // if we don't have departments, just label everything "Unallocated" and bail
  if (!Array.isArray(departments) || departments.length === 0) {
	if (Array.isArray(lib_resources)) {
	  lib_resources.forEach(r => {
		if (!r) return;
		r.departmentName = r.departmentName || 'Unallocated';
	  });
	}
	if (Array.isArray(roles)) {
	  roles.forEach(r => {
		if (!r) return;
		r.departmentName = r.departmentName || 'Unallocated';
	  });
	}
	return;
  }

  // we do have departments
  if (Array.isArray(lib_resources)) {
	lib_resources.forEach(resource => {
	  if (!resource) return;

	  // try to match
	  const dep = departments.find(d => d && d.ref == resource.departmentNumber);
	  if (dep) {
		resource.departmentName = dep.department;
	  } else {
		resource.departmentName = resource.departmentName || 'Unallocated';
	  }
	});
  }

  if (Array.isArray(roles)) {
	roles.forEach(role => {
	  if (!role) return;

	  const dep = departments.find(d => d && d.ref == role.department);
	  if (dep) {
		role.departmentName = dep.department;
	  } else {
		role.departmentName = role.departmentName || 'Unallocated';
	  }
	});
  }
}

function deselectRadioButton(ref){
  const el = document.getElementById('record' + ref);
  if (el) el.checked = false;
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
	let resourceObject = {};
	
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
		resourceObject = lib_resources[x];
		
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
		resourceObject = roles[x];
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
	let resourceMenu = document.createElement('div');
	resourceMenu.id = "menuContainer";
	
	// Create the menuHeader
	let menuHeader = document.createElement('div');
	menuHeader.classList.add('menuHeader');
	
	let strongString = document.createElement('strong');
	strongString.textContent = 'Update Menu';
	
	
	let closeButton = document.createElement('button');
	closeButton.textContent = 'X';
	closeButton.addEventListener(
		"click",() => {
			destroyMenu("menuContainer"),
			deselectRadioButton(y)
		}
	);
	
	menuHeader.appendChild(strongString);
	menuHeader.appendChild(closeButton);
	
	// Badge if any overdue rises exist for this resource
	const badge = document.createElement('span');
	badge.className = 'pill';
	if (window.risesByResource?.[String(y)]) {
	  const todayEom = endOfMonth(new Date());
	  const hasOverdue = window.risesByResource[String(y)].some(
		r => !Number(r.APPLIED_FLAG) && new Date(r.EFFECTIVE_DATE) <= todayEom
	  );
	  if (hasOverdue) {
		badge.textContent = 'Pay rise overdue';
		badge.style.marginLeft = '8px';
		badge.style.background = '#ffe3e3';
		badge.style.border = '1px solid #e00';
		badge.style.padding = '2px 6px';
		badge.style.borderRadius = '999px';
		menuHeader.appendChild(badge);
	  }
	}
	
	// Create the Name (+ Job Title) row
	let nameRow = document.createElement('div');
	nameRow.classList.add('menuRow');
	nameRow.textContent = `${name} (${jobTitle})`;
	
	// Create the Contract Type row
	let contractTypeRow = document.createElement('div');
	contractTypeRow.classList.add('menuRow');
	let contractTypeLabel = document.createElement('label');
	contractTypeLabel.for = 'contractTypeSelector';
	contractTypeLabel.textContent = 'Contract Type';
	contractTypeRow.appendChild(contractTypeLabel);
	let contractTypeSelect = document.createElement('select');
	contractTypeSelect.name = 'contractTypeSelector';
	contractTypeSelect.id = 'contract_type';
	contractTypeSelect.innerHTML = contractTypeSelector;
	contractTypeRow.appendChild(contractTypeSelect);
	
	// Create the Department row
	let departmentRow = document.createElement('div');
	departmentRow.classList.add('menuRow');
	let departmentLabel = document.createElement('label');
	departmentLabel.for = 'departmentSelector';
	departmentLabel.textContent = 'Department';
	departmentRow.appendChild(departmentLabel);
	let departmentSelect = document.createElement('select');
	departmentSelect.name = 'departmentSelector';
	departmentSelect.id = 'department';
	departmentSelect.innerHTML = departmentSelector;
	departmentRow.appendChild(departmentSelect);
	
	// Create the Start Date row
	let startDateRow = document.createElement('div');
	startDateRow.classList.add('menuRow');
	let startDateLabel = document.createElement('label');
	startDateLabel.for = 'startDateInput';
	startDateLabel.textContent = 'Start Date';
	startDateRow.appendChild(startDateLabel);
	let startDateInput = document.createElement('input');
	startDateInput.name = 'startDateInput';
	startDateInput.id = 'start_date';
	startDateInput.type = 'date';
	startDateInput.value = startDate;
	startDateRow.appendChild(startDateInput);
	
	// Create the End Date row
	let endDateRow = document.createElement('div');
	endDateRow.classList.add('menuRow');
	let endDateLabel = document.createElement('label');
	endDateLabel.for = 'endDateInput';
	endDateLabel.textContent = 'End Date';
	endDateRow.appendChild(endDateLabel);
	let endDateInput = document.createElement('input');
	endDateInput.name = 'endDateInput';
	endDateInput.id = 'end_date';
	endDateInput.type = 'date';
	endDateInput.value = endDate;
	endDateRow.appendChild(endDateInput);
	
	// Create the Annual Salary row
	let annualSalaryRow = document.createElement('div');
	annualSalaryRow.classList.add('menuRow');
	let annualSalaryLabel = document.createElement('label');
	annualSalaryLabel.for = 'annualSalaryInput';
	annualSalaryLabel.textContent = 'Annual Salary';
	annualSalaryRow.appendChild(annualSalaryLabel);
	let annualSalaryInput = document.createElement('input');
	annualSalaryInput.name = 'annualSalaryInput';
	annualSalaryInput.id = 'annual_salary';
	annualSalaryInput.type = 'number';
	annualSalaryInput.value = annualSalary;
	annualSalaryRow.appendChild(annualSalaryInput);
	
	// Create the FTE row
	let fteRow = document.createElement('div');
	fteRow.classList.add('menuRow');
	let fteLabel = document.createElement('label');
	fteLabel.for = 'fte';
	fteLabel.textContent = 'Full Time Equivalent';
	fteRow.appendChild(fteLabel);
	let fteInput = document.createElement('input');
	fteInput.name = 'fte';
	fteInput.id = 'fte';
	fteInput.type = 'number';
	fteInput.value = fte;
	fteRow.appendChild(fteInput);
	
	// y is the DB ref for the resource/role already set above
	renderPayRiseRows(y);
	
	// Create the button group
	let buttonRow = document.createElement('div');
	buttonRow.classList.add('buttonGroup');
	
	let advancedEditButton = document.createElement('button');
	advancedEditButton.id = 'advancedEdit';
	advancedEditButton.addEventListener("click",() => {advancedEmployeeEdit(resourceObject, x, arrayName, y, type)});
	advancedEditButton.textContent = 'Advanced Edit';
	buttonRow.appendChild(advancedEditButton);
	
	let createPayChangeMenuButton = document.createElement('button');
	createPayChangeMenuButton.id = 'createPayChangeMenu';
	createPayChangeMenuButton.addEventListener("click",() => {payChangeMenu(resourceObject, x, arrayName, y, type)});
	createPayChangeMenuButton.textContent = 'Schedule Pay Change';
	
	if(type==='resources'){
		buttonRow.appendChild(createPayChangeMenuButton);
	}
	
	
	let saveButton = document.createElement('button');
	saveButton.id = 'saveEmployeeChanges';
	saveButton.addEventListener("click",()=>updateEmployee(y,type));
	saveButton.textContent = 'Save';
	buttonRow.appendChild(saveButton);
	
	// Create the Array Reference
	let hiddenInput = document.createElement('div');
	hiddenInput.type = 'hidden';
	hiddenInput.id = 'arrayRef';
	hiddenInput.value = x;
	
	resourceMenu.appendChild(menuHeader);
	resourceMenu.appendChild(nameRow);
	resourceMenu.appendChild(contractTypeRow);
	resourceMenu.appendChild(departmentRow);
	resourceMenu.appendChild(startDateRow);
	resourceMenu.appendChild(endDateRow);
	resourceMenu.appendChild(annualSalaryRow);
	resourceMenu.appendChild(fteRow);
	resourceMenu.appendChild(buttonRow);
	resourceMenu.appendChild(hiddenInput);
	contentView.appendChild(resourceMenu);
	
	makeDraggable(resourceMenu);
}

function maintainOneHundredPercent(currentSelection) { // Ensures that the three percentages all equate to 100%
	let opex = document.getElementById('opexPercentage');
	let exceptional = document.getElementById('exceptionalPercentage');
	let capex = document.getElementById('capexPercentage');

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
	if(arrayName === 'roles'){
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

function payChangeMenu(resource, arrayRef, arrayName, radioSelectRef, resourceType) {
	
	let x = arrayRef;
	let y = 0;
	
	// Set the variables depending on whether the record is a resource or a role
	if(resourceType == 'resources'){
		y = lib_resources[x].ref;
		
	} else {
		return;
	}
	
	console.log(y);
	
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null){
		destroyMenu('menuContainer');
	};
	
	let rType = resourceType;
	
	// Create the Advanced Edit Menu
	let editPayMenu = document.createElement('div');
	editPayMenu.id = 'menuContainer';
	editPayMenu.classList.add('editPayMenu');
	
	// Create the menuHeader
	let menuHeader = document.createElement('div');
	menuHeader.classList.add('menuHeader');
	
	let strongString = document.createElement('strong');
	strongString.textContent = `Schedule Pay Change for ${resource.firstname} ${resource.surname}`;
	menuHeader.appendChild(strongString);
	
	let closeButton = document.createElement('button');
	closeButton.textContent = 'X';
	closeButton.addEventListener(
		"click", () => {
			destroyMenu('menuContainer'),
			createResourceMenu(radioSelectRef-1,resourceType)
		}
	)
	menuHeader.appendChild(closeButton);
	
	editPayMenu.appendChild(menuHeader);
	
	let payRiseRows = document.createElement('div');
	payRiseRows.id = 'payRiseRows';
	
	editPayMenu.appendChild(payRiseRows);
	
	let effectiveDateRow = document.createElement('div');
	effectiveDateRow.classList.add('menuRow');
	
	let effectiveDateLabel = document.createElement('label');
	effectiveDateLabel.for = 'pr_date';
	effectiveDateLabel.textContent = 'Effective date';
	
	let effectiveDateInput = document.createElement('input');
	effectiveDateInput.type = 'date';
	effectiveDateInput.id = 'pr_date';
	
	effectiveDateRow.appendChild(effectiveDateLabel);
	effectiveDateRow.appendChild(effectiveDateInput);
	
	editPayMenu.appendChild(effectiveDateRow);
	
	let kindRow = document.createElement('div');
	kindRow.classList.add('menuRow');
	
	let kindLabel = document.createElement('label');
	kindLabel.for = 'pr_kind';
	kindLabel.textContent = 'Kind';
	
	let kindInput = document.createElement('select');
	kindInput.id = 'pr_kind';
	
	let pctOption = document.createElement('option');
	pctOption.value='PCT';
	pctOption.textContent = '% Change';
	
	let absOption = document.createElement('option');
	absOption.value='ABS';
	absOption.textContent = '£ Change';
	
	let newOption = document.createElement('option');
	newOption.value='NEW';
	newOption.textContent = 'New annual';
	
	kindInput.appendChild(pctOption);
	kindInput.appendChild(absOption);
	kindInput.appendChild(newOption);
	kindInput.addEventListener('change',()=>{
		valueInput.step = (kindInput.value === 'PCT') ? '0.01' : '1';
	});
	
	kindRow.appendChild(kindLabel);
	kindRow.appendChild(kindInput);
	
	editPayMenu.appendChild(kindRow);
	
	let valueRow = document.createElement('div');
	valueRow.classList.add('menuRow');
	
	let valueLabel = document.createElement('label');
	valueLabel.for = 'pr_value';
	valueLabel.textContent = 'Value';
	
	let valueInput = document.createElement('input');
	valueInput.type = 'number';
	valueInput.id = 'pr_value';
	valueInput.step = '0.01';
	valueInput.setAttribute('min', '-9999999');
	valueInput.setAttribute('inputmode', 'decimal');
	
	valueRow.appendChild(valueLabel);
	valueRow.appendChild(valueInput);
	
	editPayMenu.appendChild(valueRow);
	
	let noteRow = document.createElement('div');
	noteRow.classList.add('menuRow');
	
	let noteLabel = document.createElement('label');
	noteLabel.for = 'pr_note';
	noteLabel.textContent = 'Note';
	
	let noteInput = document.createElement('input');
	noteInput.type = 'text';
	noteInput.id = 'pr_note';
	
	noteRow.appendChild(noteLabel);
	noteRow.appendChild(noteInput);
	
	editPayMenu.appendChild(noteRow);
	
	let payAddButtonGroup = document.createElement('div');
	payAddButtonGroup.classList.add('buttonGroup');
	
	let payAddButton = document.createElement('button');
	payAddButton.id = 'pr_add';
	payAddButton.textContent = 'Add Pay Change';
	
	payAddButtonGroup.appendChild(payAddButton);
	editPayMenu.appendChild(payAddButtonGroup);
	
	contentView.appendChild(editPayMenu);
	renderPayRiseRows(y);
	
}

function advancedEmployeeEdit(resource, arrayRef, arrayName, radioSelectRef, resourceType) {
	
	let rType = resourceType;
	
	let oValue = resource['opex'] ?? 100;
	let eValue = resource['exceptional'] ?? 0;
	let cValue = resource['capex'] ?? 0;

	let actualMonths = Object.keys(resource['actuals']);
	let outturnMonths = Object.keys(resource['outturn']);
	
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null){
		destroyMenu('menuContainer');
	};
	
	// Create the Advanced Edit Menu
	let advancedEditMenu = document.createElement('div');
	advancedEditMenu.id = 'menuContainer';
	advancedEditMenu.classList.add('advancedEdit');
	
	// Create the menuHeader
	let menuHeader = document.createElement('div');
	menuHeader.classList.add('menuHeader');
	
	let strongString = document.createElement('strong');
	strongString.textContent = `Advanced Edit: ${resource.firstname} ${resource.surname}`;
	menuHeader.appendChild(strongString);
	
	let closeButton = document.createElement('button');
	closeButton.textContent = 'X';
	closeButton.addEventListener(
		"click", () => {
			destroyMenu('menuContainer'),
			createResourceMenu(radioSelectRef-1,resourceType)
		}
	)
	menuHeader.appendChild(closeButton);
	
	advancedEditMenu.appendChild(menuHeader);
	
	let categorisationRow = document.createElement('div');
	categorisationRow.classList.add('panel');
	
	let categorisationHeader = document.createElement('h2');
	categorisationHeader.textContent = 'Categorisation (%)';
	
	categorisationRow.appendChild(categorisationHeader);
	
	// Create the Opex Percentage line
	let opexPercentageRow = document.createElement('div');
	opexPercentageRow.classList.add('menuRow');
	let opexPercentageLabel = document.createElement('label');
	opexPercentageLabel.for = 'opexPercentage';
	opexPercentageLabel.textContent = 'Operating Costs';
	opexPercentageRow.appendChild(opexPercentageLabel);
	let opexPercentageInput = document.createElement('input');
	opexPercentageInput.name = 'opexPercentage';
	opexPercentageInput.id = 'opexPercentage';
	opexPercentageInput.type = 'number';
	opexPercentageInput.placeholder = '100';
	opexPercentageInput.value = oValue;
	opexPercentageInput.min = '0';
	opexPercentageInput.max = '100';
	opexPercentageInput.step = '25';
	opexPercentageInput.addEventListener("click",()=>{maintainOneHundredPercent("opex")})
	opexPercentageRow.appendChild(opexPercentageInput);
//	let opexPercentageP = document.createElement('span');
//	opexPercentageP.textContent = '%';
//	opexPercentageRow.appendChild(opexPercentageP);
	
	categorisationRow.appendChild(opexPercentageRow);
	
	
	// Create the Exceptional Percentage line
	let exceptionalPercentageRow = document.createElement('div');
	exceptionalPercentageRow.classList.add('menuRow');
	let exceptionalPercentageLabel = document.createElement('label');
	exceptionalPercentageLabel.for = 'exceptionalPercentage';
	exceptionalPercentageLabel.textContent = 'Exceptional Costs';
	exceptionalPercentageRow.appendChild(exceptionalPercentageLabel);
	let exceptionalPercentageInput = document.createElement('input');
	exceptionalPercentageInput.name = 'exceptionalPercentage';
	exceptionalPercentageInput.id = 'exceptionalPercentage';
	exceptionalPercentageInput.type = 'number';
	exceptionalPercentageInput.placeholder = '100';
	exceptionalPercentageInput.value = eValue;
	exceptionalPercentageInput.min = '0';
	exceptionalPercentageInput.max = '100';
	exceptionalPercentageInput.step = '25';
	exceptionalPercentageInput.addEventListener("click",()=>{maintainOneHundredPercent("exceptional")})
	exceptionalPercentageRow.appendChild(exceptionalPercentageInput);
//	let exceptionalPercentageP = document.createElement('span');
//	exceptionalPercentageP.textContent = '%';
//	exceptionalPercentageRow.appendChild(exceptionalPercentageP);
	
	categorisationRow.appendChild(exceptionalPercentageRow);
	
	
	// Create the Capex Percentage line
	let capexPercentageRow = document.createElement('div');
	capexPercentageRow.classList.add('menuRow');
	let capexPercentageLabel = document.createElement('label');
	capexPercentageLabel.for = 'capexPercentage';
	capexPercentageLabel.textContent = 'Labour Capitalisation';
	capexPercentageRow.appendChild(capexPercentageLabel);
	let capexPercentageInput = document.createElement('input');
	capexPercentageInput.name = 'capexPercentage';
	capexPercentageInput.id = 'capexPercentage';
	capexPercentageInput.type = 'number';
	capexPercentageInput.placeholder = '100';
	capexPercentageInput.value = cValue;
	capexPercentageInput.min = '0';
	capexPercentageInput.max = '100';
	capexPercentageInput.step = '25';
	capexPercentageInput.addEventListener("click",()=>{maintainOneHundredPercent("capex")})
	capexPercentageRow.appendChild(capexPercentageInput);
//	let capexPercentageP = document.createElement('span');
//	capexPercentageP.textContent = '%';
//	capexPercentageRow.appendChild(capexPercentageP);
	
	categorisationRow.appendChild(capexPercentageRow);
	
	advancedEditMenu.appendChild(categorisationRow);
	
	let advancedAdjustmentSection = document.createElement('div');
	advancedAdjustmentSection.id = 'actualsAdvancedAdjustmentSection';
	
	let actualAdvancedSection = document.createElement('div');
	actualAdvancedSection.id = 'actualsAdvancedAdjustmentSection';
	actualAdvancedSection.classList.add('panel');
	
	let actualsHeader = document.createElement('h2');
	actualsHeader.textContent = 'Actuals';
	
	actualAdvancedSection.appendChild(actualsHeader);
	
	let advancedEditDiv = document.createElement('div');
	advancedEditDiv.id = 'actualsAdvancedEdit';
	
	let actualSelectB = document.createElement('select');
	actualSelectB.id = 'actualSelect';
	
	advancedEditDiv.appendChild(actualSelectB);
	
	let baseRow = document.createElement('div');
	baseRow.id = 'baseRow';
	baseRow.classList.add('menuRow');
	
	
	let baseLabel = document.createElement('label');
	baseLabel.for = 'baseValue';
	baseLabel.textContent = 'Base';
	
	let baseInput = document.createElement('input');
	baseInput.id = 'baseValue';
	baseInput.type = 'number';
	baseInput.name = 'baseValue';
	
	baseRow.appendChild(baseLabel);
	baseRow.appendChild(baseInput);
	advancedEditDiv.appendChild(baseRow);
	
	let overtimeRow = document.createElement('div');
	overtimeRow.id = 'overtimeRow';
	overtimeRow.classList.add('menuRow');
	
	let overtimeLabel = document.createElement('label');
	overtimeLabel.for = 'overtimeValue';
	overtimeLabel.textContent = 'Overtime';
	
	let overtimeInput = document.createElement('input');
	overtimeInput.id = 'overtimeValue';
	overtimeInput.type = 'number';
	overtimeInput.name = 'overtimeValue';
	
	overtimeRow.appendChild(overtimeLabel);
	overtimeRow.appendChild(overtimeInput);
	advancedEditDiv.appendChild(overtimeRow);
	
	let onCallRow = document.createElement('div');
	onCallRow.id = 'onCallRow';
	onCallRow.classList.add('flexRow');
	
	let onCallLabel = document.createElement('label');
	onCallLabel.for = 'onCallValue';
	onCallLabel.textContent = 'On Call';
	
	let onCallInput = document.createElement('input');
	onCallInput.id = 'onCallValue';
	onCallInput.type = 'number';
	onCallInput.name = 'onCallValue';
	
	onCallRow.appendChild(onCallLabel);
	onCallRow.appendChild(onCallInput);
	advancedEditDiv.appendChild(onCallRow);
	
	let bonusRow = document.createElement('div');
	bonusRow.id = 'bonusRow';
	bonusRow.classList.add('flexRow');
	
	let bonusLabel = document.createElement('label');
	bonusLabel.for = 'bonusValue';
	bonusLabel.textContent = 'Bonus';
	
	let bonusInput = document.createElement('input');
	bonusInput.id = 'bonusValue';
	bonusInput.type = 'number';
	bonusInput.name = 'bonusValue';
	
	bonusRow.appendChild(bonusLabel);
	bonusRow.appendChild(bonusInput);
	advancedEditDiv.appendChild(bonusRow);
	
	let otherRow = document.createElement('div');
	otherRow.id = 'otherRow';
	otherRow.classList.add('flexRow');
	
	let otherLabel = document.createElement('label');
	otherLabel.for = 'otherValue';
	otherLabel.textContent = 'Other Costs';
	
	let otherInput = document.createElement('input');
	otherInput.id = 'otherValue';
	otherInput.type = 'number';
	otherInput.name = 'otherValue';
	
	otherRow.appendChild(otherLabel);
	otherRow.appendChild(otherInput);
	advancedEditDiv.appendChild(otherRow);
	
	let welfareRow = document.createElement('div');
	welfareRow.id = 'welfareRow';
	welfareRow.classList.add('flexRow');
	
	let welfareLabel = document.createElement('label');
	welfareLabel.for = 'welfareValue';
	welfareLabel.textContent = 'Welfare';
	
	let welfareInput = document.createElement('input');
	welfareInput.id = 'welfareValue';
	welfareInput.type = 'number';
	welfareInput.name = 'welfareValue';
	
	welfareRow.appendChild(welfareLabel);
	welfareRow.appendChild(welfareInput);
	advancedEditDiv.appendChild(welfareRow);
	
	let pensionRow = document.createElement('div');
	pensionRow.id = 'pensionRow';
	pensionRow.classList.add('flexRow');
	
	let pensionLabel = document.createElement('label');
	pensionLabel.for = 'pensionValue';
	pensionLabel.textContent = 'Pension';
	
	let pensionInput = document.createElement('input');
	pensionInput.id = 'pensionValue';
	pensionInput.type = 'number';
	pensionInput.name = 'pensionValue';
	
	pensionRow.appendChild(pensionLabel);
	pensionRow.appendChild(pensionInput);
	advancedEditDiv.appendChild(pensionRow);
	
	let statutoryPayRow = document.createElement('div');
	statutoryPayRow.id = 'statutoryPayRow';
	statutoryPayRow.classList.add('flexRow');
	
	let statutoryPayLabel = document.createElement('label');
	statutoryPayLabel.for = 'welfareValue';
	statutoryPayLabel.textContent = 'Statutory Pay';
	
	let statutoryPayInput = document.createElement('input');
	statutoryPayInput.id = 'statutoryPayValue';
	statutoryPayInput.type = 'number';
	statutoryPayInput.name = 'statutoryPayValue';
	
	statutoryPayRow.appendChild(statutoryPayLabel);
	statutoryPayRow.appendChild(statutoryPayInput);
	advancedEditDiv.appendChild(statutoryPayRow);
	
	let employersNIRow = document.createElement('div');
	employersNIRow.id = 'employersNIRow';
	employersNIRow.classList.add('flexRow');
	
	let employersNILabel = document.createElement('label');
	employersNILabel.for = 'employersNIValue';
	employersNILabel.textContent = 'Employers NI';
	
	let employersNIInput = document.createElement('input');
	employersNIInput.id = 'employersNIValue';
	employersNIInput.type = 'number';
	employersNIInput.name = 'employersNIValue';
	
	employersNIRow.appendChild(employersNILabel);
	employersNIRow.appendChild(employersNIInput);
	advancedEditDiv.appendChild(employersNIRow);
	
	let commissionRow = document.createElement('div');
	commissionRow.id = 'commissionRow';
	commissionRow.classList.add('flexRow');
	
	let commissionLabel = document.createElement('label');
	commissionLabel.for = 'commissionValue';
	commissionLabel.textContent = 'Commission';
	
	let commissionInput = document.createElement('input');
	commissionInput.id = 'commissionValue';
	commissionInput.type = 'number';
	commissionInput.name = 'commissionValue';
	
	commissionRow.appendChild(commissionLabel);
	commissionRow.appendChild(commissionInput);
	advancedEditDiv.appendChild(commissionRow);
	
	let employeeCostsRow = document.createElement('div');
	employeeCostsRow.id = 'employeeCostsRow';
	employeeCostsRow.classList.add('flexRow');
	
	let employeeCostsLabel = document.createElement('label');
	employeeCostsLabel.for = 'employeeCostsValue';
	employeeCostsLabel.textContent = 'Employee\'s Costs';
	
	let employeeCostsInput = document.createElement('input');
	employeeCostsInput.id = 'employeeCostsValue';
	employeeCostsInput.type = 'number';
	employeeCostsInput.name = 'employeeCostsValue';
	
	employeeCostsRow.appendChild(employeeCostsLabel);
	employeeCostsRow.appendChild(employeeCostsInput);
	advancedEditDiv.appendChild(employeeCostsRow);
	
	actualAdvancedSection.appendChild(advancedEditDiv);
	advancedEditMenu.appendChild(actualAdvancedSection);
	
	let outturnAdvancedAdjustmentSectionDiv = document.createElement('div');
	outturnAdvancedAdjustmentSectionDiv.id = 'outturnAdvancedAdjustmentSection';
	outturnAdvancedAdjustmentSectionDiv.classList.add('panel');
	
	let outturnAdvancedHeader = document.createElement('h2');
	outturnAdvancedHeader.textContent = 'Outturn';
	
	outturnAdvancedAdjustmentSectionDiv.appendChild(outturnAdvancedHeader);
	
	let outturnAdvancedEditDiv = document.createElement('div');
	outturnAdvancedEditDiv.id = 'outturnAdvancedEdit';
	
	let outturnSelectB = document.createElement('select');
	outturnSelectB.id = 'outturnSelect';
	
	outturnAdvancedEditDiv.appendChild(outturnSelectB);
	
	outturnAdvancedAdjustmentSectionDiv.appendChild(outturnAdvancedEditDiv);
	
	let outturnCategoryDiv = document.createElement('div');
	outturnCategoryDiv.id = 'outturnCategoryMonthly';
	
	outturnAdvancedAdjustmentSectionDiv.appendChild(outturnCategoryDiv);
	
	advancedEditMenu.appendChild(outturnAdvancedAdjustmentSectionDiv);
	contentView.appendChild(advancedEditMenu);
	
	makeDraggable(advancedEditMenu);
	
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