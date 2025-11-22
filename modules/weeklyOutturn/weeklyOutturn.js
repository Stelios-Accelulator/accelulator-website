// modules/weeklyOutturn/weeklyOutturn.js

// Simple global store
window.weeklyOutturnRows = []; // [{ week: 1, label: 'W1', start: '2025-01-06', end: '2025-01-12', actual: 12345, forecast: 13000 }, ...]

function clearWeeklyOutturnState() {
	window.weeklyOutturnRows = [];
}

// Take JSON from PHP and hydrate local structures
function hydrateWeeklyOutturn(data) {
	window.weeklyOutturnRows = Array.isArray(data.weeks) ? data.weeks : [];
}

// Render table + chart
function renderWeeklyOutturn() {
	const container = document.getElementById('weeklyOutturnView');
	if (!container) {
		console.warn('[weeklyOutturn] #weeklyOutturnView missing');
		return;
	}
	container.innerHTML = '';

	const rows = window.weeklyOutturnRows || [];
	if (!rows.length) {
		container.innerHTML = '<p>No weekly data available.</p>';
		renderWeeklyOutturnChart([], [], []);
		return;
	}

	// Build table
	const table = document.createElement('table');
	table.className = 'weekly-outturn-table';

	const thead = document.createElement('thead');
	const headerRow = document.createElement('tr');
	['Week', 'Start', 'End', 'Actual', 'Forecast', 'Variance'].forEach(h => {
		const th = document.createElement('th');
		th.textContent = h;
		headerRow.appendChild(th);
	});
	thead.appendChild(headerRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	let totalActual = 0;
	let totalForecast = 0;

	rows.forEach(r => {
		const tr = document.createElement('tr');

		const weekLabel = r.label || ('W' + r.week);
		const start = r.start || '';
		const end = r.end || '';
		const actual = Number(r.actual || 0);
		const forecast = Number(r.forecast || 0);
		const variance = forecast - actual;

		totalActual += actual;
		totalForecast += forecast;

		[
			weekLabel,
			start ? new Date(start).toLocaleDateString('en-GB') : '',
			end ? new Date(end).toLocaleDateString('en-GB') : '',
			Math.round(actual).toLocaleString(),
			Math.round(forecast).toLocaleString(),
			Math.round(variance).toLocaleString()
		].forEach((val, idx) => {
			const td = document.createElement('td');
			td.textContent = val;
			if (idx >= 3) td.classList.add('valueColumn');
			if (idx === 5) td.classList.add('variance');
			tr.appendChild(td);
		});

		tbody.appendChild(tr);
	});

	table.appendChild(tbody);

	// footer row
	const tfoot = document.createElement('tfoot');
	const ftr = document.createElement('tr');
	const footerCells = [
		'', 'Totals', '', '',
		Math.round(totalActual).toLocaleString(),
		Math.round(totalForecast).toLocaleString(),
		Math.round(totalForecast - totalActual).toLocaleString()
	];

	footerCells.forEach((val, idx) => {
		const td = document.createElement('td');
		td.textContent = val;
		if (idx >= 4) td.classList.add('valueColumn');
		if (idx === 6) td.classList.add('variance');
		ftr.appendChild(td);
	});
	tfoot.appendChild(ftr);
	table.appendChild(tfoot);

	container.appendChild(table);

	// chart data
	const labels = rows.map(r => r.label || ('W' + r.week));
	const actuals = rows.map(r => Number(r.actual || 0));
	const forecasts = rows.map(r => Number(r.forecast || 0));

	renderWeeklyOutturnChart(labels, actuals, forecasts);
}

// Chart.js line chart for weekly data
function renderWeeklyOutturnChart(labels, actuals, forecasts) {
	const canvas = document.getElementById('weeklyOutturnChart');
	if (!canvas) {
		console.warn('[weeklyOutturn] weeklyOutturnChart canvas not found');
		return;
	}

	const ctx = canvas.getContext('2d');

	if (window.weeklyOutturnChartInstance) {
		window.weeklyOutturnChartInstance.destroy();
	}

	window.weeklyOutturnChartInstance = new Chart(ctx, {
		type: 'line',
		data: {
			labels: labels,
			datasets: [
				{
					label: 'Actuals',
					data: actuals,
					borderWidth: 2,
					tension: 0.25
				},
				{
					label: 'Forecast',
					data: forecasts,
					borderWidth: 2,
					borderDash: [4, 4],
					tension: 0.25
				}
			]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: {
				legend: { position: 'top' },
				tooltip: { mode: 'index', intersect: false }
			},
			scales: {
				y: {
					beginAtZero: true,
					ticks: {
						callback: function (value) {
							return value.toLocaleString();
						}
					}
				}
			}
		}
	});
}

// Main loader – mirrors loadMonthlyOutturn
async function loadWeeklyOutturn() {
	if (loadWeeklyOutturn._inflight) loadWeeklyOutturn._inflight.abort?.();
	const ctrl = new AbortController();
	loadWeeklyOutturn._inflight = ctrl;

	try {
		const yearEl = document.getElementById('weeklyYear');
		const depEl  = document.getElementById('weeklyDepartment');

		const year = yearEl ? yearEl.value : (new Date().getFullYear());
		const dep  = depEl ? depEl.value : (getCookie('department') || 0);

		setCookie('department', dep);

		clearWeeklyOutturnState();

		const res = await fetch('/scripts/getWeeklyOutturnData.php', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json'
			},
			body: JSON.stringify({ year, dep }),
			signal: ctrl.signal
		});
		if (!res.ok) throw new Error(await res.text());
		const data = await res.json();
		if (!data.ok) throw new Error(data.error || 'Unknown error');

		hydrateWeeklyOutturn(data);
		renderWeeklyOutturn();
	} catch (e) {
		console.error('[weeklyOutturn] reload failed:', e);
	} finally {
		if (loadWeeklyOutturn._inflight === ctrl) loadWeeklyOutturn._inflight = null;
	}
}

// Hook up toolbar changes
document.addEventListener('change', (e) => {
	const t = e.target;
	if (!t) return;

	if (t.id === 'weeklyYear' || t.id === 'weeklyDepartment') {
		loadWeeklyOutturn();
	}
});

// Export if other modules need it
window.loadWeeklyOutturn = loadWeeklyOutturn;
window.reloadWeeklyOutturn = loadWeeklyOutturn;