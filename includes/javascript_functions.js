<script>

/* FUNCTION TO DETECT SAFARI BROWSER */
function isSafari() {
	const ua = navigator.userAgent.toLowerCase();
	return ua.includes('safari/') && !ua.includes('chrome/') && !ua.includes('chromium/');
}

/* Dynamically set the favicon based on the browser */
function setFavicon() {
	const link = document.createElement('link');
	link.rel = 'icon';
	link.type = 'image/png';
	link.href = isSafari()
		? '/assets/favicon_original.png'
		: '/assets/favicon_original.svg';
	document.head.appendChild(link);
}

setFavicon(); // Set favicon immediately on load

// ------------------------------
// Cookie handling functions
// ------------------------------

// Set a cookie with optional expiry in days
function setCookie(name, value, days) {
	let expires = "";
	value = scrub(value);
	if (days) {
		const date = new Date();
		date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
		expires = "; expires=" + date.toUTCString();
	}
	document.cookie = `${name}=${encodeURIComponent(value || "")}${expires}; path='/'`;
}

// Delete a cookie
function eraseCookie(name) {
	document.cookie = `${name}=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;`;
}

// Retrieve the value of a cookie by name
function getCookie(name) {
	const cookies = document.cookie.split(';');
	for (let cookie of cookies) {
		const [key, value] = cookie.trim().split('=');
		if (key === name) {
			return decodeURIComponent(value);
		}
	}
	return null;
}

// Clean the user input using DOMPurify
function scrub(dirty){
	return DOMPurify.sanitize(dirty);
}

// ------------------------------
// AJAX content loading helpers using Fetch API
// ------------------------------

function dynamicContentLoad(selector, resource) {
	const target = document.querySelector(selector);
	if (!target) return;

	// Fade out over 200ms
	target.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 200, fill: 'forwards' }).onfinish = () => {
		// Once faded out, fetch new content
		fetch(resource)
			.then(response => {
				if (!response.ok) throw new Error("Network response was not ok");
				return response.text();
			})
			.then(data => {
				target.innerHTML = data;

				// Fade in over 300ms
				target.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 300, fill: 'forwards' });
			})
			.catch(error => {
				console.error('Fetch error:', error);
			});
	};
}

// ------------------------------
// Event listeners: wait until DOM is fully loaded
// ------------------------------
document.addEventListener('DOMContentLoaded', () => {

	// Handle "logIn" click
	const logIn = document.getElementById('logInLink');
	if (logIn) {
		logIn.addEventListener('click', () => {
			dynamicContentLoad('#contentView', '/includes/login.php?inject=1');
		});
	}

	// Handle "register" click
	const register = document.getElementById('registerLink');
	if (register) {
		register.addEventListener('click', () => {
			dynamicContentLoad('#contentView', '/includes/register.php?inject=1');
		});
	}
	
	document.addEventListener('click', function (e) { // Handles the pull of register.php when the links are created dynamically
		// ⚠️ Would be great to understand this more
		const target = e.target;
	
		// Handle clicks on #loginRegister or #loginRegister2
		if (target && (target.id === 'loginRegister' || target.id === 'loginRegister2')) {
			e.preventDefault();
	
			dynamicContentLoad('#contentView', '/includes/register.php?inject=1');
		}
	});

	// Handle "logOut" click
	const logOut = document.getElementById('logOutLink');
	if (logOut) {
		logOut.addEventListener('click', () => {
			window.location.href = './scripts/destroySession.php';
		});
	}
});

function loadRegistrationForm(){
	dynamicContentLoad('#contentView', '/includes/register.php?inject=1');
}

// ------------------------------
// ➕Imported from peopleForecast.js prior to deletion
// ------------------------------
 
function generateMonthRange(offset) { // Populates the monthArray array with the relevant months using an offset determined from the selector option
	
	if(isNaN(offset)){
		offset = 0;
	}
	const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
	const currentDate = new Date();
	const startMonthIndex = -aMonths + offset; // Number of months prior (includes current reporting month, being last month) - set in GLOBAL VARIABLES & ARRAYS above but can be changed
	const endMonthIndex = oMonths + offset;   // Number months into the future - set in GLOBAL VARIABLES & ARRAYS above but can be changed
	const monthArray = [];

	for (let i = startMonthIndex; i <= endMonthIndex; i++) {
		const newDate = new Date(currentDate.getFullYear(), currentDate.getMonth() + i, 1); // Adjust month
		const formattedMonth = `${months[newDate.getMonth()]}-${newDate.getFullYear().toString().slice(-2)}`;
		monthArray.push(formattedMonth);
	}

	return monthArray;
}

function convertToLastDay(dateStr) {
	const [monthAbbrev, yearSuffix] = dateStr.split('-');
	const monthMap = {
		JAN: 0, FEB: 1, MAR: 2, APR: 3, MAY: 4, JUN: 5,
		JUL: 6, AUG: 7, SEP: 8, OCT: 9, NOV: 10, DEC: 11
	};

	const year = 2000 + parseInt(yearSuffix);
	const month = monthMap[monthAbbrev.toUpperCase()];

	// Get last day of month
	const lastDay = new Date(year, month + 1, 0);

	// Set time to end of the day
	lastDay.setHours(23, 59, 59, 999);

	return lastDay;
}

// ------------------------------


// ------------------------------
// Utility functions to show/hide elements
// ------------------------------

function Resource (ref, jobTitle, firstname, surname, start_date, end_date, annual_salary, fte, pension, rowNumber, departmentNumber, contractType){
	this.ref = ref;
	this.jobTitle = jobTitle;
	this.firstname = firstname;
	this.surname = surname;
	this.start_date = start_date;
	this.end_date = end_date;
	this.annual_salary = annual_salary;
	this.fte = fte;
	this.pension = Number(parseFloat(pension).toFixed(9));
	this.rowNumber = rowNumber;
	if(departmentNumber==null){
		this.departmentNumber = 0;
	} else {
		this.departmentNumber = departmentNumber;
	}
	this.contractType = contractType;
	this.actuals = [];
}

// ‼️ This is required. It is not called in .js files, though, it is called in .php files
function fadeLoadContent(element, source, speedOut, speedIn){
	element = "#" + element;
	source = source;
	if (speedOut == null){
		speedOut = 200;
	} else {
		speedOut = speedOut;
	}
	if (speedIn == null) {
		speedIn = 200;
	} else {
		speedIn = speedIn;
	}
	$(element)
	.fadeOut(speedOut, function() {		// 1. fade current content out (200 ms)
		$(this).load(source, () => {
			$(this).fadeIn(speedIn);	// 3. fade new content in (200 ms)
		});								// 2. load new toolbar
	});
}

// ------------------------------
// Dragging Menu Script
// ------------------------------

function makeDraggable(element) {
  let isDragging = false;
  let offsetX = 0;
  let offsetY = 0;

  element.addEventListener("mousedown", (e) => {
	isDragging = true;
	offsetX = e.clientX - element.offsetLeft;
	offsetY = e.clientY - element.offsetTop;
	element.style.zIndex = 1000;
  });

  document.addEventListener("mousemove", (e) => {
	if (isDragging) {
	  element.style.left = `${e.clientX - offsetX}px`;
	  element.style.top = `${e.clientY - offsetY}px`;
	}
  });

  document.addEventListener("mouseup", () => {
	isDragging = false;
  });
}

// ------------------------------
// Home Page Menus
// ------------------------------

function createCFOMenu(){
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null) {
		destroyMenu('menuContainer');
	}
	
	// Create the new menu container
	let cfoMenu = document.createElement('div');
	cfoMenu.id = 'menuContainer';
	
	// Append to DOM
	document.getElementById('contentView').appendChild(cfoMenu);
	
	// Build inner HTML structure
	cfoMenu.innerHTML = `
		<div class='menuHeader'><strong>CFOs & FDs</strong><button onClick='destroyMenu("menuContainer");'><strong>X</strong></button></div>
		<div>
		<ul>
			<li>Break down costs by department, role, or person</li>
			<li>See year-end outturn instantly from live data</li>
			<li>Spot overspends early and avoid surprises</li>
			<li>Share clear, board-ready reports</li>
			<li>Model pay or headcount changes in seconds</li>
		</ul>
		</div>
	`;
	
	makeDraggable(cfoMenu);
	
}

function createHRMenu(){
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null) {
		destroyMenu('menuContainer');
	}
	
	// Create the new menu container
	let hrMenu = document.createElement('div');
	hrMenu.id = 'menuContainer';
	
	// Append to DOM
	document.getElementById('contentView').appendChild(hrMenu);
	
	// Build inner HTML structure
	hrMenu.innerHTML = `
		<div class='menuHeader'><strong>HR Leads</strong><button onClick='destroyMenu("menuContainer");'><strong>X</strong></button></div>
		<div>
		<ul>
			<li>Align hiring plans with budget and forecast</li>
			<li>Track start dates, end dates, and role changes</li>
			<li>See cost impact before you hire</li>
			<li>Share plans in a finance-friendly format</li>
			<li>Test scenarios like delayed hires or pay rises</li>
		</ul>
		</div>
	`;
	
	makeDraggable(hrMenu);
	
}

function createBudgetHolderMenu(){
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null) {
		destroyMenu('menuContainer');
	}
	
	// Create the new menu container
	let budgetHolderMenu = document.createElement('div');
	budgetHolderMenu.id = 'menuContainer';
	
	// Append to DOM
	document.getElementById('contentView').appendChild(budgetHolderMenu);
	
	// Build inner HTML structure
	budgetHolderMenu.innerHTML = `
		<div class='menuHeader'><strong>Budget Owners</strong><button onClick='destroyMenu("menuContainer");'><strong>X</strong></button></div>
		<div>
		<ul>
			<li>All your staffing costs in one dashboard</li>
			<li>Check if you're under or over budget instantly</li>
			<li>See how changes affect monthly costs</li>
			<li>Skip the spreadsheets and manual updates</li>
			<li>Spot trends before they hit your budget</li>
		</ul>
		</div>
	`;
	
	makeDraggable(budgetHolderMenu);
	
}

function addUserMenu(){
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null) {
		destroyMenu('menuContainer');
	}
	
	// Create the new menu container
	
	let newUserMenu = document.createElement('div');
	newUserMenu.id = 'menuContainer';
	
	let headerDiv = document.createElement('div');
	headerDiv.classList.add("menuHeader");
	headerDiv.innerHTML = `
		<strong>Add User</strong>
		<button onClick='destroyMenu("menuContainer");'>
			<strong>X</strong>
		</button>
	`;
	
	newUserMenu.appendChild(headerDiv);
	
	let userMenuRow = document.createElement('div');
	userMenuRow.classList.add('menuRow');
	
	let emailAddressLabel = document.createElement('label');
	emailAddressLabel.for = 'emailAddressInput';
	emailAddressLabel.textContent = 'Email Address';
	
	userMenuRow.appendChild(emailAddressLabel);
	
	let emailAddressInput = document.createElement('input');
	emailAddressInput.id = 'emailAddressInput';
	emailAddressInput.name = 'emailAddressInput';
	emailAddressInput.placeholder = 'user.name@company.com';
	
	userMenuRow.appendChild(emailAddressInput);
	
	newUserMenu.appendChild(userMenuRow);
	
	let firstNameMenuRow = document.createElement('div');
	firstNameMenuRow.classList.add('menuRow');
	
	let firstNameLabel = document.createElement('label');
	firstNameLabel.for = 'firstNameInput';
	firstNameLabel.textContent = 'First Name';
	
	firstNameMenuRow.appendChild(firstNameLabel);
	
	let firstNameInput = document.createElement('input');
	firstNameInput.id = 'firstNameInput';
	firstNameInput.name = 'firstNameInput';
	firstNameInput.placeholder = 'User';
	
	firstNameMenuRow.appendChild(firstNameInput);
	
	newUserMenu.appendChild(firstNameMenuRow);
	
	let surnameMenuRow = document.createElement('div');
	surnameMenuRow.classList.add('menuRow');
	
	let surnameLabel = document.createElement('label');
	surnameLabel.for = 'surnameInput';
	surnameLabel.textContent = 'Surname';
	
	surnameMenuRow.appendChild(surnameLabel);
	
	let surnameInput = document.createElement('input');
	surnameInput.id = 'surnameInput';
	surnameInput.name = 'surnameInput';
	surnameInput.placeholder = 'Name';
	
	surnameMenuRow.appendChild(surnameInput);
	
	newUserMenu.appendChild(surnameMenuRow);
	
	let accessSelectRow = document.createElement('div');
	accessSelectRow.classList.add('menuRow');
	
	let accessSelectLable = document.createElement('label');
	accessSelectLable.for = 'accessSelectInput';
	accessSelectLable.textContent = 'Access Level';
	
	accessSelectRow.appendChild(accessSelectLable);
	
	let accessSelectInput = document.createElement('select');
	accessSelectInput.id = 'accessSelectInput';
	accessSelectInput.name = 'accessSelectInput';
	
	// Build options from unassignedSeats (only roles with spare paid seats)
	const seatsMap = window.unassignedSeats || {};
	const eligible = accessLevels.filter(l => {
	  const ref = Number(l.ref);
	  // hide SUPERUSER / non-paid roles (mrr <= 0) just like the Users table
	  if (l.code === 'SUPERUSER' || Number(l.mrr) <= 0) return false;
	  return Number(seatsMap[ref] || 0) > 0;
	});
	
	if (eligible.length === 0) {
	  const opt = document.createElement('option');
	  opt.value = '';
	  opt.textContent = 'No seats available';
	  opt.disabled = true;
	  opt.selected = true;
	  accessSelectInput.appendChild(opt);
	  // (optional) prevent proceeding if none available
	  // purchaseButton.disabled = true;  // uncomment if you want to block the button
	} else {
	  eligible.forEach(l => {
		const opt = document.createElement('option');
		opt.value = l.ref;
		opt.textContent = l.name; // no prices
		accessSelectInput.appendChild(opt);
	  });
	}
	
	accessSelectRow.appendChild(accessSelectInput);
	
	newUserMenu.appendChild(accessSelectRow);
	
	let purchaseButton = document.createElement('button');
	purchaseButton.id = 'purchaseButton';
	purchaseButton.textContent = 'Add User';
	
	// --- validation helpers ---
	const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	
	function fieldsValid() {
	  const okEmail = emailRe.test(emailAddressInput.value.trim());
	  const okFirst = firstNameInput.value.trim().length >= 1;
	  const okLast  = surnameInput.value.trim().length >= 1;
	  const okRole  = accessSelectInput.value && accessSelectInput.value !== '';
	  return okEmail && okFirst && okLast && okRole;
	}
	
	function updateButtonState() {
	  purchaseButton.disabled = !fieldsValid();
	}
	
	emailAddressInput.addEventListener('input', updateButtonState);
	firstNameInput.addEventListener('input', updateButtonState);
	surnameInput.addEventListener('input', updateButtonState);
	accessSelectInput.addEventListener('change', updateButtonState);
	
	// initial state
	purchaseButton.disabled = true;
	updateButtonState();
	
	purchaseButton.addEventListener('click', async () => {
	  if (!fieldsValid()) return;
	
	  try {
		const resp = await fetch('/scripts/createUser.php', {
		  method: 'POST',
		  headers: {
			'Content-Type': 'application/json',
			'X-CSRF-Token': window.csrfToken
		  },
		  body: JSON.stringify({
			email: emailAddressInput.value.trim(),
			firstName: firstNameInput.value.trim(),
			surname: surnameInput.value.trim(),
			accessRef: Number(accessSelectInput.value)
		  })
		});
	
		const data = await resp.json();
	
		if (data.status === 'success') {
		  // close menu
		  destroyMenu('menuContainer');
	
		  // refresh Users panel (use whichever target wrapper you’ve got)
		  // If you don’t have a partial include, just do:
		  location.reload();
	
		  alert('Invite sent to ' + emailAddressInput.value.trim());
		} else {
		  alert(data.message || 'Unable to add user.');
		}
	  } catch (e) {
		console.error(e);
		alert('Network error while creating user.');
	  }
	});
	
	newUserMenu.appendChild(purchaseButton);
	
	// Append to DOM
	document.getElementById('contentView').appendChild(newUserMenu);
	
	makeDraggable(newUserMenu);
	
}

// ------------------------------
// Consistents
// ------------------------------

function populateAllFromJson(data) {
	// Clear global arrays first
	lib_resources = [];
	roles = [];
	departments = [];
	forecasts = [];
	userOutturn = [];
	niBands = [];
	niBandLookup = [];

	// 🔹 1. RESOURCES
	data.resources.forEach((res, i) => {
		let r = new Resource(
			res.RES_REF,
			'Unallocated',
			res.FIRSTNAME,
			res.SURNAME,
			res.START_DATE,
			res.END_DATE,
			res.ANNUAL_SALARY,
			res.FTE,
			res.PENSION,
			i,
			res.DEPARTMENT,
			res.CONTRACT_TYPE
		);
		lib_resources.push(r);
	});

	// 🔹 2. ROLES
	data.roles.forEach((row, i) => {
		let r = new Role(
			row.REF,
			row.JOB_TITLE,
			row.DEPARTMENT,
			row.FILLED_REFERENCE,
			row.STATUS,
			row.BENCHMARK_FTE,
			row.BENCHMARK_SALARY,
			row.BENCHMARK_PRORATA_SALARY,
			row.START_DATE,
			row.END_DATE,
			row.CONTRACT_TYPE,
			row.PENSION,
			i
		);
		roles.push(r);
	});

	// 🔹 3. DEPARTMENTS
	data.departments.forEach((row, i) => {
		let d = new Department(row.REF, row.DEPARTMENT);
		departments.push(d);
	});

	// 🔹 4. FORECASTS
	data.forecasts.forEach((row, i) => {
		let f = new ForecastList(i, row.ACTUAL_FORECAST, row.FORECAST_NAME, row.FORECAST_VERSION);
		forecasts.push(f);
	});

	// 🔹 5. OUTTURNS
	data.outturns.forEach((entry, i) => {
		let existing = userOutturn.find(o => o.library === entry.library && o.ref === entry.ref);
		if (!existing) {
			existing = {
				library: entry.library,
				ref: entry.ref,
				outturn: {}
			};
			userOutturn.push(existing);
		}
		if (!existing.outturn[entry.date]) {
			existing.outturn[entry.date] = {};
		}
		existing.outturn[entry.date][entry.type] = entry.value;
	});

	// 🔹 6. NI BANDS
	data.niBands.forEach(band => {
		niBands.push(band);
		const year = new Date(band.FROM_DATE).getFullYear();
		niBandLookup[year] = band;
	});

	// 🔹 7. ACTUALS (hydrate + compute totalCosts per EMP_KEY, per month)
	data.actuals.forEach(entry => {
	  const month = convertDateToMMMYY(entry.DATE);
	  const resource = lib_resources.find(r => r.ref == entry.EMP_KEY);
	  if (!resource) return;
	
	  // Ensure month bucket exists with all canonical keys
	  if (!resource.actuals) resource.actuals = {};
	  if (!resource.actuals[month]) {
		resource.actuals[month] = {
		  totalCosts: 0,
		  base: 0,
		  overtime: 0,
		  onCall: 0,
		  bonus: 0,
		  other: 0,
		  welfare: 0,
		  pension: 0,
		  statutoryPay: 0,
		  employersNI: 0,
		  commission: 0,
		  employeeCosts: 0,
		  type: 'actual'
		};
	  }
	
	  // Map DB TYPE -> our canonical keys (handles both space- and camelCase)
	  const canonicalKey = t => {
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
		  case 'employers ni':
		  case 'employersni':
		  case "employer's ni": return 'employersNI';
		  case 'commission': return 'commission';
		  case 'employee costs':
		  case 'employeecosts': return 'employeeCosts';
		  case 'paye': return 'paye';
		  default: return null;
		}
	  };
	
	  const key = canonicalKey(entry.TYPE);
	  const val = Number(entry.VALUE) || 0;
	
	  if (key && key in resource.actuals[month]) {
		resource.actuals[month][key] += val;
	  } else {
		console.warn(`[actuals] Unmapped TYPE '${entry.TYPE}' → defaulting to 'other'`);
		resource.actuals[month].other += val;
	  }
	
	  // Recompute totalCosts each write
	  const include = ['base','overtime','onCall','bonus','other','welfare','pension','employersNI','commission'];
	  resource.actuals[month].totalCosts = include.reduce((sum, k) => sum + (Number(resource.actuals[month][k]) || 0), 0);
	});
	
}

// ------------------------------
// StaffCast Application
// ------------------------------


/* GLOBALS */
window.payRises = window.payRises || [];
window.risesByResource = window.risesByResource || {};
let peopleCostsChartInstance = null;

function updatePeopleCostsChartFromSelection() {
  const monthsEl = document.getElementById('months');
  if (!monthsEl) return;

  // Your select's VALUE is an offset (number), not "Mon-YY"
  const offset = parseInt(monthsEl.value, 10) || 0;

  // Read your cookies and default them sensibly
  const actual = Number(getCookie('aMonths')) || 7;  // months back incl. current
  const outturn = Number(getCookie('oMonths')) || 6; // months forward

  // Build the label list using your existing helper
  const labels = generateMonthArray(offset, actual, outturn - 1);

  // Render the chart for these labels
  renderPeopleCostsChart(labels);
}

// your updated renderer
async function renderPeopleCostsChart(labels) {
  await ensureChartJs();                   // lazy-load Chart.js if needed

  const canvas = document.getElementById('peopleCostsChart');
  if (!canvas || !window.Chart) return;

  if (peopleCostsChartInstance) {
	peopleCostsChartInstance.destroy();
	peopleCostsChartInstance = null;
  }

  // derive labels if not provided
  if (!Array.isArray(labels) || !labels.length) {
	const monthsEl = document.getElementById('months');
	const offset = monthsEl ? parseInt(monthsEl.value, 10) || 0 : 0;
	const actual = Number(getCookie('aMonths')) || 7;
	const outturn = Number(getCookie('oMonths')) || 6;
	labels = generateMonthArray(offset, actual, outturn - 1);
  }

  const actuals  = (window.peopleCostsData && window.peopleCostsData.actuals)  || {};
  const outturn  = (window.peopleCostsData && window.peopleCostsData.outturn)  || {};
  const forecast = (window.peopleCostsData && window.peopleCostsData.forecast) || {};

  // colours
  const BOLD_BLUE = 'rgba(25,118,210,1)'; // Actuals
  const BRAND_OUTTURN = '#07A4BC';        // Outturn months

  const barData = [];
  const barColors = [];
  const fcData = [];

  for (const m of labels) {
	if (actuals[m] != null) {
	  barData.push(+actuals[m] || 0);
	  barColors.push(BOLD_BLUE);
	} else {
	  barData.push(+outturn[m] || 0);
	  barColors.push(BRAND_OUTTURN);
	}
	fcData.push(+forecast[m] || 0);
  }

  const brand = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim();
  const gbp = v => '£' + (v ?? 0).toLocaleString('en-GB', { maximumFractionDigits: 0 });

  peopleCostsChartInstance = new Chart(canvas.getContext('2d'), {
	type: 'bar',
	data: {
	  labels,
	  datasets: [
		{
		  label: 'People Cost',
		  data: barData,
		  backgroundColor: barColors,
		  borderColor: barColors,
		  borderWidth: 1,
		  borderRadius: 6,
		  maxBarThickness: 28,
		},
		{
		  type: 'line',
		  label: 'Forecast',
		  data: fcData,
		  borderColor: brand,
		  backgroundColor: brand,
		  borderWidth: 2,
		  pointRadius: 3,
		  pointHoverRadius: 4,
		  tension: 0.25
		}
	  ]
	},
	options: {
	  responsive: true,
	  maintainAspectRatio: false,
	  layout: { padding: { top: 8, right: 8, bottom: 4, left: 4 } },
	  interaction: { mode: 'index', intersect: false },
	  plugins: {
		legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8 } },
		title:  { display: false },
		tooltip:{ padding: 10, callbacks: { label: c => `${c.dataset.label}: ${gbp(c.parsed.y)}` } }
	  },
	  scales: {
		y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.06)' }, ticks: { callback: v => gbp(v) } },
		x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true } }
	  }
	}
  });

  // this exists in summarisedOutturn.js
  if (typeof syncChartHeightToSummary === 'function') {
	syncChartHeightToSummary();
  }
}

function convertToLastDayOfMonth(monthStr) {
  const [mon, yearSuffix] = monthStr.split('-');
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const monthIndex = months.indexOf(mon);
  const year = parseInt('20' + yearSuffix, 10);
  return new Date(year, monthIndex + 1, 0); // last day of month
}

function calculateEmployersNI(grossSalary, monthStr) {
  const date = convertToLastDayOfMonth(monthStr);
  const taxYearStart = (date.getMonth() >= 3) ? date.getFullYear() : date.getFullYear() - 1;
  const band = niBandLookup[taxYearStart];

  if (!band) {
	console.warn(`No NI band found for tax year starting ${taxYearStart}`);
	return 0;
  }

  const threshold = parseFloat(band.SECONDARY_THRESHOLD_MONTHLY);
  const rate = parseFloat(band.RATE);

  if (grossSalary <= threshold) {
	return 0;
  }

  const niPayable = (grossSalary - threshold) * rate;
  return Math.round(niPayable * 100) / 100;
}

function parseMonthYear(input) {
	const [monthAbbr, yearSuffix] = input.split('-');
	const fullYear = 2000 + parseInt(yearSuffix, 10); // Assumes years 2000–2099

	const monthMap = {
		Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5,
		Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11
	};

	const month = monthMap[monthAbbr];

	if (month === undefined || isNaN(fullYear)) {
		throw new Error("Invalid format. Use format like 'Jun-25'.");
	}

	return new Date(fullYear, month, 1); // 1st day of the month
}

function checkMonthIsEqual(date1,date2){ // Function to check that the month is the same for two separate dates
	const a = new Date(date1);
	const b = new Date(date2);
	
	const sameMonth = a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth();
	return sameMonth;
}

async function loadPayRises(){
	const resp = await fetch('/scripts/getPayRises.php', {
		method: 'GET',
		headers: {'X-CSRF-Token': window.csrfToken}
	});
	const data = await resp.json();
	if (data.status === 'success'){
		window.payRises = data.rows || [];
		window.risesByResource = {};
		for (const r of window.payRises){
			const k = String(r.RESOURCE_REF);
			(window.risesByResource[k] ||= []).push(r);
		}
		// keep each resource's rises sorted by date asc
		for (const k in window.risesByResource){
			window.risesByResource[k].sort((a,b) => new Date(a.EFFECTIVE_DATE) - new Date(b.EFFECTIVE_DATE));
		}
	}
}

window.loadPayRises = loadPayRises; // make callable from other files

// ‼️ This is required. It is not called in .js files, though, it is called in .php files
function populateResourceActuals(resource_id, date, type, value) {
  // Map DB/payTypeGroup VALUE -> canonical object keys
  function canonicalKey(raw) {
	const s = String(raw || '').toLowerCase().trim();
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
		  default: return null;
		}
  }

  // Resolve groupType from payTypeGroups (type is a REF)
  let groupType = '';
  for (const ptg of payTypeGroups) {
	if (type == ptg.ref) { groupType = ptg.value; break; }
  }
  const key = canonicalKey(groupType);
  const numericVal = Number(value) || 0;

  // Ensure month bucket exists with canonical keys
  if (!resource_id.actuals) resource_id.actuals = {};
  if (!resource_id.actuals[date]) {
	resource_id.actuals[date] = {
	  totalCosts: 0,
	  base: 0,
	  overtime: 0,
	  onCall: 0,
	  bonus: 0,
	  other: 0,
	  welfare: 0,
	  pension: 0,
	  statutoryPay: 0,
	  employersNI: 0,
	  commission: 0,
	  employeeCosts: 0,
	  type: 'actual'
	};
  }

  // Write the value
  if (key && key in resource_id.actuals[date]) {
	resource_id.actuals[date][key] += numericVal;
  } else {
	console.warn(`Unrecognised groupType '${groupType}' from '${type}', falling back to 'other'`);
	resource_id.actuals[date].other += numericVal;
  }

  // Recompute totalCosts
  const include = ['base','overtime','onCall','bonus','other','welfare','pension','employersNI','commission'];
  resource_id.actuals[date].totalCosts = include.reduce(
	(sum, k) => sum + (Number(resource_id.actuals[date][k]) || 0),
	0
  );
}

function weightedAverageRecent(array) {
	const n = array.length;
	if (n === 0) return 0;

	let weightedSum = 0;
	let totalWeight = 0;

	for (let i = 0; i < n; i++) {
		const weight = i + 1; // oldest = 1, newest = n
		weightedSum += array[i] * weight;
		totalWeight += weight;
	}

	return Number(weightedSum / totalWeight);
}

function calculateResourceWeightedHistory(resource, type) {
	let history = [];

	const actuals = resource.actuals || {};

	const monthKeys = Object.keys(actuals).filter(key =>
		/^[A-Za-z]{3}-\d{2}$/.test(key)
	);

	monthKeys.sort((a, b) => {
		const parseDate = str => new Date('01-' + str); // e.g., '01-Jun-25'
		return parseDate(a) - parseDate(b);
	});

	monthKeys.forEach(key => {
		const monthData = actuals[key];
		if (monthData && typeof monthData[type] === 'number') {
			history.push(monthData[type]);
		}
	});

	return weightedAverageRecent(history);
}

function calculatePercentageWorked(startDate, endDate, monthISO, started, left) {
	let percentage = 1;

	if (!started) {
		if (startDate < monthISO && !checkMonthIsEqual(monthISO, startDate)) {
			started = 1;
			percentage = 1;
		} else if (checkMonthIsEqual(monthISO, startDate)) {
			started = 1;
			let fullDays = monthISO.getDate() + 1;
			let workedDays = startDate.getDate() - 1;
			percentage = workedDays / fullDays;
			if (workedDays > fullDays || workedDays === 0) percentage = 1;
		} else {
			percentage = 0;
		}
	} else {
		if (!left && checkMonthIsEqual(monthISO, endDate)) {
			let fullDays = monthISO.getDate();
			let workedDays = endDate.getDate();
			percentage = workedDays / fullDays;
			if (workedDays > fullDays) percentage = 1;
			left = 1;
		} else if (left || monthISO > endDate) {
			percentage = 0;
			left = 1;
		} else {
			percentage = 1;
		}
	}

	return { percentage, started, left };
}

async function getDefaultPensionRate(resourceReference) {
	try {
		const res = await fetch("/scripts/getDefaultPensionRate.php", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-CSRF-Token": csrfToken
			},
			body: JSON.stringify({
				resourceReference: resourceReference
			})
		});

		const data = await res.json();

		if (data.status === "success" && typeof data.pensionRate === "number") {
			return data.pensionRate; // ✅ return just the numeric pension rate
		} else {
			console.warn("No pension rate returned, defaulting to 0.04");
			return 0.04; // fallback default
		}
	} catch (err) {
		console.error("Error fetching pension rate:", err);
		return 0.04; // fallback default if fetch fails
	}
}

async function populateResourceOutturn() { 
// Calculates the Outturn numbers for both the resources and the roles, implements them into the relevant array to be accessed
// Functions used:
// - calculateResourceWeightedHistory()
// - generateMonthArray() 
// - calculatePercentageWorked()
// - convertToLastDay()
// - calculateEmployersNI()
	
	
	// PROCESS lib_resources
	lib_resources.forEach(resource => { // Go through each of the objects in lib_resources
		
		resource['outturn'] = []; // create an array for each resource, called outturn: this is where the outturn will be stored
		
		const baseAnnual = Number(scrub(resource.annual_salary)) || 0;
		const startDate = new Date(scrub(resource.start_date));
		const endDate = new Date(scrub(resource.end_date));
		endDate.setHours(23,59,59,999);
		
		const rises = risesByResource[String(resource.ref)] || []; // array sorted by effective date
		
		let actualMonths = Number(scrub(getCookie('aMonths'))) || 7;
		let outturnMonths = 60;
		let monthArray = generateMonthArray(0, actualMonths, outturnMonths);
		
		// helper to compute annual at a given month (first day)
		function annualAt(monthStr){
			let annual = baseAnnual;
			const monthFirst = parseMonthYear(monthStr); // your helper returns 1st of month
			
			for (const r of rises){
				if(new Date(r.EFFECTIVE_DATE) <= monthFirst) {
					const kind = (r.RISE_KIND || 'PCT').toUpperCase();
					const val = Number(r.VALUE) || 0;
					if (kind === 'PCT') annual = Math.round((annual * (1 + val/100)) * 100) /100;
					else if (kind === 'ABS') annual = Math.round((annual + val) * 100) / 100;
					else if (kind === 'NEW') annual = Math.round(val * 100) / 100;
				}
			}
			return annual;
		}
		
		let salary = scrub(resource.annual_salary); // Get the annual salary from the resource so that we can use it later
		let overtime = calculateResourceWeightedHistory(resource, 'overtime');  
		let onCall = calculateResourceWeightedHistory(resource, 'onCall');
		let bonus = calculateResourceWeightedHistory(resource, 'bonus');
		let other = calculateResourceWeightedHistory(resource, 'other');
		let welfare = calculateResourceWeightedHistory(resource, 'welfare');
		let statutoryPay = calculateResourceWeightedHistory(resource, 'statutoryPay');
		let commission = calculateResourceWeightedHistory(resource, 'commission');
		let employeeCosts = calculateResourceWeightedHistory(resource, 'employeeCosts');
		let type = 'outturn'; // Type is always outturn
		let resourceStarted = 0; // a flag for if the resource has started: default is 0 (off)
		let resourceLeft = 0; // a flag for if the resource has left: default is 0 (off)

		monthArray.forEach(month => { // Go through each of the months
			let monthISO = new Date(convertToLastDay(month)); // Take the month that I'm looking at (e.g. Apr-25) and convert it into a standard date on the last day of the month (e.g. '2025-04-30 23:59:59)
			let result = calculatePercentageWorked(startDate, endDate, monthISO, resourceStarted, resourceLeft);
			resourceStarted = result.started; // Pulls the started value from the function so that we can check if they have started or not
			resourceLeft = result.left; // Pulls the left value from the function so that we can check if they have finished or not
			let percentageOfDaysWorked = result.percentage; // Pulls the percentage (to be applied to metrics) from the function
			
			const annualForMonth = annualAt(month);
			const monthlySalary = Math.round((annualForMonth / 12) * 100) / 100;
			
			// Need to calculate these so they can be passed to calculateEmployersNI cleanly
			let mBase = monthlySalary * percentageOfDaysWorked;
			let mOvertime = overtime * percentageOfDaysWorked;
			let mOnCall = onCall * percentageOfDaysWorked;
			let mBonus = bonus * percentageOfDaysWorked;
			let mOther = other * percentageOfDaysWorked;
			let mCommission = commission * percentageOfDaysWorked;
			
			// Now bring them together so that we have a value to pass to the calculation
			let forErsNI = mBase + mOvertime + mOnCall + mBonus + mOther + mCommission;
			
			// Pension at 4% of eligible pay components (exclude 'other', welfare, etc.)
			let mPension = 0.04 * (mBase + mOvertime + mOnCall + mBonus + mCommission);
			
			let temp = {
				totalCosts: mBase + mOvertime + mOnCall + mBonus + mOther + (welfare * percentageOfDaysWorked) + mPension + (statutoryPay * percentageOfDaysWorked) + mCommission + calculateEmployersNI(forErsNI,month),
				base: mBase,
				overtime: mOvertime,
				onCall: mOnCall,
				bonus: mBonus,
				other: mOther,
				welfare: welfare * percentageOfDaysWorked,
				pension: mPension,
				statutoryPay: statutoryPay * percentageOfDaysWorked,
				commission: mCommission,
				employeeCosts: employeeCosts * percentageOfDaysWorked,
				employersNI: calculateEmployersNI(forErsNI,month),
				type: type
			};
			
			// Apply any overrides from userOutturn
			let matchingOutturns = userOutturn.filter(u =>
				u.library === 'lib_resources' &&
				u.ref == resource.ref &&
				u.outturn && u.outturn[month]
			);
			
			// Replace all matching entries with those from the userOutturn list (taken from the user table)
			matchingOutturns.forEach(entry => {
				let override = entry.outturn[month];
				Object.keys(override).forEach(key => {
					if (temp.hasOwnProperty(key)) {
						temp[key] = override[key];
					}
				});
			});
			
			// Now that it has been through, found the matches and updated the temp holder, it's time to recalculate the employer's NIC. First, I need to get the components
			let adjustedIncomeForErsNI = temp['base'] + temp['overtime'] + temp['onCall'] + temp['bonus'] + temp['other'] + temp['commission'];
			// Then pass them to the function to update the Employers NIC held in temp
			temp['employersNI'] = calculateEmployersNI(adjustedIncomeForErsNI,month);
			
			// Now, just need to output the temp into the relevant month for the resource
			resource['outturn'][month] = temp;
		});
	});

	// Process roles
	roles.forEach(role => { // Go through each of the objects in roles
		
		role['outturn'] = []; // create an array for each resource, called outturn: this is where the outturn will be stored

		let salary = scrub(role.benchmarkSalary);
		let monthlySalary = Math.round((salary / 12) * 100) / 100; // Calculate the monthly salary
		let overtime = calculateResourceWeightedHistory(role, 'overtime');  
		let onCall = calculateResourceWeightedHistory(role, 'onCall');
		let bonus = calculateResourceWeightedHistory(role, 'bonus');
		let other = calculateResourceWeightedHistory(role, 'other');
		let welfare = calculateResourceWeightedHistory(role, 'welfare');
		let statutoryPay = calculateResourceWeightedHistory(role, 'statutoryPay');
		let commission = calculateResourceWeightedHistory(role, 'commission');
		let employeeCosts = calculateResourceWeightedHistory(role, 'employeeCosts');
		let startDate = new Date(role.startDate);
		let endDate = new Date(role.endDate);
			endDate.setHours(23, 59, 59, 999);
		let actualMonths = scrub(getCookie('aMonths') ?? 7); // Gets the number of actual months required or defaults to 7 if there is not set number
		let outturnMonths = 60; // Projects 5 years into the future, being 60 months
		let type = 'outturn'; // Type is always outturn
		let roleStarted = 0; // a flag for if the resource has started: default is 0 (off)
		let roleLeft = 0; // a flag for if the resource has left: default is 0 (off)

		let monthArray = generateMonthArray(0, actualMonths, outturnMonths); // Generates the month array based off of the users' actual month ands outturn months (e.g. 3 past; 1 present; 6 future == 10)

		monthArray.forEach(month => { // Go through each of the months
			let monthISO = new Date(convertToLastDay(month)); // Take the month that I'm looking at (e.g. Apr-25) and convert it into a standard date on the last day of the month (e.g. '2025-04-30 23:59:59)
			let result = calculatePercentageWorked(startDate, endDate, monthISO, roleStarted, roleLeft);
			roleStarted = result.started; // Pulls the started value from the function so that we can check if they have started or not
			roleLeft = result.left; // Pulls the left value from the function so that we can check if they have finished or not
			let percentageOfDaysWorked = result.percentage; // Pulls the percentage (to be applied to metrics) from the function
			
			// Need to calculate these so they can be passed to calculateEmployersNI cleanly
			let mBase = monthlySalary * percentageOfDaysWorked;
			let mOvertime = overtime * percentageOfDaysWorked;
			let mOnCall = onCall * percentageOfDaysWorked;
			let mBonus = bonus * percentageOfDaysWorked;
			let mOther = other * percentageOfDaysWorked;
			let mCommission = commission * percentageOfDaysWorked;
			
			// Now bring them together so that we have a value to pass to the calculation
			let forErsNI = mBase + mOvertime + mOnCall + mBonus + mOther + mCommission;
			
			// Pension at 4% of eligible pay components (exclude 'other', welfare, etc.)
			let mPension = 0.04 * (mBase + mOvertime + mOnCall + mBonus + mCommission);
			
			let temp = {
				totalCosts: mBase + mOvertime + mOnCall + mBonus + mOther + (welfare * percentageOfDaysWorked) + mPension + (statutoryPay * percentageOfDaysWorked) + mCommission + calculateEmployersNI(forErsNI,month),
				base: mBase,
				overtime: mOvertime,
				onCall: mOnCall,
				bonus: mBonus,
				other: mOther,
				welfare: welfare * percentageOfDaysWorked,
				pension: mPension,
				statutoryPay: statutoryPay * percentageOfDaysWorked,
				commission: mCommission,
				employeeCosts: employeeCosts * percentageOfDaysWorked,
				employersNI: calculateEmployersNI(forErsNI,month),
				type: type
			};
			
			// Apply any overrides from userOutturn
			let matchingOutturns = userOutturn.filter(u =>
				u.library === 'roles' &&
				u.ref == role.ref &&
				u.outturn && u.outturn[month]
			);
			
			// Replace all matching entries with those from the userOutturn list (taken from the user table)
			matchingOutturns.forEach(entry => {
				let override = entry.outturn[month];
				Object.keys(override).forEach(key => {
					if (temp.hasOwnProperty(key)) {
						temp[key] = override[key];
					}
				});
			});

			// Now that it has been through, found the matches and updated the temp holder, it's time to recalculate the employer's NIC. First, I need to get the components
			let adjustedIncomeForErsNI = temp['base'] + temp['overtime'] + temp['onCall'] + temp['bonus'] + temp['other'] + temp['commission'];
			// Then pass them to the function to update the Employers NIC held in temp
			temp['employersNI'] = calculateEmployersNI(adjustedIncomeForErsNI,month);
			
			// Now, just need to output the temp into the relevant month for the resource
			role['outturn'][month] = temp;
		});
	});
}

function eoMonth(iterator) { // function to return the last day of the last complete month
	// Convert input to number or default to 0
	let offset = Number(scrub(iterator));
	if (isNaN(offset)) {
		offset = 0;
	}

	const today = new Date();

	if (offset === 0) {
		// Go to the first of this month
		const firstOfThisMonth = new Date(today.getFullYear(), today.getMonth(), 1);
		// Subtract one day = last day of previous month
		return new Date(firstOfThisMonth - 1);
	}

	// Get the first day of the month AFTER the offset month
	const nextMonth = new Date(today.getFullYear(), today.getMonth() + offset + 1, 1);
	// Subtract one day = last day of offset month
	return new Date(nextMonth - 1);
}

function convertDateToMMMYY(date) {
	// Ensure date is a Date object
	const d = new Date(date);

	if (isNaN(d)) {
		console.warn("Invalid date passed to convertDateToMMMYY:", date);
		return '';
	}

	const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	const formattedMonth = `${months[d.getMonth()]}-${d.getFullYear().toString().slice(-2)}`;
	return formattedMonth;
}

function generateMonthArray(offsetValue, actualValue, outturnValue) {
	const offset = Number(offsetValue) || 0;
	const actual = Number(actualValue) || 7;
	const outturn = Number(outturnValue) || 6;

	const startMonthIndex = -actual + offset;
	const endMonthIndex = outturn + offset;
	const monthArray = [];

	const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	const currentDate = new Date();

	for (let i = startMonthIndex; i <= endMonthIndex; i++) {
		const newDate = new Date(currentDate.getFullYear(), currentDate.getMonth() + i, 1);
		const formattedMonth = `${months[newDate.getMonth()]}-${newDate.getFullYear().toString().slice(-2)}`;
		monthArray.push(formattedMonth);
	}

	return monthArray;
}

function destroyMenu(menuName){
	var menu = document.getElementById(menuName);
	menu.parentNode.removeChild(menu);
	if(menuName == 'employeeMenu'){
		document.getElementById("addEmployee").innerHTML = '<button onclick="addEmployeeMenu();" id="addEmployeeButton">+</button>';
	}
}

function updateEmployee(selectedNumber, type){ // This is the one that actually updates the employee
	let typeText = 'Employee';
	let updateReference = Number(scrub(selectedNumber));
	
	if(type == 'resources'){
		setCookie('updateResource',selectedNumber,1);
	} else {
		setCookie('updateRole',selectedNumber,1);
		typeText = 'Role';
	}
	
	x = document.getElementById('arrayRef').value;
	
	let start_date = scrub(document.getElementById('start_date').value);
	let end_date = scrub(document.getElementById('end_date').value);
	let annual_salary = scrub(document.getElementById('annual_salary').value);
	let fte = scrub(document.getElementById('fte').value);
	let departmentNumber = scrub(document.getElementById('department').value);
	let department ='Unallocated';
	let contractType = scrub(document.getElementById('contract_type').value);
	let fullName = '';
	
	for (a = 0; a < departments.length; a++){
		if(departments[a].ref == departmentNumber) {
			department = departments[a].department;
		}
	}
	
	if(type=='resources'){
		lib_resources[x].start_date = start_date;
		lib_resources[x].end_date = end_date;
		lib_resources[x].annual_salary = annual_salary;
		lib_resources[x].fte = fte;
		lib_resources[x].departmentNumber = departmentNumber;
		lib_resources[x].departmentName = department;
		lib_resources[x].contractType = contractType;
		fullName = lib_resources[x].firstname + " " + lib_resources[x].surname;
	} else {
		roles[x].startDate = start_date;
		roles[x].endDate = end_date;
		roles[x].benchmarkSalary = annual_salary;
		roles[x].benchmarkFTE = fte;
		roles[x].department = departmentNumber;
		roles[x].departmentName = department;
		roles[x].contractType = contractType;
		fullName = roles[x].jobTitle;
	}

	if(type == 'resources'){
		// Use Ajax to send to the script rather than passing cookies:
		fetch("/scripts/updateEmployee.php", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-CSRF-Token": window.csrfToken // <-- include the token
			},
			body: JSON.stringify({
				updateResource: updateReference,
				startDate: start_date,
				endDate: end_date,
				annualSalary: annual_salary,
				fte: fte,
				departmentNumber: departmentNumber,
				contractType: contractType,
			})
		})
	} else {
		// Use Ajax to send to the script rather than passing cookies:
		fetch("/scripts/updateRole.php", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-CSRF-Token": window.csrfToken // <-- include the token
			},
			body: JSON.stringify({
				updateRole: updateReference,
				startDate: start_date,
				endDate: end_date,
				annualSalary: annual_salary,
				fte: fte,
				departmentNumber: departmentNumber,
				contractType: contractType,
			})
		})
	}
	
	let selectedResourceArrayReference = document.getElementById('arrayRef').value;
	let selectedResourceTableReference = selectedNumber;
	
	destroyMenu('menuContainer');
	alert(typeText + " updated");
	populateResourceOutturn();
	createTable();
	createSummaryTable();
	
}

function deleteRoleResource(selectedNumber, type){
	let typeText = 'Employee';
	arrayRef = document.getElementById('arrayRef').value;
	setCookie('destroyEmployee',selectedNumber,1);
	if(type == 'resources'){
		setCookie('destroyEmployee',selectedNumber,1);
		$("#empty").load("/scripts/deleteEmployee.php");
		resources.splice(arrayRef,1);
	} else {
		typeText = 'Role';
		setCookie('destroyRole',selectedNumber,1)
		$("#empty").load("/scripts/deleteRole.php")
		roles.splice(arrayRef,1);
	}
	
	destroyMenu('menuContainer');
	
	alert(typeText + " removed");
	
	createTable();
	
}


// ------------------------------
// FILTER POPULATION
// ------------------------------

// ‼️ This is required. It is not called in .js files, though, it is called in .php files
function changeContractTypeView(){
	contractType = document.getElementById('contractType').value;
	
	setCookie('contractType', contractType);
	
	createTable();
	createSummaryTable();
	
}

// ‼️ This is required. It is not called in .js files, though, it is called in .php files
async function changeDepartmentView() {
	const selectedDepartment = document.getElementById('departmentDisplaySelector').value;
	setCookie('department', selectedDepartment);

	try {
		const res = await fetch('/scripts/getResourcesRoleFinancials.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-Token': window.csrfToken
			},
			body: JSON.stringify({ department: selectedDepartment })
		});
		const data = await res.json();

		if (data.status === 'success') {
			populateAllFromJson(data);
			await loadPayRises();            // <— was `return loadPayRises();`
			applyRolesToEmployees();
			applyDepartments();
			allocateForecast();
			createTable();
			createSummaryTable();
		}
	} catch (err) {
		console.error('[changeDepartmentView] failed:', err);
	}
}

function changeForecastView(){
  forecastRows = [];
  const idx = scrub(document.getElementById("forecastSelect").value);

  const forecastPrefix  = scrub(forecasts[idx].actualForecast);
  const forecastName    = scrub(forecasts[idx].forecastName);
  const forecastVersion = scrub(forecasts[idx].forecastVersion);

  setCookie('forecastPrefix', forecastPrefix);
  setCookie('forecastName',  forecastName);
  setCookie('forecastVersion', forecastVersion);

  fetch('/scripts/getForecast.php', {
	credentials: 'same-origin',
	headers: { 'Accept': 'application/json' }
  })
  .then(r => r.text())
  .then(raw => {
	const data = raw ? JSON.parse(raw) : {};
	window.forecastRows = data;
	allocateForecast();
	createTable();
	createSummaryTable();
  })
  .catch(err => console.error('[changeForecastView] failed:', err));
}

// 🗑️ CAN THIS BE DELETED? @26/10/2025
function actionAddEmployee(){ // Script to add the employee to the database
	
	// CLEAN UP THE USER INPUTS
	let newFirstName = scrub(document.getElementById("newFirstName").value);
	let newSurname = scrub(document.getElementById("newSurname").value);
	let newSalary = scrub(document.getElementById("newSalary").value);
	let newStartDate = scrub(document.getElementById("newStartDate").value);
	
	// SET THE COOKIES
	setCookie('newFirstName',newFirstName,0);
	setCookie('newSurname',newSurname,0);
	setCookie('newSalary',newSalary,0);
	setCookie('newStartDate',newStartDate,0);
	
	document.getElementById("addEmployee").innerHTML = '<button onclick="addEmployeeMenu();" id="addEmployeeButton">+</button>';
	employeeDetailsTable = []; // Resets the employeeDetailsTable so that it free to repopulate
	
	$('#empty').load("/scripts/addEmployee.php");
	$('#empty').load("/scripts/getEmployeesFinancials.php");
	refreshTable();

}

function populateDepartmentOptions() { // ‼️ Chat GPT Generated
	// 1. make sure the target element exists
	var sel = document.getElementById("departmentDisplaySelector");
	if (!sel) {
		// nothing to populate on this page
		return;
	}

	// 2. get whatever was selected last time
	var selectedDepartment = getCookie("department");
	// default to '0' (All)
	if (selectedDepartment == null || selectedDepartment === "") {
		selectedDepartment = "0";
	}

	// 3. start building options
	var optionsHtml = "<option value='0'>All</option>";

	// if we don't have departments yet, just show "All" and bail
	if (!Array.isArray(departments) || departments.length === 0) {
		sel.innerHTML = optionsHtml;
		return;
	}

	// 4. build each department option
	for (var i = 0; i < departments.length; i++) {
		var dep = departments[i];
		if (!dep) continue;

		var ref = String(dep.ref);
		var label = dep.department;

		if (ref === String(selectedDepartment)) {
			optionsHtml += "<option value='" + ref + "' selected='selected'>" + label + "</option>";
		} else {
			optionsHtml += "<option value='" + ref + "'>" + label + "</option>";
		}
	}

	// 5. drop into DOM
	sel.innerHTML = optionsHtml;
}

function populateForecastOptions() { // ‼️ Chat GPT Generated
	// 1. make sure the target element exists
	var sel = document.getElementById("forecastSelect");
	if (!sel) {
		// page doesn't have the select, just bail
		return;
	}

	// 2. if we don't have forecasts yet, just show a placeholder
	if (!Array.isArray(forecasts) || forecasts.length === 0) {
		sel.innerHTML = "<option value=''>No forecasts yet</option>";
		return;
	}

	// 3. try to restore user's choice from cookie
	var cookieVal = getCookie("selectedForecast"); // this might be a REF, might be empty
	var selectedRef = cookieVal ? String(cookieVal) : null;

	// default: last forecast in the array
	var last = forecasts[forecasts.length - 1];
	var defaultRef = String(last.ref);

	// 4. build options
	var html = "";
	for (var i = 0; i < forecasts.length; i++) {
		var f = forecasts[i];
		if (!f) continue;

		var ref  = String(f.ref); // what we store in value=""
		var text = f.actualForecast + " - " + f.forecastName + " " + f.forecastVersion;

		// pick selected:
		//  - if cookie matches this ref, use that
		//  - else if no cookie, pick the last forecast
		var isSelected = false;
		if (selectedRef) {
			isSelected = (ref === selectedRef);
		} else {
			isSelected = (ref === defaultRef);
		}

		html += "<option value='" + ref + "'" + (isSelected ? " selected='selected'" : "") + ">" + text + "</option>";
	}

	sel.innerHTML = html;

	// 5. write back the selection so subsequent fetches know which one
	var finalVal = sel.value;
	if (finalVal) {
		setCookie("selectedForecast", finalVal);
		// also keep the three separate ones in sync if you want to reuse your existing getForecast.php logic
		var picked = forecasts.find(function (f) { return String(f.ref) === String(finalVal); });
		if (picked) {
			setCookie('forecastPrefix',  picked.actualForecast);
			setCookie('forecastName',   picked.forecastName);
			setCookie('forecastVersion',picked.forecastVersion);
		}
	}
}

async function actionAddRole(){
// Adds a new role to the database

	// Takes the inputs the user has entered and then cleans them to remove any malicious code
	let newRole = scrub(document.getElementById("newRole").value);
	let department = scrub(document.getElementById("department").value);
	let fte = scrub(document.getElementById("fte").value);
	let salary = scrub(document.getElementById("salary").value);
	let proRataSalary = salary / fte;
	let startDate = scrub(document.getElementById("startDate").value);
	let endDate = document.getElementById("endDate").value;
	let departmentName = '';
	let contractType = scrub(document.getElementById("contractType").value);
	
	departments.forEach(department => {
		if(department.ref==department){
			departmentName = department.department;
		}
	});
	
	if (contractType == null){
		contractType = 1;
	}
	
	if (newRole == ''){
		alert(`The Job Title is empty.\n\nPlease enter a Job Title.`);
		return;
	} else {
	
		try {
			const cRoleFetch = await fetch('/scripts/addRoleFetch.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-Token': window.csrfToken
				},
				body: JSON.stringify({
					newRole			:	newRole,
					department		:	department,
					fte				: 	fte,
					proRataSalary	:	proRataSalary,
					salary			:	salary,
					startDate		:	startDate,
					endDate			:	endDate,
					contractType	:	contractType
				})
			})
			
			const data = await cRoleFetch.json();
			
			if (data.status === 'success') {
				roles.push({
					benchmarkFTE: fte,
					benchmarkProrataSalary: proRataSalary,
					benchmarkSalary: salary,
					contractType: contractType,
					department: department,
					departmentName: departmentName,
					filledReference: 0,
					jobTitle: newRole,
					ref: Number(departments[departments.length - 1].ref) + 1,
					startDate: startDate,
					status: 4,
					tableRef: departments.length
				});
				
				try{
					applyRolesToEmployees();
					applyDepartments();
					
					// legacy call still used in UI
					$('#empty').load('/scripts/getDepartments.php');
					
					allocateForecast();
					allocateRoles();
					populateForecastOptions();
					createTable();
					if (typeof window.createSummaryTable === 'function') {
						window.createSummaryTable();
					}
					
					alert(`${newRole} has been added.`);
					
				} catch (e) {
				console.error('[monthlyOutturn] init failed:', e);
				}
			}
			
		} catch (e) {
			console.error(e);
			alert(`There was an error creating ${newRole}.`)
		}
	}
}

function actionAddDepartment(){
// Adds the new department to the database
	
	let newDepartment = scrub(document.getElementById("newDepartmentName").value);
	let matchFlag = 0; // if this is zero, not match has been found; if it is 1, a match has been found
	
	// Check the new department against those that already exist; if it does, set the matchFlag to 1
	for (x = 0; x < departments.length; x++) {
		if (departments[x].department.toLowerCase() == newDepartment.toLowerCase()) { // toLowerCase in order to ensure that capitalisation doesn't affect the matching
			matchFlag = 1;
		}
	}
	
	if (matchFlag != 1){ // Check if the newDepartment has been matched and, if it hasn't, add it to the database
		
		// Set the cookies to be passed over to PHP
		setCookie('newDepartment',newDepartment,0);
		setCookie('FAILFLAG','0');
		
		// create an object with the reference and department as its properties
		tempDep = {ref: departments.length + 1, department: newDepartment};
		
		// Loads the insert routine and then clears the cookies
		$('#empty').load("/scripts/addDepartment.php");
		
		// gets the status of the insert from the PHP script
		failFlag = scrub(getCookie('FAILFLAG'));
		
		if(failFlag == '1'){ // If the PHP script highlighted that this department already existed, then tell the user
			
			alert('That Department already exists, please try again with a different Department name.');
		
		} else { // Otherwise, everything was good and we want to remove the menu, add the department to the departments array (for future selection) and tell the user it was good
			
			destroyMenu('menuContainer'); // close the menu
			departments.push(tempDep); // add the department to the array
			alert('Department successfully added.') // Tell the user that all was good
			
		}
		
		fadeLoadContent("contentView","/pages/staffCastApp.php",200,500);
		
	}else { // If a match was made, tell the user this
		
		alert("That Department already exists.");
	}
}

function addRoleMenu(){
	
	// removeElement("addEmployeeButton");
	
	var menuExists = document.getElementById("roleMenu");
	if (menuExists != null){
		destroyMenu('roleMenu');
	}
	roleMenu = document.createElement("div");
	roleMenu.id="roleMenu";
	
	departmentSelector = "";
	
	for (a = 0; a < departments.length; a++) {
		departmentSelector = departmentSelector + "<option value='" + departments[a].ref + "'>" + departments[a].department + "</option>";
	}
	
	document.getElementById("contentView").appendChild(roleMenu);
	document.getElementById("roleMenu").innerHTML = ""+
	"<div id='draggableRoleMenuHeader'><strong>Add Role</strong><button onClick='destroyMenu(`roleMenu`);'><strong>X</strong></button></div>"+
	"<div><label>Type:</label>"+
		"<select name='type' id='type'>"+
			"<option value='Employee'>Employee</option>"+
			"<option value='Contractor'>Contractor</option>"+
			"<option value='Agency'>Agency</option>"+
		"</select>"+
	"</div>"+
	"<div><label>Job Title:</label><input type='text' value ='' id='newRole'></div>"+
	"<div><label>Department:</label><select name='department' id='department'>" + departmentSelector + "</select></div>"+
	"<div><label>FTE:</label><input type='text' value ='' id='fte'></div>"+
	"<div><label>Salary:</label><input type='text' value ='' id='salary'></div>"+
	"<div><label>Start Date:</label><input type='date' value ='' id='startDate'></div>"+
	"<div><label>End Date:</label><input type='date' value ='' id='endDate'></div>"+
	"<button onclick='actionAddRole();'>Add Role</button>";
	document.getElementById('newRole').focus();
}


// ------------------------------
// TEMPLATES
// ------------------------------

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

function Department(ref,department){ // Template for New Department ? Should I just create a temp in the php function to not require this
	this.ref = ref;
	this.department = department;
}

function ForecastList(ref, actualForecast, forecastName, forecastVersion){
	this.ref = ref;
	this.actualForecast = actualForecast;
	this.forecastName = forecastName;
	this.forecastVersion = forecastVersion;
}

function Employee(id,firstname,surname,start_date,end_date,annual_salary,fte,arrayRef,departmentNumber,actuals){ // Object to be used as template for employees
	this.id = id;
	this.firstname = firstname;
	this.surname = surname;
	this.start_date = start_date;
	this.end_date = end_date;
	this.annual_salary = annual_salary;
	this.fte = fte;
	this.name = firstname + " " + surname;
	this.departmentNumber = departmentNumber;
	this.arrayRef = arrayRef;
}

function Type(ref,type,payTypeGroupRef,value){
	this.ref = ref;
	this.type = type;
	this.payTypeGroupRef = payTypeGroupRef;
	this.value = value;
}

function PayTypeGroup(ref,type,value){
	this.ref = ref;
	this.type = type;
	this.value = value;
}

function Role(ref,jobTitle,department,filledReference,status,benchmarkFTE,benchmarkSalary,benchmarkProrataSalary,startDate,endDate,contractType,pension,tableRef){
	this.ref = ref;
	this.jobTitle = jobTitle;
	this.department = department;
	this.filledReference = filledReference;
	this.status = status;
	this.benchmarkFTE = benchmarkFTE;
	this.benchmarkSalary = benchmarkSalary;
	this.benchmarkProrataSalary = benchmarkProrataSalary;
	if(startDate==null){
		this.startDate = "2020-01-01"
	}else{
		this.startDate = startDate;
	}
	this.endDate = endDate ?? '9999-12-31';
	this.contractType = contractType;
	this.pension = Number(parseFloat(pension).toFixed(9));
	this.tableRef = tableRef;
}

function monthlyValues(month,base){ // DEPRECATED? Object to be used as template for month and base
	this.month = month;
	this.base = base;
}

function togglePassword() { // Used by login.php
  // try the real ID first
  const pwd = document.getElementById('pass') || document.getElementById('password');
  const btn = document.querySelector('.password-toggle');
  if (!pwd || !btn) return;

  const start = pwd.selectionStart, end = pwd.selectionEnd;
  const show = (pwd.type === 'password');

  pwd.type = show ? 'text' : 'password';
  btn.textContent = show ? 'Hide' : 'Show';

  try { pwd.setSelectionRange(start, end); } catch (e) {}
  pwd.focus();
}

// ------------------------------
// Lazy loading of Chart.js
// ------------------------------

let _chartJsPromise;

function ensureChartJs(src = "https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js") {
  if (_chartJsPromise) return _chartJsPromise;           // already loading/loaded
  if (window.Chart) return Promise.resolve();            // already present

  _chartJsPromise = new Promise((resolve, reject) => {
	const s = document.createElement('script');
	s.src = src;
	s.async = true;
	s.onload = () => resolve();
	s.onerror = () => reject(new Error('Failed to load Chart.js'));
	document.head.appendChild(s);
  });
  return _chartJsPromise;
}

// ------------------------------
// Registration JavaScript
// ------------------------------

function updatePasswordStrength (pw) {
	const fill = document.getElementById('strengthFill');
	const label = document.getElementById('strengthLabel');
	
	let score = 0;
	if (!pw) score = 0;
	else {
		if (pw.length >= 8) score++;
		if (/[A-Z]/.test(pw)) score++;
		if (/[a-z]/.test(pw)) score++;
		if (/\d/.test(pw)) score++;
		if (/[^A-Za-z0-9]/.test(pw)) score++;
		if (pw.length >= 12) score++;
	}
	const pct = Math.min(100, score * (100/6));
	fill.style.width = pct + '%';
	
	let color = '#ef4444', text = 'Weak';
	if (pct >= 66) { color = '#16a34a'; text = 'Strong'; }
	else if (pct >= 33) { color = '#f59e0b'; text = 'Fair'; }
	fill.style.background = color;
	label.textContent = pw ? ('Strength: ' + text) : 'Strength: —';
};

function showRegistrationSuccess(msg) {
	const el = document.getElementById('registrationMessage');
	el.className = 'msg-success';
	el.textContent = msg || 'Success.';
}

function showRegistrationError(msg) {
	const el = document.getElementById('registrationMessage');
	el.className = 'msg-error';
	el.textContent = msg || 'Something went wrong.';
}

function updateYearEndSetting(){
	const monthList = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	const monthNo = parseInt(document.getElementById('yearEndSelect').value,10);
	
	// Run the PHP to upload the month into the database
	fetch("/scripts/updateYearEndSetting.php", {
		method: "POST",
		headers: {
			"Content-Type": "application/json",
			"X-CSRF-Token": window.csrfToken
		},
		body: JSON.stringify({
			monthNo: monthNo,
			month: monthList[monthNo - 1]
		})
	})
	.then(res => res.json())
	.then(data => {
		if (data.status === "success") {
			alert(`Year end changed to ${monthList[monthNo - 1]}`);
		}
	});
}

function updateFirstYearSetting(){
	const firstYear = parseInt(document.getElementById('firstYearSelect').value,10);
	
	// Run the PHP to upload the month into the database
	fetch("/scripts/updateFirstYearSetting.php", {
		method: "POST",
		headers: {
			"Content-Type": "application/json",
			"X-CSRF-Token": window.csrfToken
		},
		body: JSON.stringify({
			firstYear: firstYear,
		})
	})
	.then(res => res.json())
	.then(data => {
		if (data.status === "success") {
			alert(`Established year changed to ${firstYear}`);
		}
	});
}

// ---- DETERMINE WHAT THE USER CAN SEE ----
async function returnUserAccessLevel() {
	try {
		const res = await fetch("/scripts/getUserAccessLevel.php", {
			method: "POST",
			headers: {
				"X-CSRF-Token": window.csrfToken
			}
		});

		if (!res.ok) {
			console.error("Bad response getting access level");
			return 0;
		}

		// PHP sends a JSON number, e.g. 3
		const data = await res.json();
		const level = Number(data) || 0;
		return level;

	} catch (err) {
		console.error("Error fetching access level:", err);
		return 0;
	}
}

</script>
