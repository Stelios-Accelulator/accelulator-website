<?php
// /pages/inefficiencyTax.php
$inject = isset($_GET['inject']) && $_GET['inject'] == '1';
if($inject == 1){

}else{
	require_once("../includes/header.php");
	require_once("../includes/functions.php");
}
?>

<style>
	.st-wrap{max-width:980px;margin:0 auto;padding:1.75rem 1.25rem;}
	.st-h1{font-size:clamp(1.6rem,2.6vw,2.2rem);margin:0 0 0.5rem 0;}
	.st-sub{color:var(--muted-ink);max-width:56rem;margin:0 0 1.25rem 0;line-height:1.5;}
	.st-grid{
		display:grid;
		grid-template-columns:1fr; /* always stacked */
		gap:1rem;
		align-items:start;
	}
	.st-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:var(--shadow-2);padding:1rem;}
	.st-card h3{margin:0 0 0.75rem 0;font-size:1.05rem;}
	.st-row{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:0.75rem;}
	@media (max-width:640px){.st-row{grid-template-columns:1fr;}}
	.st-row label{font-size:0.9rem;color:#333;display:block;margin-bottom:0.35rem;}
	.st-row input,.st-row select{
		width:100%;
		padding:0.55rem 0.6rem;
		border:1px solid #e5e7eb;
		border-radius:10px;
		background:#fff;
	}
	.st-actions{display:flex;gap:0.75rem;flex-wrap:wrap;margin-top:1rem;align-items:center;}
	.st-muted{color:var(--muted-ink);font-size:0.9rem;line-height:1.45;}
	.st-kpis{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:0.75rem;}
	@media (max-width:640px){.st-kpis{grid-template-columns:1fr;}}
	.st-kpi{border:1px solid #eee;border-radius:12px;padding:0.75rem;background:#fcfcfc;}
	.st-kpi .label{font-size:0.85rem;color:var(--muted-ink);margin-bottom:0.2rem;}
	.st-kpi .value{font-size:1.3rem;font-weight:700;letter-spacing:-0.02em;}
	.st-table{width:100%;border-collapse:collapse;margin-top:0.75rem;font-size:0.92rem;}
	.st-table th,.st-table td{border-bottom:1px solid #eee;padding:0.55rem 0.35rem;vertical-align:top;}
	.st-pill{display:inline-flex;gap:0.4rem;align-items:center;padding:0.25rem 0.5rem;border-radius:999px;border:1px solid #e5e7eb;background:#fafafa;font-size:0.82rem;}
	.st-adv{margin-top:0.75rem;border-top:1px solid #eee;padding-top:0.75rem;}
	.st-adv summary{cursor:pointer;font-weight:600;}
	.st-cta{margin-top:0.75rem;padding:0.9rem;border:1px dashed #d1d5db;border-radius:12px;background:#fafafa;}
	.st-cta h4{margin:0 0 0.35rem 0;font-size:1rem;}
	.st-cta p{margin:0 0 0.75rem 0;color:var(--muted-ink);line-height:1.45;}
	.st-mini{font-size:0.82rem;color:var(--muted-ink);line-height:1.35;margin-top:0.5rem;}
	
	/* --- Mobile hardening for Inefficiency Tax --- */
	.st-wrap{
		box-sizing:border-box;
		width:100%;
		overflow-x:hidden; /* stops a single wide child from shifting the page */
	}
	
	/* Ensure all children participate in box sizing */
	.st-wrap *, .st-wrap *:before, .st-wrap *:after{
		box-sizing:border-box;
	}
	
	/* Prevent long KPI numbers / strings from forcing width */
	.st-kpi .value{
		overflow-wrap:anywhere;
		word-break:break-word;
		font-size:clamp(1.1rem, 6vw, 1.35rem);
	}
	
	/* Pills can overflow on small screens (especially inside a table cell) */
	.st-pill{
		max-width:100%;
		white-space:normal;
		overflow-wrap:anywhere;
	}
	
	/* Tables often cause horizontal scroll on mobile */
	.st-table{
		display:block;          /* allow horizontal scrolling if needed */
		width:100%;
		overflow-x:auto;
		-webkit-overflow-scrolling:touch;
	}
	
	/* Make sure table cells don’t force width */
	.st-table th, .st-table td{
		white-space:normal;
		overflow-wrap:anywhere;
	}
	
	/* Buttons should not force a row wider than the viewport */
	.st-actions{
		width:100%;
	}
	.st-actions button{
		max-width:100%;
	}
	
	/* Slightly tighter spacing on very small screens */
	@media (max-width:420px){
		.st-wrap{padding:1.25rem 0.9rem;}
		.st-card{padding:0.85rem;}
		.st-row{gap:0.6rem;}
	}
	
	@media (max-width:640px){
		/* Make the table scroll affordance obvious */
		.st-table{
			border:1px solid #eee;
			border-radius:12px;
			padding:0.25rem;
		}
	
		/* CTA button easier to hit on mobile */
		#cta .btn-primary{
			width:100%;
		}
	}
	
	@media (max-width:640px){
		/* Help the money column behave */
		#breakdownTable th:nth-child(2),
		#breakdownTable td:nth-child(2){
			white-space:nowrap;
		}
	
		/* Make rows feel less cramped */
		#breakdownTable th, #breakdownTable td{
			padding:0.7rem 0.45rem;
		}
	}
	
	@media (max-width:640px){
	
		/* Stop the “one-letter / syllable” wrapping in the Component column */
		#breakdownTable th:first-child,
		#breakdownTable td:first-child{
			overflow-wrap:normal;     /* undo anywhere */
			word-break:normal;        /* undo break-word */
			hyphens:auto;             /* nicer breaks when needed */
		}
	
		/* Give the Component column a bit more room */
		#breakdownTable th:first-child,
		#breakdownTable td:first-child{
			width:34%;
		}
	
		/* Keep Annual estimate tight */
		#breakdownTable th:nth-child(2),
		#breakdownTable td:nth-child(2){
			width:26%;
			white-space:nowrap;
		}
	
		/* Let “What it represents” take the remaining space */
		#breakdownTable{
			table-layout:fixed;
		}
	}
	
	@media (max-width:640px){
	
		/* Let the browser size columns naturally (undo our fixed layout) */
		#breakdownTable{ table-layout:auto; }
	
		/* Annual estimate should size to its content and never wrap */
		#breakdownTable th:nth-child(2),
		#breakdownTable td:nth-child(2){
			white-space:nowrap;
			width:1%;               /* “shrink-to-fit” trick */
		}
	
		/* Make the pill not behave like a flex item that can cause weird sizing */
		#breakdownTable td:nth-child(2) .st-pill{
			display:inline-block;
			white-space:nowrap;
		}
	
		/* Component: wrap normally, not inside words */
		#breakdownTable th:first-child,
		#breakdownTable td:first-child{
			overflow-wrap:normal;
			word-break:normal;
			hyphens:auto;
		}
	
		/* What it represents: wrap on words (no “anywhere” splits) */
		#breakdownTable th:nth-child(3),
		#breakdownTable td:nth-child(3){
			overflow-wrap:normal;
			word-break:normal;
			hyphens:auto;
		}
	
		/* IMPORTANT: override the earlier global "anywhere" rule for table cells */
		#breakdownTable th, #breakdownTable td{
			overflow-wrap:normal;
			word-break:normal;
		}
	}
	
</style>

<div class="st-wrap">
	<h1 class="st-h1">Inefficiency Tax Calculator</h1>
	<p class="st-sub">
		A quick estimate of your <strong>people cost decision delay</strong>.
		<br>
		Not because payroll is wrong. Because decision-ready information arrives too late to act.
		<br><span class="st-muted">This is a conservative model. Adjust assumptions to match your reality.</span>
	</p>

	<div class="st-grid">
		<div class="st-card">
			<h3>1) Enter your basics</h3>

			<div class="st-row">
				<div>
					<label>Number of employees</label>
					<input type="number" id="employees" min="1" step="1" placeholder="e.g. 200">
				</div>
				<div>
					<label>Reporting cadence</label>
					<select id="cadence">
						<option value="monthly" selected>Monthly (12 cycles)</option>
						<option value="4weekly">4-weekly (13 cycles)</option>
						<option value="quarterly">Quarterly (4 cycles)</option>
					</select>
				</div>
			</div>

			<details class="st-adv">
				<summary>Advanced assumptions (optional)</summary>

				<div class="st-row" style="margin-top:0.75rem;">
					<div>
						<label>Avg fully-loaded cost per employee (£/year)</label>
						<input type="number" id="avgCost" min="0" step="100" value="45000">
					</div>
					<div>
						<label>Fully loaded day cost (£/day) for Finance/HR</label>
						<input type="number" id="dayCost" min="0" step="10" value="350">
					</div>
				</div>

				<div class="st-row">
					<div>
						<label>Time spent rebuilding/reconciling per cycle (days per person)</label>
						<input type="number" id="daysPerCycle" min="0" step="0.25" value="2">
					</div>
					<div>
						<label>Decision latency cost (% of annual people cost)</label>
						<input type="number" id="latencyPct" min="0" step="0.01" value="0.15">
					</div>
				</div>

				<div class="st-row">
					<div>
						<label>Attrition rate (% of workforce per year)</label>
						<input type="number" id="attritionPct" min="0" step="0.5" value="15">
					</div>
					<div>
						<label>Avoidable share of attrition (% of leavers)</label>
						<input type="number" id="avoidableAttritionPct" min="0" step="0.1" value="2">
					</div>
				</div>

				<div class="st-row">
					<div>
						<label>Replacement cost (% of salary)</label>
						<input type="number" id="replacementPct" min="0" step="1" value="30">
					</div>
					<div>
						<label>Secondary inefficiency (% of annual people cost)</label>
						<input type="number" id="secondaryPct" min="0" step="0.01" value="0.05">
					</div>
				</div>

				<p class="st-mini">
					Defaults reflect a conservative version of the StaffCast cost model:
					rebuild effort + decision latency + avoidable attrition + secondary inefficiency.
				</p>
			</details>

			<div class="st-actions">
				<button class="btn-primary" id="btnCalc">Calculate</button>
				<button class="btn-secondary" id="btnReset">Reset</button>
				<span class="st-muted" id="status"></span>
			</div>

			<p class="st-muted" style="margin-top:0.75rem;">
				This calculator exists to quantify the cost of <strong>delay and friction</strong> in workforce information.
				Even strong teams pay this tax because the process is reconstruction-based.
			</p>
		</div>

		<div class="st-card">
			<h3>2) Your estimated inefficiency tax</h3>

			<div class="st-kpis">
				<div class="st-kpi">
					<div class="label">Estimated annual cost</div>
					<div class="value" id="kpiAnnual">—</div>
				</div>
				<div class="st-kpi">
					<div class="label">Per employee, per year</div>
					<div class="value" id="kpiPerEmp">—</div>
				</div>
				<div class="st-kpi">
					<div class="label">Per month (average)</div>
					<div class="value" id="kpiMonthly">—</div>
				</div>
				<div class="st-kpi">
					<div class="label">Per working day</div>
					<div class="value" id="kpiDaily">—</div>
				</div>
			</div>

			<table class="st-table" id="breakdownTable" style="display:none;">
				<thead>
					<tr>
						<th>Component</th>
						<th style="width:22%;">Annual estimate</th>
						<th>What it represents</th>
					</tr>
				</thead>
				<tbody id="breakdownBody"></tbody>
			</table>

			<div class="st-cta" id="cta" style="display:none;">
				<h4>What would this have shown you last month?</h4>
				<p>
					StaffCast removes the reconstruction layer and turns payroll + plans into a live, trusted workforce cost signal.
					That’s how the inefficiency tax collapses.
				</p>
				<button class="btn-primary" onclick="if (typeof loadRegistrationForm === 'function') { loadRegistrationForm(); } else { window.location.href = '/'; }">
					Start Accelulator for free
				</button>
				<p class="st-mini" id="licenceHint"></p>
			</div>

			<p class="st-mini" id="methodNote" style="margin-top:0.75rem; display:none;">
				Note: This estimate is conservative and directionally correct. The real cost usually appears as delayed hiring decisions,
				unnecessary freezes, avoidable attrition, late redeployment, and leadership time lost to alignment.
			</p>
		</div>
	</div>

</div>

<script>
(function(){
	const el = (id) => document.getElementById(id);

	const employees = el('employees');
	const cadence = el('cadence');

	const avgCost = el('avgCost');
	const dayCost = el('dayCost');
	const daysPerCycle = el('daysPerCycle');
	const latencyPct = el('latencyPct');
	const attritionPct = el('attritionPct');
	const avoidableAttritionPct = el('avoidableAttritionPct');
	const replacementPct = el('replacementPct');
	const secondaryPct = el('secondaryPct');

	const btnCalc = el('btnCalc');
	const btnReset = el('btnReset');
	const status = el('status');

	const kpiAnnual = el('kpiAnnual');
	const kpiPerEmp = el('kpiPerEmp');
	const kpiMonthly = el('kpiMonthly');
	const kpiDaily = el('kpiDaily');

	const breakdownTable = el('breakdownTable');
	const breakdownBody = el('breakdownBody');
	const cta = el('cta');
	const licenceHint = el('licenceHint');
	const methodNote = el('methodNote');

	function toNum(v, fallback=0){
		const n = parseFloat(String(v ?? '').replace(/[^0-9.\-]/g,''));
		return isNaN(n) ? fallback : n;
	}

	function money(n){
		const sign = n < 0 ? '-' : '';
		const abs = Math.abs(n);
		return sign + '£' + abs.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
	}

	function cyclesPerYear(){
		const v = cadence.value;
		if (v === '4weekly') return 13;
		if (v === 'quarterly') return 4;
		return 12;
	}

	function peopleInvolvedFromSize(n){
		if (n <= 100) return 1;
		if (n <= 500) return 3;
		if (n <= 5000) return 7;
		if (n <= 20000) return 18;
		return 50;
	}

	function licenceHintFromSize(n, annualTax){
		// Intentionally conservative and non-committal (avoid procurement mode)
		// We express as a % of the estimated tax, not a hard price.
		let pct = 8;
		if (n <= 100) pct = 10;
		else if (n <= 500) pct = 8;
		else if (n <= 5000) pct = 6;
		else if (n <= 20000) pct = 5;
		else pct = 4;

		return `Typical licence cost is usually under ~${pct}% of the inefficiency shown above (varies by access needs).`;
	}

	function calc(){
		const nEmp = Math.max(0, Math.floor(toNum(employees.value, 0)));
		if (!nEmp){
			status.textContent = 'Enter employee count to calculate.';
			return;
		}
		status.textContent = '';

		const avg = toNum(avgCost.value, 45000);
		const day = toNum(dayCost.value, 350);
		const dpc = toNum(daysPerCycle.value, 2);
		const lat = toNum(latencyPct.value, 0.15) / 100;
		const attr = toNum(attritionPct.value, 15) / 100;
		const avoid = toNum(avoidableAttritionPct.value, 2) / 100;
		const repl = toNum(replacementPct.value, 30) / 100;
		const sec = toNum(secondaryPct.value, 0.05) / 100;

		const cycles = cyclesPerYear();
		const peopleInvolved = peopleInvolvedFromSize(nEmp);

		// 1) Rebuild effort
		const rebuild = peopleInvolved * dpc * day * cycles;

		// Annual people cost
		const peopleCost = nEmp * avg;

		// 2) Decision latency cost
		const latency = peopleCost * lat;

		// 3) Attrition wastage
		const attrition = nEmp * attr * avoid * avg * repl;

		// 4) Secondary inefficiency
		const secondary = peopleCost * sec;

		const total = rebuild + latency + attrition + secondary;

		// KPIs
		kpiAnnual.textContent = money(total);
		kpiPerEmp.textContent = money(total / nEmp);
		kpiMonthly.textContent = money(total / 12);
		kpiDaily.textContent = money(total / 260);

		// Breakdown
		breakdownBody.innerHTML = '';

		const rows = [
			['Rebuild & reconciliation', rebuild, `Manual mapping, reconciling, report prep across ${peopleInvolved} Finance/HR people per cycle.`],
			['Decision latency', latency, 'Cost of delayed or softened workforce decisions because information isn’t decision-ready in time.'],
			['Avoidable attrition', attrition, 'Defensive pay/approval behaviour and delayed progression causing avoidable leavers and replacement cost.'],
			['Secondary inefficiency', secondary, 'Late redeployment, slow contractor decisions, heavy/light areas discovered too late.']
		];

		rows.forEach(([label, val, desc]) => {
			const tr = document.createElement('tr');
			tr.innerHTML = `
				<td>${label}</td>
				<td><span class="st-pill">${money(val)}</span></td>
				<td class="st-muted">${desc}</td>
			`;
			breakdownBody.appendChild(tr);
		});

		breakdownTable.style.display = '';
		cta.style.display = '';
		methodNote.style.display = '';
		licenceHint.textContent = licenceHintFromSize(nEmp, total);

		
	}

	function reset(){
		employees.value = '';
		cadence.value = 'monthly';

		avgCost.value = 45000;
		dayCost.value = 350;
		daysPerCycle.value = 2;
		latencyPct.value = 0.15;
		attritionPct.value = 15;
		avoidableAttritionPct.value = 2;
		replacementPct.value = 30;
		secondaryPct.value = 0.05;

		status.textContent = '';
		kpiAnnual.textContent = '—';
		kpiPerEmp.textContent = '—';
		kpiMonthly.textContent = '—';
		kpiDaily.textContent = '—';

		breakdownTable.style.display = 'none';
		cta.style.display = 'none';
		methodNote.style.display = 'none';
	}

	btnCalc.addEventListener('click', calc);
	btnReset.addEventListener('click', reset);

	reset();
})();
</script>