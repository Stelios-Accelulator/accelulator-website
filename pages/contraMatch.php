<?php
// /pages/contraMatch.php
$inject = isset($_GET['inject']) && $_GET['inject'] == '1';
if($inject == 1){
}else{
	require_once("../includes/header.php");
	require_once("../includes/functions.php");
}
?>

<style>
	.cm-wrap{max-width:980px;margin:0 auto;padding:1.75rem 1.25rem;}
	.cm-h1{font-size:clamp(1.6rem,2.6vw,2.2rem);margin:0 0 0.5rem 0;}
	.cm-sub{color:var(--muted-ink);max-width:56rem;margin:0 0 1.25rem 0;line-height:1.5;}
	.cm-grid{display:grid;grid-template-columns:1fr;gap:1rem;align-items:start;}
	.cm-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:var(--shadow-2);padding:1rem;}
	.cm-row{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:0.75rem;}
	@media (max-width:760px){.cm-row{grid-template-columns:1fr;}}
	label{font-size:0.9rem;color:#333;display:block;margin-bottom:0.35rem;}
	input,select{
		width:100%;
		padding:0.55rem 0.6rem;
		border:1px solid #e5e7eb;
		border-radius:10px;
		background:#fff;
	}
	.cm-actions{display:flex;gap:0.75rem;flex-wrap:wrap;margin-top:1rem;align-items:center;}
	.cm-muted{color:var(--muted-ink);font-size:0.9rem;line-height:1.45;}
	.cm-kpis{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;margin-top:0.75rem;}
	@media (max-width:760px){.cm-kpis{grid-template-columns:1fr;}}
	.cm-kpi{border:1px solid #eee;border-radius:12px;padding:0.75rem;background:#fcfcfc;}
	.cm-kpi .label{font-size:0.85rem;color:var(--muted-ink);margin-bottom:0.2rem;}
	.cm-kpi .value{font-size:1.15rem;font-weight:700;letter-spacing:-0.02em;}
	.cm-adv{margin-top:0.75rem;border-top:1px solid #eee;padding-top:0.75rem;}
	.cm-adv summary{cursor:pointer;font-weight:600;}
	.cm-pill{display:inline-flex;gap:0.4rem;align-items:center;padding:0.25rem 0.5rem;border-radius:999px;border:1px solid #e5e7eb;background:#fafafa;font-size:0.82rem;}
</style>

<div class="cm-wrap">
	<h1 class="cm-h1">Contra Matcher</h1>
	<p class="cm-sub">
		Upload an <strong>.xlsx</strong> and we’ll flag contra items:
		<br><span class="cm-pill">Level 1</span> exact 1:1 opposites (± to chosen dp)
		<span class="cm-pill">Level 2</span> one-to-many / many-to-one combos (bounded search)
		<br><span class="cm-muted">Runs locally in your browser. Your file is not uploaded.</span>
	</p>

	<div class="cm-grid">
		<div class="cm-card">
			<h3 style="margin:0 0 0.75rem 0;font-size:1.05rem;">1) Load your spreadsheet</h3>

			<div class="cm-row">
				<div>
					<label>Choose .xlsx</label>
					<input type="file" id="file" accept=".xlsx">
				</div>
				<div>
					<label>Sheet</label>
					<select id="sheet" disabled></select>
				</div>
			</div>

			<div class="cm-row">
				<div>
					<label>Amount format</label>
					<select id="amountMode" disabled>
						<option value="net" selected>Single Net column (positive/negative)</option>
						<option value="dc">Separate Debit / Credit columns</option>
					</select>
				</div>
				<div>
					<label>Rounding</label>
					<select id="dp" disabled>
						<option value="2" selected>2 decimal places (recommended)</option>
						<option value="0">0 dp</option>
						<option value="1">1 dp</option>
						<option value="3">3 dp</option>
					</select>
				</div>
			</div>

			<div class="cm-row" id="netRow">
				<div>
					<label>Net amount column</label>
					<select id="amountCol" disabled></select>
					<div class="cm-muted" style="margin-top:0.35rem;">
						One column containing positive and negative values.
					</div>
				</div>
				<div></div>
			</div>

			<div class="cm-row" id="dcRow" style="display:none;">
				<div>
					<label>Debit column</label>
					<select id="debitCol" disabled></select>
				</div>
				<div>
					<label>Credit column</label>
					<select id="creditCol" disabled></select>
				</div>
			</div>

			<details class="cm-adv">
				<summary>Advanced options (recommended for Level 2)</summary>

				<div class="cm-row" style="margin-top:0.75rem;">
					<div>
						<label>Enable Level 2 (combos)</label>
						<select id="enableL2" disabled>
							<option value="0" selected>No (Level 1 only)</option>
							<option value="1">Yes (find combos)</option>
						</select>
					</div>
					<div>
						<label>Max combo size (Level 2)</label>
						<select id="maxCombo" disabled>
							<option value="3">3</option>
							<option value="4" selected>4</option>
							<option value="5">5</option>
							<option value="6">6 (slower)</option>
						</select>
					</div>
				</div>

				<div class="cm-row">
					<div>
						<label>Candidate cap per side (Level 2)</label>
						<input type="number" id="candCap" value="60" min="10" step="10" disabled>
						<div class="cm-muted" style="margin-top:0.35rem;">Keeps the combo search fast on messy ledgers.</div>
					</div>
					<div>
						<label>Grouping columns (multi-select)</label>
						<select id="groupCols" multiple size="5" disabled></select>
						<div class="cm-muted" style="margin-top:0.35rem;">
							Hold ⌘/Ctrl to select multiple. Matches are searched within each group.
							<br>Good defaults: Journal ID + Account (or Reference).
						</div>
					</div>
				</div>

				<div class="cm-muted" style="margin-top:0.75rem;">
					Level 2 is a bounded search (practical, not theoretical). It’s designed for typical split postings
					(e.g. one invoice netting to multiple allocations).
				</div>
			</details>

			<div class="cm-actions">
				<button class="btn-primary" id="run" disabled>Run & Download</button>
				<button class="btn-secondary" id="reset" disabled>Reset</button>
				<span class="cm-muted" id="status"></span>
			</div>
		</div>

		<div class="cm-card">
			<h3 style="margin:0 0 0.75rem 0;font-size:1.05rem;">2) Results</h3>

			<div class="cm-kpis">
				<div class="cm-kpi">
					<div class="label">Rows processed</div>
					<div class="value" id="kRows">—</div>
				</div>
				<div class="cm-kpi">
					<div class="label">Level 1 matches (pairs)</div>
					<div class="value" id="kL1">—</div>
				</div>
				<div class="cm-kpi">
					<div class="label">Level 2 groups</div>
					<div class="value" id="kL2">—</div>
				</div>
			</div>

			<p class="cm-muted" style="margin-top:0.75rem;">
				Output columns appended:
				<br><span class="cm-pill">Contra_L1</span> Yes/No
				<span class="cm-pill">Contra_L1_ID</span> stable match id
				<span class="cm-pill">Contra_L2</span> Yes/No
				<span class="cm-pill">Contra_L2_ID</span> stable match id
				<span class="cm-pill">Contra_Matched_Rows</span> matched Excel row numbers
				<span class="cm-pill">Contra_Notes</span> brief explanation
			</p>
		</div>
		
		<div id="cmThanks" class="cm-card" style="display:none;margin-top:1rem;">
			<h3 style="margin:0 0 0.5rem 0;font-size:1.05rem;">
				Did this save you time?
			</h3>
		
			<p class="cm-muted" style="margin:0 0 0.75rem 0;">
				This tool is free and runs entirely in your browser.
				If it helped, you can support development — or start using these ideas inside Accelulator.
			</p>
		
			<div class="cm-actions">
				<a href="https://buymeacoffee.com/accelulator"
					 target="_blank"
					 rel="noopener"
					 class="btn-secondary">
					☕ Buy me a coffee
				</a>
		
				<button class="btn-primary"
					onclick="if (typeof loadRegistrationForm === 'function') { loadRegistrationForm(); } else { window.location.href = '/'; }">
					Create a free Accelulator account
				</button>
			</div>
		
			<p class="cm-muted" style="margin-top:0.5rem;font-size:0.85rem;">
				Accelulator turns ad-hoc reconciliations like this into a live, structured workflow
				for payroll, people cost and forecasting.
			</p>
		</div>
	</div>
</div>

<!-- SheetJS + FileSaver (client-side) -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/file-saver@2.0.5/dist/FileSaver.min.js"></script>

<script>
(function(){
	const el = id => document.getElementById(id);

	const fileEl = el('file');
	const sheetEl = el('sheet');

	const amountModeEl = el('amountMode');
	const dpEl = el('dp');

	const netRow = el('netRow');
	const dcRow = el('dcRow');

	const amountColEl = el('amountCol');
	const debitColEl = el('debitCol');
	const creditColEl = el('creditCol');

	const enableL2El = el('enableL2');
	const maxComboEl = el('maxCombo');
	const candCapEl = el('candCap');
	const groupColsEl = el('groupCols');

	const runEl = el('run');
	const resetEl = el('reset');
	const statusEl = el('status');

	const kRows = el('kRows');
	const kL1 = el('kL1');
	const kL2 = el('kL2');

	let wb = null;
	let originalName = null;

	function setStatus(msg){ statusEl.textContent = msg || ''; }

	function roundTo(n, dp){
		const f = Math.pow(10, dp);
		return Math.round((n + Number.EPSILON) * f) / f;
	}

	function moneyKey(n, dp){
		const r = roundTo(n, dp);
		return r.toFixed(dp);
	}

	function isFiniteNumber(v){
		return typeof v === 'number' && isFinite(v);
	}

	function normaliseAmount(v){
		if (v === null || v === undefined) return NaN;
		if (typeof v === 'number') return v;
		const s = String(v).trim();
		if (!s) return NaN;
		const n = parseFloat(s.replace(/[^0-9.\-]/g,''));
		return isNaN(n) ? NaN : n;
	}

	function hashId(str){
		let h = 5381;
		for (let i=0; i<str.length; i++){
			h = ((h << 5) + h) + str.charCodeAt(i);
		}
		return (h >>> 0).toString(36);
	}

	function excelRowNumber(itemIdx){
		// sheet_to_json returns data rows only, so add 2 (header row + 1-indexed rows)
		return itemIdx + 2;
	}

	function enableControls(on){
		sheetEl.disabled = !on;

		amountModeEl.disabled = !on;
		dpEl.disabled = !on;

		amountColEl.disabled = !on;
		debitColEl.disabled = !on;
		creditColEl.disabled = !on;

		enableL2El.disabled = !on;
		maxComboEl.disabled = !on;
		candCapEl.disabled = !on;
		groupColsEl.disabled = !on;

		runEl.disabled = !on;
		resetEl.disabled = !on;
	}

	function resetAll(){
		wb = null;
		originalName = null;

		sheetEl.innerHTML = '';

		amountModeEl.value = 'net';
		dpEl.value = '2';

		amountColEl.innerHTML = '';
		debitColEl.innerHTML = '';
		creditColEl.innerHTML = '';

		groupColsEl.innerHTML = '';

		enableL2El.value = '0';
		maxComboEl.value = '4';
		candCapEl.value = '60';

		netRow.style.display = '';
		dcRow.style.display = 'none';

		kRows.textContent = '—';
		kL1.textContent = '—';
		kL2.textContent = '—';
		setStatus('');

		enableControls(false);
		fileEl.value = '';
	}

	resetAll();

	amountModeEl.addEventListener('change', () => {
		const isDC = amountModeEl.value === 'dc';
		netRow.style.display = isDC ? 'none' : '';
		dcRow.style.display = isDC ? '' : 'none';
	});

	fileEl.addEventListener('change', async (e) => {
		const f = e.target.files && e.target.files[0];
		if (!f) return;

		originalName = f.name.replace(/\.xlsx$/i,'');
		setStatus('Reading workbook…');

		const data = await f.arrayBuffer();
		wb = XLSX.read(data, {type:'array'});

		sheetEl.innerHTML = '';
		wb.SheetNames.forEach((name, idx) => {
			const opt = document.createElement('option');
			opt.value = name;
			opt.textContent = name;
			if (idx === 0) opt.selected = true;
			sheetEl.appendChild(opt);
		});

		populateColumns();
		enableControls(true);
		setStatus('');
	});

	sheetEl.addEventListener('change', populateColumns);

	function populateColumns(){
		if (!wb) return;
		const shName = sheetEl.value;
		const ws = wb.Sheets[shName];

		const aoa = XLSX.utils.sheet_to_json(ws, {header:1, raw:true, defval:''});
		const headers = (aoa[0] || []).map(h => String(h).trim()).filter(Boolean);

		amountColEl.innerHTML = '';
		debitColEl.innerHTML = '';
		creditColEl.innerHTML = '';
		groupColsEl.innerHTML = '';

		headers.forEach((h) => {
			const optNet = document.createElement('option');
			optNet.value = h;
			optNet.textContent = h;
			amountColEl.appendChild(optNet);

			const optD = document.createElement('option');
			optD.value = h;
			optD.textContent = h;
			debitColEl.appendChild(optD);

			const optC = document.createElement('option');
			optC.value = h;
			optC.textContent = h;
			creditColEl.appendChild(optC);

			const optG = document.createElement('option');
			optG.value = h;
			optG.textContent = h;
			groupColsEl.appendChild(optG);
		});

		// Guess defaults
		const guessDebit = headers.find(h => /^debit$/i.test(h)) || headers.find(h => /debit/i.test(h)) || '';
		const guessCredit = headers.find(h => /^credit$/i.test(h)) || headers.find(h => /credit/i.test(h)) || '';
		const guessNet = headers.find(h => /net|amount|value|total|gross/i.test(h)) || headers[headers.length - 1] || '';

		if (guessDebit && guessCredit){
			amountModeEl.value = 'dc';
			debitColEl.value = guessDebit;
			creditColEl.value = guessCredit;
			netRow.style.display = 'none';
			dcRow.style.display = '';
		} else {
			amountModeEl.value = 'net';
			amountColEl.value = guessNet;
			netRow.style.display = '';
			dcRow.style.display = 'none';
		}

		// Default grouping: Account (and optionally Description) is best for contra/reversal matching
		const defaults = ['Account'];
		[...groupColsEl.options].forEach(opt => {
			if (defaults.some(d => d.toLowerCase() === opt.value.toLowerCase())) opt.selected = true;
		});

		setStatus('');
	}

	resetEl.addEventListener('click', resetAll);

	function getSignedAmount(row, dp){
		if (amountModeEl.value === 'dc'){
			const d = normaliseAmount(row[debitColEl.value]);
			const c = normaliseAmount(row[creditColEl.value]);
			const debit = isNaN(d) ? 0 : d;
			const credit = isNaN(c) ? 0 : c;
			return roundTo(debit - credit, dp); // debit positive, credit negative
		}
		const n = normaliseAmount(row[amountColEl.value]);
		return isNaN(n) ? NaN : roundTo(n, dp);
	}

	function buildGroupKey(row, groupCols){
		if (!groupCols.length) return '__ALL__';
		return groupCols.map(c => String(row[c] ?? '')).join(' | ');
	}

	runEl.addEventListener('click', () => {
		if (!wb) return;

		const shName = sheetEl.value;
		const ws = wb.Sheets[shName];

		const dp = parseInt(dpEl.value, 10);
		const enableL2 = enableL2El.value === '1';
		const maxCombo = parseInt(maxComboEl.value, 10);
		const candCap = Math.max(10, parseInt(candCapEl.value, 10) || 60);

		const groupCols = [...groupColsEl.selectedOptions].map(o => o.value).filter(Boolean);

		setStatus('Parsing sheet…');

		const rows = XLSX.utils.sheet_to_json(ws, {defval:'', raw:true});
		const headers = Object.keys(rows[0] || {});
		if (!headers.length){
			setStatus('No rows found.');
			return;
		}

		const OUT = {
			L1: 'Contra_L1',
			L1ID: 'Contra_L1_ID',
			L2: 'Contra_L2',
			L2ID: 'Contra_L2_ID',
			MatchedRows: 'Contra_Matched_Rows',
			Notes: 'Contra_Notes',
		};

		const items = rows.map((r, idx) => {
			const amt = getSignedAmount(r, dp);
			return {
				idx,
				row: r,
				amt,
				key: buildGroupKey(r, groupCols),
				usedL1: false,
				usedL2: false
			};
		});

		// Initialise outputs
		items.forEach(it => {
			it.row[OUT.L1] = '';
			it.row[OUT.L1ID] = '';
			it.row[OUT.L2] = '';
			it.row[OUT.L2ID] = '';
			it.row[OUT.MatchedRows] = '';
			it.row[OUT.Notes] = '';
		});

		// Group items
		const groups = new Map();
		for (const it of items){
			if (!groups.has(it.key)) groups.set(it.key, []);
			groups.get(it.key).push(it);
		}

		let l1Pairs = 0;
		let l2Groups = 0;

		// Process per group
		for (const [gk, gItems] of groups.entries()){
			// Level 1: exact opposites
			const posMap = new Map();
			const negMap = new Map();

			for (const it of gItems){
				if (!isFiniteNumber(it.amt) || it.amt === 0) continue;
				const rk = moneyKey(Math.abs(it.amt), dp);
				if (it.amt > 0){
					if (!posMap.has(rk)) posMap.set(rk, []);
					posMap.get(rk).push(it);
				} else {
					if (!negMap.has(rk)) negMap.set(rk, []);
					negMap.get(rk).push(it);
				}
			}

			for (const [rk, posQueue] of posMap.entries()){
				const negQueue = negMap.get(rk) || [];
				while (posQueue.length && negQueue.length){
					const a = posQueue.shift();
					const b = negQueue.shift();
					if (a.usedL1 || b.usedL1) continue;

					a.usedL1 = true;
					b.usedL1 = true;

					const idSeed = `L1|${gk}|${rk}|${Math.min(a.idx,b.idx)}|${Math.max(a.idx,b.idx)}`;
					const id = `L1-${hashId(idSeed)}`;
					const matchedRows = [excelRowNumber(a.idx), excelRowNumber(b.idx)].sort((x,y)=>x-y).join(',');

					a.row[OUT.L1] = 'Yes';
					b.row[OUT.L1] = 'Yes';
					a.row[OUT.L1ID] = id;
					b.row[OUT.L1ID] = id;
					a.row[OUT.MatchedRows] = matchedRows;
					b.row[OUT.MatchedRows] = matchedRows;

					const note = `Exact contra match to ${dp}dp (${rk}).`;
					a.row[OUT.Notes] = note;
					b.row[OUT.Notes] = note;

					l1Pairs += 1;
				}
			}

			if (!enableL2) continue;

			// Level 2: combos for unmatched items
			const pos = [];
			const neg = [];
			for (const it of gItems){
				if (!isFiniteNumber(it.amt) || it.amt === 0) continue;
				if (it.usedL1 || it.usedL2) continue;
				const amt = roundTo(it.amt, dp);
				if (amt > 0) pos.push(it);
				else neg.push(it);
			}

			function capPool(arr){
				arr.sort((a,b) => Math.abs(b.amt) - Math.abs(a.amt));
				return arr.slice(0, candCap);
			}
			const posPool = capPool(pos);
			const negPool = capPool(neg);

			function findCombo(pool, targetAbs){
				const target = roundTo(targetAbs, dp);
				const N = pool.length;
				if (N === 0) return null;

				const maxLeft = Math.floor(maxCombo / 2);
				const maxRight = maxCombo - maxLeft;

				function genSums(maxSize){
					const sums = new Map();

					for (let i=0;i<N;i++){
						const s = roundTo(Math.abs(pool[i].amt), dp);
						const k = moneyKey(s, dp);
						if (!sums.has(k)) sums.set(k, [i]);
					}
					if (maxSize === 1) return sums;

					for (let size=2; size<=maxSize; size++){
						if (size === 2){
							for (let i=0;i<N;i++){
								for (let j=i+1;j<N;j++){
									const s = roundTo(Math.abs(pool[i].amt) + Math.abs(pool[j].amt), dp);
									const k = moneyKey(s, dp);
									if (!sums.has(k)) sums.set(k, [i,j]);
								}
							}
						} else if (size === 3){
							for (let i=0;i<N;i++){
								for (let j=i+1;j<N;j++){
									for (let k2=j+1;k2<N;k2++){
										const s = roundTo(Math.abs(pool[i].amt) + Math.abs(pool[j].amt) + Math.abs(pool[k2].amt), dp);
										const k = moneyKey(s, dp);
										if (!sums.has(k)) sums.set(k, [i,j,k2]);
									}
								}
							}
						} else if (size === 4){
							for (let i=0;i<N;i++){
								for (let j=i+1;j<N;j++){
									for (let k2=j+1;k2<N;k2++){
										for (let l=k2+1;l<N;l++){
											const s = roundTo(Math.abs(pool[i].amt) + Math.abs(pool[j].amt) + Math.abs(pool[k2].amt) + Math.abs(pool[l].amt), dp);
											const kk = moneyKey(s, dp);
											if (!sums.has(kk)) sums.set(kk, [i,j,k2,l]);
										}
									}
								}
							}
						}
					}
					return sums;
				}

				const leftSums = genSums(Math.max(1, maxLeft));
				const directKey = moneyKey(target, dp);
				if (leftSums.has(directKey)){
					return leftSums.get(directKey).map(i => pool[i]);
				}

				const rightSums = genSums(Math.max(1, maxRight));

				for (const [lk, lIdx] of leftSums.entries()){
					const lSum = parseFloat(lk);
					const need = roundTo(target - lSum, dp);
					if (need <= 0) continue;

					const nk = moneyKey(need, dp);
					if (rightSums.has(nk)){
						const rIdx = rightSums.get(nk);

						const used = new Set(lIdx);
						let overlaps = false;
						for (const ri of rIdx){
							if (used.has(ri)){ overlaps = true; break; }
						}
						if (overlaps) continue;

						const comboIdx = [...lIdx, ...rIdx];
						if (comboIdx.length <= maxCombo){
							return comboIdx.map(i => pool[i]);
						}
					}
				}

				return null;
			}

			const unmatched = [...posPool, ...negPool].filter(it => !it.usedL1 && !it.usedL2);
			unmatched.sort((a,b) => Math.abs(b.amt) - Math.abs(a.amt));

			for (const it of unmatched){
				if (it.usedL1 || it.usedL2) continue;

				const amtAbs = Math.abs(roundTo(it.amt, dp));
				if (amtAbs === 0) continue;

				const pool = it.amt > 0 ? negPool : posPool;
				const poolAvail = pool.filter(x => !x.usedL1 && !x.usedL2);

				const combo = findCombo(poolAvail, amtAbs);
				if (!combo) continue;

				const idxs = [it.idx, ...combo.map(c => c.idx)].sort((x,y)=>x-y);
				const idSeed = `L2|${gk}|${moneyKey(amtAbs, dp)}|${idxs.join(',')}`;
				const id = `L2-${hashId(idSeed)}`;
				const matchedRows = idxs.map(excelRowNumber).join(',');

				it.usedL2 = true;
				it.row[OUT.L2] = 'Yes';
				it.row[OUT.L2ID] = id;
				it.row[OUT.MatchedRows] = matchedRows;

				for (const c of combo){
					c.usedL2 = true;
					c.row[OUT.L2] = 'Yes';
					c.row[OUT.L2ID] = id;
					c.row[OUT.MatchedRows] = matchedRows;
				}

				const note = `Contra combo found (${combo.length} txn) to offset ${moneyKey(amtAbs, dp)}.`;
				it.row[OUT.Notes] = note;
				combo.forEach(c => c.row[OUT.Notes] = note);

				l2Groups += 1;
			}
		}

		kRows.textContent = String(items.length);
		kL1.textContent = String(l1Pairs);
		kL2.textContent = String(l2Groups);

		setStatus('Writing output workbook…');

		const outWs = XLSX.utils.json_to_sheet(rows, {skipHeader:false});
		const outWb = XLSX.utils.book_new();
		XLSX.utils.book_append_sheet(outWb, outWs, shName);

		const outName = `${originalName || 'contra'}_contraMatched.xlsx`;
		const wbout = XLSX.write(outWb, {bookType:'xlsx', type:'array'});

		saveAs(
			new Blob([wbout], {type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),
			outName
		);
		
		setStatus('Done. Download should have started.');
		
		const thanks = document.getElementById('cmThanks');
		if (thanks) {
			thanks.style.display = '';
			thanks.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	});
})();
</script>