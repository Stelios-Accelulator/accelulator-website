// =========================
// StaffCast App JS (full)
// =========================

// ------------ Sources ------------
const resources    = window.lib_resources || [];   // employees array
const roles        = window.roles || [];           // roles array
const forecastRows = window.forecastRows || {};    // { resource: { [id]: { "Mon-YY": { base, employersNI, ... } } } }

// ------------ Helpers ------------
const toNum = v => (v == null || v === '' ? 0 : parseFloat(v));

function add(map, month, amount) {
  if (!month) return;
  map[month] = (map[month] || 0) + (amount || 0);
}

// Map UI pay-type control to data key
function mapPayType(val) {
  const v = String(val || '').toLowerCase();
  // numeric values from select? fall back to common ids
  if (v === '1' || v === 'base') return 'base';
  if (v === '2' || v === 'overtime') return 'overtime';
  if (v === '3' || v === 'oncall' || v === 'on-call' || v === 'on_call') return 'onCall';
  if (v === '4' || v === 'employersni' || v === 'employers_ni' || v === 'employer\'s ni') return 'employersNI';
  if (v === 'total' || v === '0') return 'total';
  // also handle capitalised labels from UI
  if (/^base$/i.test(val)) return 'base';
  if (/^overtime$/i.test(val)) return 'overtime';
  if (/^on[-_\s]?call$/i.test(val)) return 'onCall';
  if (/^employers?[-_\s]?ni$/i.test(val)) return 'employersNI';
  return 'base';
}

function valForPayType(monthObj, payType) {
  if (!monthObj) return 0;
  const key = mapPayType(payType);
  if (key === 'total') {
	let s = 0;
	for (const v of Object.values(monthObj)) s += toNum(v);
	return s;
  }
  return toNum(monthObj[key]);
}

// Parse "Mon-YY" to Date (UTC 1st of month)
function parseMonYY(key) {
  const [monStr, yyStr] = String(key).split('-');
  if (!monStr || !yyStr) return null;
  const M = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
  const m = M.indexOf(monStr);
  if (m < 0) return null;
  const year = 2000 + parseInt(yyStr, 10);
  const d = new Date(Date.UTC(year, m, 1));
  return isNaN(d.getTime()) ? null : d;
}

function latestMonthKeyFromMaps(...maps) {
  let latest = null;
  for (const m of maps) {
	for (const k of Object.keys(m || {})) {
	  const d = parseMonYY(k);
	  if (!d) continue;
	  if (!latest || d > latest.d) latest = { k, d };
	}
  }
  return latest ? latest.k : null;
}

function monYYFromDate(d){
  const M = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
  return `${M[d.getUTCMonth()]}-${String(d.getUTCFullYear()).slice(2)}`;
}

function isValidMonYY(s){
  return /^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)-\d{2}$/.test(String(s || '').trim());
}

// ------------ Summation from your structures ------------

// lib_resources[*][bucket] where bucket is 'actuals' | 'outturn' | 'forecast'
// shape per-month: { "Mon-YY": { base, employersNI, overtime?, onCall? } } or [] when empty
function sumFromLibResources(bucket, payType) {
  const totals = {};
  for (const r of (window.lib_resources || [])) {
	const mobj = r && r[bucket];
	if (!mobj || Array.isArray(mobj)) continue;
	for (const [mon, rec] of Object.entries(mobj)) add(totals, mon, valForPayType(rec, payType));
  }
  return totals;
}

// forecastRows.resource[id][month] -> same inner shape
function sumFromForecastRows(payType) {
  const totals = {};
  const res = (window.forecastRows && window.forecastRows.resource) ? window.forecastRows.resource : {};
  for (const empId of Object.keys(res)) {
	const months = res[empId] || {};
	for (const [mon, rec] of Object.entries(months)) add(totals, mon, valForPayType(rec, payType));
  }
  return totals;
}

// roles: include outturn ONLY when unfilled (filledReference == 0/"0"/falsy)
function sumFromRolesOutturn(payType) {
  const totals = {};
  for (const role of (window.roles || [])) {
	const filledRef = role?.filledReference;
	const isUnfilled = filledRef === 0 || filledRef === '0' || filledRef === '' || filledRef == null;
	if (!isUnfilled) continue;
	const mobj = role?.outturn;
	if (!mobj || Array.isArray(mobj)) continue;
	for (const [mon, rec] of Object.entries(mobj)) add(totals, mon, valForPayType(rec, payType));
  }
  return totals;
}

// Build Actuals / Outturn / Forecast maps
function buildThreeMaps(payType) {
  const actuals = sumFromLibResources('actuals',  payType);   // currently may be empty
  const outEmp  = sumFromLibResources('outturn',  payType);
  const outRol  = sumFromRolesOutturn(payType);
  const outturn = {};
  for (const k of new Set([...Object.keys(outEmp), ...Object.keys(outRol)])) {
	outturn[k] = (outEmp[k] || 0) + (outRol[k] || 0);
  }

  const fcA = sumFromLibResources('forecast', payType);
  const fcB = sumFromForecastRows(payType);
  const forecast = {};
  for (const k of new Set([...Object.keys(fcA), ...Object.keys(fcB)])) {
	forecast[k] = (fcA[k] || 0) + (fcB[k] || 0);
  }

  return { actuals, outturn, forecast };
}

// ------------ Cookies / inputs ------------
function _getCookie(name) {
  const m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)');
  return m ? decodeURIComponent(m[1]) : null;
}
const readCookie = (n) => (typeof getCookie === 'function' ? getCookie(n) : _getCookie(n));

function getIntFromInputOrCookie(inputEl, cookieName, fallback) {
  if (inputEl && inputEl.value !== '') {
	const n = parseInt(inputEl.value, 10);
	if (Number.isFinite(n)) return n;
  }
  const c = readCookie(cookieName);
  const n = parseInt(c, 10);
  return Number.isFinite(n) ? n : fallback;
}

// ------------ Selected month resolver ------------
function resolveSelectedMonth(actuals, outturn, forecast){
  const monthEl = document.getElementById('months');
  if (monthEl && monthEl.selectedIndex >= 0) {
	const txt = monthEl.options[monthEl.selectedIndex].text.trim(); // use option TEXT
	if (isValidMonYY(txt)) return txt;
  }
  const fromData = latestMonthKeyFromMaps(actuals, outturn, forecast);
  if (isValidMonYY(fromData)) return fromData;
  // fallback: last full month
  const now = new Date();
  const lastFull = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - 1, 1));
  return monYYFromDate(lastFull);
}

// ------------ Chart renderer ------------
let peopleChart;

function renderWithChartJs(payload) {
  const el = document.getElementById('peopleCostsChart');
  if (!el) { console.warn('peopleCostsChart canvas not found'); return; }

  const ctx    = el.getContext('2d');
  const labels = payload.labels || [];
  const bars   = payload.bar || [];
  const isAct  = payload.isActual || [];
  const fc     = payload.forecast || [];
  const cutoff = payload.cutoff || '';

  const BOLD = 'rgba(25,118,210,1)';
  const PALE = 'rgba(25,118,210,0.35)';
  const bg   = isAct.map(a => a ? BOLD : PALE);

  const gbp = v => '£' + (v ?? 0).toLocaleString('en-GB', { maximumFractionDigits: 0 });

  const cfg = {
	type: 'bar',
	data: {
	  labels,
	  datasets: [
		{ label: 'People Cost', data: bars, backgroundColor: bg, borderColor: bg, borderWidth: 1 },
		{ type: 'line', label: 'Forecast', data: fc, borderWidth: 2, pointRadius: 2, tension: 0.2 }
	  ]
	},
	options: {
	  responsive: true,
	  maintainAspectRatio: false,
	  interaction: { mode: 'index', intersect: false },
	  plugins: {
		legend: { position: 'top' },
		title:  { display: true, text: `People Cost — Actuals to ${cutoff}, Outturn after` },
		tooltip:{ callbacks:{ label:c => `${c.dataset.label}: ${gbp(c.parsed.y)}` } }
	  },
	  scales: {
		y: { beginAtZero: true, ticks: { callback: v => gbp(v) } },
		x: { ticks: { maxRotation: 0, autoSkip: true } }
	  }
	}
  };

  if (peopleChart) { peopleChart.data = cfg.data; peopleChart.options = cfg.options; peopleChart.update(); }
  else { peopleChart = new Chart(ctx, cfg); }
}

// ------------ Main slice + render ------------
async function recomputeAndSlice() {
  const payTypeEl = document.getElementById('payType');
  const payType = payTypeEl ? payTypeEl.value : 'base';

  const { actuals, outturn, forecast } = buildThreeMaps(payType);

  const selectedMonth = resolveSelectedMonth(actuals, outturn, forecast);
  const monthsBack    = getIntFromInputOrCookie(document.getElementById('monthsBack'),    'aMonths', 3);
  const monthsForward = getIntFromInputOrCookie(document.getElementById('monthsForward'), 'oMonths', 8);

  const payload = { actuals, outturn, forecast, selectedMonth, monthsBack, monthsForward };

  const res = await fetch('/api/people_costs.php', {
	method: 'POST',
	headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
	body: JSON.stringify(payload)
  });

  const txt = await res.text();
  if (!res.ok) { console.error('Slice API error', res.status, txt, '\nPayload:', payload); return; }

  const data = JSON.parse(txt);
  renderWithChartJs(data);
}

// ------------ Event wiring / init ------------
function bindControlsOnce() {
  ['payType','months','monthsBack','monthsForward'].forEach(id => {
	const el = document.getElementById(id);
	if (el && !el._scBound) { el.addEventListener('change', recomputeAndSlice); el._scBound = true; }
  });
}

// Wait for data that your modules populate
async function waitForData(maxMs = 5000) {
  const start = Date.now();
  while (Date.now() - start < maxMs) {
	const hasResources = Array.isArray(window.lib_resources) && window.lib_resources.length > 0;
	const hasForecast  = window.forecastRows && window.forecastRows.resource &&
						 Object.keys(window.forecastRows.resource).length > 0;
	if (hasResources || hasForecast) return true;
	// also refresh local references in case globals were re-assigned
	await new Promise(r => setTimeout(r, 120));
  }
  return false;
}

async function initStaffCast() {
  bindControlsOnce();
  await waitForData(); // proceed even if timeout; recompute will still run
  recomputeAndSlice();
}

// Initial kick-off (for full page loads)
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initStaffCast);
} else {
  initStaffCast();
}

// Expose for AJAX fragments (call this after your .load() completes)
window.initStaffCast = initStaffCast;