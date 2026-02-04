<?php
// /pages/changeStoryBuilder.php
$inject = isset($_GET['inject']) && $_GET['inject'] == '1';
if($inject == 1){
	
}else{
	require_once("../includes/header.php");
	require_once("../includes/functions.php");
}

?>

<style>
	.csb-wrap{max-width:980px;margin:0 auto;padding:1.75rem 1.25rem;}
	.csb-h1{font-size:clamp(1.6rem,2.6vw,2.2rem);margin:0 0 0.5rem 0;}
	.csb-sub{color:var(--muted-ink);max-width:54rem;margin:0 0 1.25rem 0;line-height:1.5;}
	.csb-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start;}
	@media (max-width:900px){.csb-grid{grid-template-columns:1fr;}}
	.csb-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:var(--shadow-2);padding:1rem;}
	.csb-card h3{margin:0 0 0.75rem 0;font-size:1.05rem;}
	.csb-row{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:0.75rem;}
	.csb-row label{font-size:0.9rem;color:#333;display:block;margin-bottom:0.35rem;}
	.csb-row select,.csb-row input[type="file"]{width:100%;padding:0.55rem 0.6rem;border:1px solid #e5e7eb;border-radius:10px;background:#fff;}
	.csb-actions{display:flex;gap:0.75rem;flex-wrap:wrap;margin-top:1rem;align-items:center;}
	.csb-muted{color:var(--muted-ink);font-size:0.9rem;line-height:1.45;}
	.csb-table{width:100%;border-collapse:collapse;margin-top:0.75rem;font-size:0.92rem;}
	.csb-table th,.csb-table td{border-bottom:1px solid #eee;padding:0.55rem 0.35rem;vertical-align:top;}
	.csb-pill{display:inline-flex;gap:0.4rem;align-items:center;padding:0.25rem 0.5rem;border-radius:999px;border:1px solid #e5e7eb;background:#fafafa;font-size:0.82rem;}
	.csb-classify{display:flex;gap:0.4rem;flex-wrap:wrap;}
	.csb-classify button{padding:0.35rem 0.55rem;border-radius:10px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-size:0.85rem;}
	.csb-classify button.active{border-color:#111;}
	.csb-output{width:100%;min-height:200px;padding:0.75rem;border-radius:12px;border:1px solid #e5e7eb;background:#fcfcfc;font-size:0.95rem;line-height:1.45;resize:vertical;}
	.csb-note{width:100%;padding:0.45rem 0.5rem;border-radius:10px;border:1px solid #e5e7eb;font-size:0.9rem;}
	.csb-cta{margin-top:0.75rem;padding:0.9rem;border:1px dashed #d1d5db;border-radius:12px;background:#fafafa;}
	.csb-cta h4{margin:0 0 0.35rem 0;font-size:1rem;}
	.csb-cta p{margin:0 0 0.75rem 0;color:var(--muted-ink);line-height:1.45;}
	.csb-filegrid{
		display:grid;
		grid-template-columns: 1fr 1fr;
		gap: 0.75rem;
		margin-top: 0.75rem;
	}
	@media (max-width: 900px){
		.csb-filegrid{ grid-template-columns: 1fr; }
	}
	.csb-filecol label{
		font-size:0.9rem;
		color:#333;
		display:block;
		margin-bottom:0.35rem;
	}
	.csb-filecol select,
	.csb-filecol input[type="file"]{
		width:100%;
		padding:0.55rem 0.6rem;
		border:1px solid #e5e7eb;
		border-radius:10px;
		background:#fff;
	}
</style>

<div class="csb-wrap">
	<h1 class="csb-h1">ChangeLens</h1>
	<p class="csb-sub">
		Explain people-cost change by assumptions, not totals.
		<br>
		Classify movement into <strong>Timing</strong>, <strong>Rate</strong>, <strong>Volume</strong> and <strong>Structure</strong> and generate a clear narrative for stakeholders.
		<br><span class="csb-muted">Runs entirely in your browser. No uploads. No login.</span>
	</p>

	<div class="csb-grid">
		<div class="csb-card">
			<h3>1) Upload and map columns</h3>
			
			<div class="csb-filegrid">
				<!-- File A -->
				<div class="csb-filecol">
					<label>File A (baseline)</label>
					<input type="file" id="fileA" accept=".csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
			
					<label style="margin-top:0.75rem;">File A: Sheet</label>
					<select id="sheetA" disabled></select>
			
					<label style="margin-top:0.75rem;">File A: Identifier column</label>
					<select id="idColA" disabled></select>
			
					<label style="margin-top:0.75rem;">File A: Value column</label>
					<select id="valColA" disabled></select>
				</div>
			
				<!-- File B -->
				<div class="csb-filecol">
					<label>File B (new)</label>
					<input type="file" id="fileB" accept=".csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
			
					<label style="margin-top:0.75rem;">File B: Sheet</label>
					<select id="sheetB" disabled></select>
			
					<label style="margin-top:0.75rem;">File B: Identifier column</label>
					<select id="idColB" disabled></select>
			
					<label style="margin-top:0.75rem;">File B: Value column</label>
					<select id="valColB" disabled></select>
				</div>
			</div>

			<div class="csb-actions">
				<button class="btn-primary" id="btnBuild" disabled>Build deltas</button>
				<button class="btn-secondary" id="btnReset">Reset</button>
				<span class="csb-muted" id="fileStatus"></span>
			</div>

			<div class="csb-actions" style="margin-top:0.6rem;">
				<button class="btn-secondary" id="btnLoadDemo">Use demo data (instant)</button>
			</div>

			<p class="csb-muted" style="margin-top:0.75rem;">
				Tip: Your “Value” column can be total monthly cost, forecast cost, salary cost, etc. The tool treats it as a number.
			</p>
		</div>

		<div class="csb-card">
			<h3>2) Output narrative</h3>
			<textarea class="csb-output" id="narrative" readonly placeholder="Your story will appear here..."></textarea>

			<div class="csb-actions">
				<button class="btn-secondary" id="btnCopy" disabled>Copy to clipboard</button>
			</div>

			<div class="csb-cta">
				<h4>Want this to be proactive, not retroactive?</h4>
				<p>
					Accelulator captures assumption changes as they happen (so you don’t have to reconstruct the story afterwards),
					plus it gives you a shared people-cost forecast, clean outturn visibility, and controlled challenge.
				</p>
				<button class="btn-primary" onclick="if (typeof loadRegistrationForm === 'function') { loadRegistrationForm(); } else { window.location.href = '/'; }">
					Start Accelulator for free
				</button>
			</div>
		</div>
	</div>

	<div class="csb-card" style="margin-top:1rem;">
		<h3>3) Classify meaningful changes</h3>
		<div id="deltaMeta" class="csb-muted">Upload two files (or use the demo) and click “Build deltas”.</div>
		<div id="deltaTableWrap"></div>
	</div>
</div>

<!-- SheetJS (xlsx) -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
(function(){
	const fileA = document.getElementById('fileA');
	const fileB = document.getElementById('fileB');
	
	const sheetA = document.getElementById('sheetA');
	const sheetB = document.getElementById('sheetB');

	const idColA = document.getElementById('idColA');
	const valColA = document.getElementById('valColA');
	const idColB = document.getElementById('idColB');
	const valColB = document.getElementById('valColB');

	const btnBuild = document.getElementById('btnBuild');
	const btnReset = document.getElementById('btnReset');
	const btnCopy = document.getElementById('btnCopy');
	const btnLoadDemo = document.getElementById('btnLoadDemo');

	const narrative = document.getElementById('narrative');
	const deltaMeta = document.getElementById('deltaMeta');
	const deltaTableWrap = document.getElementById('deltaTableWrap');
	const fileStatus = document.getElementById('fileStatus');

	let dataA = null, dataB = null;
	let wbA = null, wbB = null; // store parsed workbooks for sheet switching
	let headersA = [], headersB = [];
	let deltas = []; // { key, idA, idB, a, b, d, cls:null, note:'' }

	// --- Helpers ---
	function fillSelect(selectEl, headers){
		selectEl.innerHTML = '';
		headers.forEach(h => {
			const opt = document.createElement('option');
			opt.value = h;
			opt.textContent = h;
			selectEl.appendChild(opt);
		});
		selectEl.disabled = headers.length === 0;
	}

	function toNumber(v){
		if (v == null) return 0;
		const cleaned = String(v).replace(/[^0-9.\-]/g,'');
		const n = parseFloat(cleaned);
		return isNaN(n) ? 0 : n;
	}

	function money(n){
		const sign = n < 0 ? '-' : '';
		const abs = Math.abs(n);
		return sign + '£' + abs.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
	}

	function parseCSV(text){
		const lines = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n').filter(l => l.trim().length);
		if (!lines.length) return { headers: [], rows: [] };

		const splitLine = (line) => {
			const out = [];
			let cur = '';
			let inQuotes = false;
			for (let i=0; i<line.length; i++){
				const ch = line[i];
				if (ch === '"' ){
					if (inQuotes && line[i+1] === '"'){ cur += '"'; i++; }
					else inQuotes = !inQuotes;
				} else if (ch === ',' && !inQuotes){
					out.push(cur);
					cur = '';
				} else {
					cur += ch;
				}
			}
			out.push(cur);
			return out.map(s => s.trim());
		};

		const headers = splitLine(lines[0]);
		const rows = [];
		for (let i=1; i<lines.length; i++){
			const cols = splitLine(lines[i]);
			const row = {};
			headers.forEach((h, idx) => row[h] = (cols[idx] ?? '').trim());
			rows.push(row);
		}
		return { headers, rows };
	}

	async function parseXLSXWorkbook(file){
		const buf = await file.arrayBuffer();
		const wb = XLSX.read(buf, { type: 'array' });
		return wb;
	}
	
	function extractSheet(wb, sheetName){
		const ws = wb.Sheets[sheetName];
		const rows = XLSX.utils.sheet_to_json(ws, { defval: '' });
		const headers = rows.length ? Object.keys(rows[0]) : [];
		return { headers, rows };
	}

	async function loadFile(fileInput, which){
		const f = fileInput.files && fileInput.files[0];
		if (!f) return;
	
		const name = (f.name || '').toLowerCase();
	
		try{
			if (name.endsWith('.xlsx')){
				const wb = await parseXLSXWorkbook(f);
				const sheets = wb.SheetNames || [];
				const targetSheetSelect = (which === 'A') ? sheetA : sheetB;
	
				// store workbook
				if (which === 'A') wbA = wb; else wbB = wb;
	
				// populate sheet dropdown
				targetSheetSelect.innerHTML = '';
				sheets.forEach(s => {
					const opt = document.createElement('option');
					opt.value = s;
					opt.textContent = s;
					targetSheetSelect.appendChild(opt);
				});
				targetSheetSelect.disabled = sheets.length <= 1 ? true : false;
	
				// load first sheet by default
				const first = sheets[0];
				const extracted = extractSheet(wb, first);
	
				if (which === 'A'){
					dataA = extracted.rows;
					headersA = extracted.headers;
					fillSelect(idColA, headersA);
					fillSelect(valColA, headersA);
				} else {
					dataB = extracted.rows;
					headersB = extracted.headers;
					fillSelect(idColB, headersB);
					fillSelect(valColB, headersB);
				}
			} else {
				// CSV: no sheets
				const text = await f.text();
				const parsed = parseCSV(text);
	
				const targetSheetSelect = (which === 'A') ? sheetA : sheetB;
				targetSheetSelect.innerHTML = '';
				const opt = document.createElement('option');
				opt.value = 'CSV';
				opt.textContent = 'CSV (single sheet)';
				targetSheetSelect.appendChild(opt);
				targetSheetSelect.disabled = true;
	
				if (which === 'A'){
					wbA = null;
					dataA = parsed.rows;
					headersA = parsed.headers;
					fillSelect(idColA, headersA);
					fillSelect(valColA, headersA);
				} else {
					wbB = null;
					dataB = parsed.rows;
					headersB = parsed.headers;
					fillSelect(idColB, headersB);
					fillSelect(valColB, headersB);
				}
			}
		} catch(e){
			alert('Could not read that file. Please try a CSV or .xlsx file.');
			return;
		}
	
		updateBuildState();
		updateFileStatus();
	}

	function updateFileStatus(){
		const aOk = !!dataA, bOk = !!dataB;
		if (!aOk && !bOk) fileStatus.textContent = '';
		else fileStatus.textContent = `${aOk ? 'A ready' : 'A missing'} · ${bOk ? 'B ready' : 'B missing'}`;
	}

	function canBuild(){
		return dataA && dataB &&
			idColA.value && valColA.value &&
			idColB.value && valColB.value;
	}

	function updateBuildState(){
		btnBuild.disabled = !canBuild();
	}

	function buildDeltas(){
		const keyA = idColA.value, valA = valColA.value;
		const keyB = idColB.value, valB = valColB.value;

		const mapA = new Map(); // canonicalKey -> {idA, a}
		dataA.forEach(r => {
			const rawId = (r[keyA] ?? '').toString().trim();
			if (!rawId) return;
			const canon = rawId.toLowerCase();
			mapA.set(canon, { idA: rawId, a: toNumber(r[valA]) });
		});

		const mapB = new Map(); // canonicalKey -> {idB, b}
		dataB.forEach(r => {
			const rawId = (r[keyB] ?? '').toString().trim();
			if (!rawId) return;
			const canon = rawId.toLowerCase();
			mapB.set(canon, { idB: rawId, b: toNumber(r[valB]) });
		});

		const keys = new Set([...mapA.keys(), ...mapB.keys()]);
		deltas = [];

		keys.forEach(k => {
			const aRec = mapA.get(k) || { idA: '', a: 0 };
			const bRec = mapB.get(k) || { idB: '', b: 0 };
			const d = bRec.b - aRec.a;
			if (Math.abs(d) < 0.01) return;
			deltas.push({
				key: k,
				idA: aRec.idA || bRec.idB,
				idB: bRec.idB || aRec.idA,
				a: aRec.a,
				b: bRec.b,
				d,
				cls: null,
				note: ''
			});
		});

		deltas.sort((x,y) => Math.abs(y.d) - Math.abs(x.d));

		renderDeltas();
		regenerateNarrative();
	}

	function renderDeltas(){
		const shown = deltas.slice(0, 30);
		deltaMeta.textContent = `Showing top ${shown.length} movements (by absolute value). Classify the cause and add a quick note if helpful.`;

		const table = document.createElement('table');
		table.className = 'csb-table';

		const thead = document.createElement('thead');
		thead.innerHTML = `
			<tr>
				<th colspan="2">File A</th>
				<th colspan="2">File B</th>
				<th>Delta</th>
				<th>Cause</th>
				<th style="min-width:200px;">Note</th>
			</tr>
			<tr>
				<th style="width:18%;">Identifier</th>
				<th style="width:12%;">Value</th>
				<th style="width:18%;">Identifier</th>
				<th style="width:12%;">Value</th>
				<th style="width:12%;">&nbsp;</th>
				<th style="width:18%;">&nbsp;</th>
				<th>&nbsp;</th>
			</tr>
		`;
		table.appendChild(thead);

		const tbody = document.createElement('tbody');

		shown.forEach(row => {
			const tr = document.createElement('tr');

			const tdIdA = document.createElement('td');
			tdIdA.textContent = row.idA || '';

			const tdValA = document.createElement('td');
			tdValA.textContent = money(row.a);

			const tdIdB = document.createElement('td');
			tdIdB.textContent = row.idB || '';

			const tdValB = document.createElement('td');
			tdValB.textContent = money(row.b);

			const tdD = document.createElement('td');
			tdD.innerHTML = `<span class="csb-pill">${money(row.d)}</span>`;

			const tdC = document.createElement('td');
			const wrap = document.createElement('div');
			wrap.className = 'csb-classify';

			['Timing','Rate','Volume','Structure'].forEach(label => {
				const b = document.createElement('button');
				b.type = 'button';
				b.textContent = label;
				if (row.cls === label) b.classList.add('active');
				b.addEventListener('click', () => {
					row.cls = label;
					Array.from(wrap.querySelectorAll('button')).forEach(btn => btn.classList.toggle('active', btn.textContent === label));
					regenerateNarrative();
				});
				wrap.appendChild(b);
			});
			tdC.appendChild(wrap);

			const tdN = document.createElement('td');
			const note = document.createElement('input');
			note.type = 'text';
			note.className = 'csb-note';
			note.placeholder = 'Optional note (e.g. “hire brought forward”, “market uplift”)';
			note.value = row.note || '';
			note.addEventListener('input', () => {
				row.note = note.value;
				regenerateNarrative();
			});
			tdN.appendChild(note);

			tr.appendChild(tdIdA);
			tr.appendChild(tdValA);
			tr.appendChild(tdIdB);
			tr.appendChild(tdValB);
			tr.appendChild(tdD);
			tr.appendChild(tdC);
			tr.appendChild(tdN);

			tbody.appendChild(tr);
		});

		table.appendChild(tbody);
		deltaTableWrap.innerHTML = '';
		deltaTableWrap.appendChild(table);

		btnCopy.disabled = false;
	}

	function regenerateNarrative(){
		if (!deltas.length){
			narrative.value = '';
			btnCopy.disabled = true;
			return;
		}

		const totals = { Timing:0, Rate:0, Volume:0, Structure:0, Unclassified:0 };
		const notesByClass = { Timing:[], Rate:[], Volume:[], Structure:[], Unclassified:[] };
		const totalDelta = deltas.reduce((s,r)=>s+r.d,0);

		deltas.forEach(r => {
			const k = r.cls ? r.cls : 'Unclassified';
			totals[k] += r.d;
			if (r.note && r.note.trim().length){
				notesByClass[k].push(`- ${r.idB || r.idA}: ${r.note.trim()} (${money(r.d)})`);
			}
		});

		const lines = [];
		lines.push(`Cost change story (File A → File B)`);
		lines.push(`Total movement: ${money(totalDelta)}.`);
		lines.push('');
		lines.push(`What moved it (classified):`);
		['Timing','Rate','Volume','Structure'].forEach(k => lines.push(`- ${k}: ${money(totals[k])}`));
		if (Math.abs(totals.Unclassified) > 0.01){
			lines.push(`- Unclassified (needs review): ${money(totals.Unclassified)}`);
		}

		// Add short notes section, but keep it compact
		const addNotes = (k) => {
			if (!notesByClass[k].length) return;
			lines.push('');
			lines.push(`${k} notes:`);
			notesByClass[k].slice(0, 6).forEach(n => lines.push(n));
			if (notesByClass[k].length > 6) lines.push(`- (and ${notesByClass[k].length - 6} more)`);
		};

		addNotes('Timing');
		addNotes('Rate');
		addNotes('Volume');
		addNotes('Structure');
		addNotes('Unclassified');

		lines.push('');
		lines.push(`Proactive version: Accelulator captures these assumption changes at source, as they happen, so the story is produced automatically rather than reconstructed.`);

		narrative.value = lines.join('\n');
		btnCopy.disabled = false;
	}

	function resetAll(){
		dataA = dataB = null;
		headersA = headersB = [];
		deltas = [];

		fileA.value = '';
		fileB.value = '';

		[idColA,valColA,idColB,valColB].forEach(sel => { sel.innerHTML=''; sel.disabled=true; });
		[sheetA, sheetB].forEach(sel => { sel.innerHTML = ''; sel.disabled = true; });
		wbA = null; wbB = null;

		btnBuild.disabled = true;
		btnCopy.disabled = true;
		deltaMeta.textContent = 'Upload two files (or use the demo) and click “Build deltas”.';
		deltaTableWrap.innerHTML = '';
		narrative.value = '';
		fileStatus.textContent = '';
	}

	// --- Demo dataset ---
	const demoA = [
		{ Person: 'Ava Jones',   MonthlyCost: 4200 },
		{ Person: 'Ben Carter',  MonthlyCost: 3800 },
		{ Person: 'Cara Smith',  MonthlyCost: 5100 },
		{ Person: 'Dan Patel',   MonthlyCost: 0    },
		{ Person: 'Eli Brown',   MonthlyCost: 4600 }
	];
	const demoB = [
		{ Person: 'Ava Jones',   MonthlyCost: 4200 },
		{ Person: 'Ben Carter',  MonthlyCost: 4100 },
		{ Person: 'Cara Smith',  MonthlyCost: 0    },
		{ Person: 'Dan Patel',   MonthlyCost: 3200 },
		{ Person: 'Eli Brown',   MonthlyCost: 4900 }
	];

	function loadDemo(){
		dataA = demoA.map(x => ({...x}));
		dataB = demoB.map(x => ({...x}));
		headersA = Object.keys(dataA[0]);
		headersB = Object.keys(dataB[0]);
		sheetA.innerHTML = '<option>Demo</option>';
		sheetB.innerHTML = '<option>Demo</option>';
		sheetA.disabled = true;
		sheetB.disabled = true;
		wbA = null; wbB = null;

		fillSelect(idColA, headersA);
		fillSelect(valColA, headersA);
		fillSelect(idColB, headersB);
		fillSelect(valColB, headersB);

		// best guess
		idColA.value = 'Person';
		valColA.value = 'MonthlyCost';
		idColB.value = 'Person';
		valColB.value = 'MonthlyCost';

		updateBuildState();
		updateFileStatus();
	}

	// --- Events ---
	fileA.addEventListener('change', () => loadFile(fileA, 'A'));
	fileB.addEventListener('change', () => loadFile(fileB, 'B'));

	[idColA,valColA,idColB,valColB].forEach(sel => sel.addEventListener('change', updateBuildState));

	btnBuild.addEventListener('click', buildDeltas);
	btnReset.addEventListener('click', resetAll);

	btnCopy.addEventListener('click', async () => {
		try{
			await navigator.clipboard.writeText(narrative.value || '');
			btnCopy.textContent = 'Copied';
			setTimeout(()=>btnCopy.textContent='Copy to clipboard', 900);
		} catch(e){
			alert('Copy failed. You can manually select and copy the text.');
		}
	});

	btnLoadDemo.addEventListener('click', () => {
		loadDemo();
	});
	
	sheetA.addEventListener('change', () => {
		if (!wbA) return;
		const extracted = extractSheet(wbA, sheetA.value);
		dataA = extracted.rows;
		headersA = extracted.headers;
		fillSelect(idColA, headersA);
		fillSelect(valColA, headersA);
		updateBuildState();
		updateFileStatus();
	});
	
	sheetB.addEventListener('change', () => {
		if (!wbB) return;
		const extracted = extractSheet(wbB, sheetB.value);
		dataB = extracted.rows;
		headersB = extracted.headers;
		fillSelect(idColB, headersB);
		fillSelect(valColB, headersB);
		updateBuildState();
		updateFileStatus();
	});

	resetAll();
})();
</script>