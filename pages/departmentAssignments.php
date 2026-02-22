<?php
require_once("../includes/header.php");
require_once("../includes/functions.php");

$level = $_SESSION['userAccess'];

if (!in_array($level, [2,5,9,10])) {
		header("Location: /modules/currentPosition.php");
		exit;
}

$user       = checkUser(true);
$companyID  = (int) getUsersCompanyId($user);
$csrf       = generateCsrfToken();
?>
<style>
	.da-container {
			display: grid;
			grid-template-columns: 320px 1fr;
			gap: 1.5rem;
	}
	
	.da-users {
			border-right: 1px solid #eee;
			padding-right: 1rem;
	}
	
	.da-users input[type="text"],
	.da-users select{
		width: 100%;
		margin-bottom: .5rem;
	}
	
	.da-userRow {
			padding: .5rem;
			cursor: pointer;
			border-radius: 6px;
	}
	
	.da-userRow:hover {
			background: #f5f5f5;
	}
	
	.da-userRow.active {
			background: #eef2ff;
	}
	
	/* Department pills */
	/* Department pills – soft brand tint */
	#departmentAssignments .tag{
		display:inline-flex;
		align-items:center;
		gap:.45rem;
	
		padding: .28rem .55rem;
		margin: .22rem .28rem .22rem 0;
	
		border-radius: 999px;
	
		/* Soft teal tint */
		background: rgba(7,164,188,0.10);
		border: 1px solid rgba(7,164,188,0.28);
	
		color: #065f6b; /* deeper teal text */
		font-size: 13px;
		line-height: 1.2;
	
		transition:
			background-color .15s ease,
			border-color .15s ease,
			box-shadow .15s ease;
	}
	
	#departmentAssignments .tag:hover{
		background: rgba(7,164,188,0.16);
		border-color: rgba(7,164,188,0.45);
		box-shadow: 0 1px 3px rgba(0,0,0,0.08);
	}
	
	/* Hide the remove button until hover */
	#departmentAssignments .tag button{
		width: 18px;
		height: 18px;
		border-radius: 999px;
	
		display:inline-flex;
		align-items:center;
		justify-content:center;
	
		border: 0;
		background: transparent;
		color: #6b7280;        /* subtle */
		padding: 0;
		line-height: 1;
	
		opacity: 0;
		transform: scale(0.95);
		transition: opacity .12s ease, transform .12s ease, background-color .12s ease, color .12s ease;
	
		cursor:pointer;
	}
	
	#departmentAssignments .tag:hover button{
		opacity: 1;
		transform: scale(1);
	}
	
	#departmentAssignments .tag button:hover{
		background: rgba(7,164,188,0.12);  /* ties into your brand accent */
		color: var(--brand-ink);
	}
	
	#departmentAssignments .tag button:focus-visible{
		outline: 2px solid var(--brand-ink);
		outline-offset: 2px;
	}
	
	/* Make this settings panel behave like a full-width module page */
	#departmentAssignments.settingsPanel{
		max-width: none;
		width: 100%;
		margin: 0;
	}
	
	/* actions row for Save/Clear (don't touch global .menuRow) */
	#departmentAssignments .da-actions{
		display:flex;
		gap:.5rem;
		margin-top:.8rem;
		align-items:center;
	}
	
	.da-editor{
		padding-left: .5rem;
	}
	
	#departmentAssignments .da-actions button{ padding:.5rem .8rem; }
	
	/* Inputs: same tidy/focus styling as uploadFileForm.php */
	#departmentAssignments .da-users input[type="text"],
	#departmentAssignments .da-users select,
	#departmentAssignments #editorControls select{
		border: 1px solid #cbd5e1;
		border-radius: 8px;
		padding: 0.4rem 0.5rem;
		font: inherit;
		background: #fff;
		box-shadow: 0 0 0 rgba(0,0,0,0);
		transition: border-color .15s ease, box-shadow .15s ease;
	}
	
	#departmentAssignments .da-users input[type="text"]:focus,
	#departmentAssignments .da-users select:focus,
	#departmentAssignments #editorControls select:focus{
		outline: none;
		border-color: var(--brand-ink);
		box-shadow: 0 0 0 3px rgba(7,164,188,0.18);
	}
	
	/* Buttons: copy the hover/active feel from uploadFileForm.php */
	#departmentAssignments button{
		border-radius: 999px;
		border: 0;
		padding: 0.45rem 0.9rem;
		font: inherit;
		font-weight: 600;
		cursor: pointer;
		background: var(--brand);
		color: #fff;
		box-shadow: 0 1px 3px rgba(0,0,0,0.16);
		transition:
			background-color .15s ease,
			box-shadow .15s ease,
			transform .06s ease,
			filter .15s ease;
	}
	
	#departmentAssignments button:hover{
		filter: brightness(1.15);
		box-shadow: 0 3px 8px rgba(0,0,0,0.18);
	}
	
	#departmentAssignments button:active{
		transform: translateY(1px);
		box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
	}
	
	#departmentAssignments button:focus-visible{
		outline: 3px solid var(--brand-ink);
		outline-offset: 2px;
	}
	
	/* Secondary button (Clear) */
	#departmentAssignments button.secondary{
		background: #fff;
		color: var(--brand);
		border: 1px solid #cbd5e1;
		box-shadow: 0 1px 2px rgba(0,0,0,0.06);
	}
	
	.da-rowTop{
		display:flex;
		align-items:center;
		justify-content:space-between;
		gap:.5rem;
	}
	
	.da-badge{
		font-size: 12px;
		padding: 2px 8px;
		border-radius: 999px;
		line-height: 1.4;
		white-space: nowrap;
	}
	
	.da-badge-warn{
		background: rgba(245, 158, 11, 0.14); /* subtle amber */
		border: 1px solid rgba(245, 158, 11, 0.35);
		color: #92400e;
	}
	
	/* Give the add-department select some breathing room before the first pill */
	#departmentAssignments #deptAddSelect{
		margin-right: .6rem;
	}
	
</style>
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

<div id="departmentAssignments" class="settingsPanel">

		<h2>Department Assignments</h2>
		<p class="muted">
			Assign departments to <strong>Analysts</strong> (multiple allowed), <strong>Department Heads</strong> (exactly one), and
			<strong>Functional Heads</strong> (multiple allowed).
		</p>

		<div class="da-container">

				<!-- LEFT PANEL -->
				<div class="da-users">
						<input type="text" id="userSearch" placeholder="Search name or email…">
						<select id="roleFilter">
								<option value="all">All</option>
								<option value="5">Analysts</option>
								<option value="7">Department Heads</option>
								<option value="8">Functional Heads</option>
						</select>

						<label>
								<input type="checkbox" id="showInactive">
								Show inactive
						</label>

						<div id="userList"></div>
				</div>

				<!-- RIGHT PANEL -->
				<div class="da-editor">
						<div id="editorEmpty">
							Select an Analyst, Department Head, or Functional Head to manage access.
						</div>

						<div id="editorContent" style="display:none;">
								<h3 id="editorName"></h3>
								<p id="editorEmail" class="muted"></p>
								<p id="editorRole"></p>

								<div id="editorControls"></div>

								<div class="da-actions">
									<button type="button" id="saveAssignmentsBtn">Save changes</button>
									<button type="button" id="clearAssignmentsBtn" class="secondary">Clear</button>
								</div>
						</div>
				</div>

		</div>
</div>

<script>

if (window.__deptAssignLoaded) {
	// If this page is injected twice, don’t redeclare globals.
	console.warn("Department Assignments already loaded");
} else {
	window.__deptAssignLoaded = true;

	const csrfMeta = document.querySelector('meta[name="csrf-token"]');
	const daCsrfToken = csrfMeta ? csrfMeta.content : '';

	let daSelectedUser = null;
	let daDepartments = [];
	let daUserDepartments = [];
	let daSearchTimer = null;

	async function fetchAssignableUsers(){
			const res = await fetch("../api/getAssignableUsers.php", {
					method:"POST",
					headers:{ "Content-Type":"application/json","X-CSRF-Token":daCsrfToken },
					body: JSON.stringify({
						search: document.getElementById("userSearch")?.value || "",
						roleFilter: document.getElementById("roleFilter")?.value || "all",
						showInactive: document.getElementById("showInactive")?.checked || false
					})			});
			return (await res.json()).users || [];
	}
	
	async function fetchDepartments(){
			const res = await fetch("../api/getCompanyDepartments.php", {
					method:"POST",
					headers:{ "Content-Type":"application/json","X-CSRF-Token":daCsrfToken },
					body:JSON.stringify({})
			});
			daDepartments = (await res.json()).departments || [];
	}
	
	async function fetchUserDepartments(userRef){
			const res = await fetch("../api/getUserDepartments.php", {
					method:"POST",
					headers:{ "Content-Type":"application/json","X-CSRF-Token":daCsrfToken },
					body:JSON.stringify({ userRef })
			});
			daUserDepartments = (await res.json()).departments || [];
	}
	
	let daFirstLoad = true;
	
	async function loadUsers(){
		const users = await fetchAssignableUsers();
		const list = document.getElementById("userList");
		list.innerHTML = "";
	
		users.forEach(u => {
			const row = document.createElement("div");
			row.className = "da-userRow";
			row.dataset.userref = u.REF;
	
			const roleName =
				(u.ACCESS_LEVEL == 7 ? "Department Head"
				: u.ACCESS_LEVEL == 8 ? "Functional Head"
				: u.ACCESS_LEVEL == 5 ? "Analyst"
				: "User");
			
			const isMissing = (u.ACCESS_LEVEL == 7 && u.departmentCount != 1);
			
			const statusText = (u.ACCESS_LEVEL == 7)
				? (u.departmentCount == 1 ? "✓ Assigned" : "Missing department")
				: `${u.departmentCount} departments`;
	
			row.innerHTML = `
				<div class="da-rowTop">
					<strong>${u.FIRSTNAME} ${u.SURNAME}</strong>
					${isMissing ? `<span class="da-badge da-badge-warn">Needs dept</span>` : ``}
				</div>
				<small>${u.USERNAME}</small><br>
				<small>${roleName} • ${statusText}</small>
			`;
	
			row.onclick = () => selectUser(u);
			list.appendChild(row);
		});
	
		// Auto-select first user once (only if nothing is selected yet)
		if (daFirstLoad && !daSelectedUser && users.length > 0) {
			daFirstLoad = false;
			await selectUser(users[0]);
		}
	}
	
	function setActiveRow(userRef){
		document.querySelectorAll('.da-userRow').forEach(el => el.classList.remove('active'));
		const active = document.querySelector(`.da-userRow[data-userref="${userRef}"]`);
		if (active) active.classList.add('active');
	}
	
	async function selectUser(user){
		daSelectedUser = user;
		await fetchUserDepartments(user.REF);
	
		document.getElementById("editorEmpty").style.display = "none";
		document.getElementById("editorContent").style.display = "block";
	
		document.getElementById("editorName").textContent = `${user.FIRSTNAME} ${user.SURNAME}`;
		document.getElementById("editorEmail").textContent = user.USERNAME;
		document.getElementById("editorRole").textContent =
		(user.ACCESS_LEVEL == 7 ? "Department Head"
		: user.ACCESS_LEVEL == 8 ? "Functional Head"
		: user.ACCESS_LEVEL == 5 ? "Analyst"
		: "User");
	
		setActiveRow(user.REF);
		renderEditor();
	}
	
	function renderEditor(){
		const container = document.getElementById("editorControls");
		container.innerHTML = "";
	
		// Department Head: exactly one (single select)
		if (daSelectedUser.ACCESS_LEVEL == 7) {
	
			const label = document.createElement("div");
			label.className = "muted";
			label.style.marginBottom = "6px";
			label.textContent = "Department (required)";
			container.appendChild(label);
	
			const select = document.createElement("select");
			select.id = "deptSelect";
	
			const blank = document.createElement("option");
			blank.value = "";
			blank.textContent = "Select department…";
			select.appendChild(blank);
	
			daDepartments.forEach(d => {
				const opt = document.createElement("option");
				opt.value = d.REF;
				opt.textContent = d.DEPARTMENT;
				if (daUserDepartments.includes(d.REF)) opt.selected = true;
				select.appendChild(opt);
			});
	
			container.appendChild(select);
		}
	
		// Functional Head + Analyst: multiple (tags)
		if (daSelectedUser.ACCESS_LEVEL == 8 || daSelectedUser.ACCESS_LEVEL == 5) {
	
			const label = document.createElement("div");
			label.className = "muted";
			label.style.marginBottom = "6px";
			label.textContent = "Departments";
			container.appendChild(label);
	
			const addSelect = document.createElement("select");
			addSelect.id = "deptAddSelect";
			
			const start = document.createElement("option");
			start.value = "";
			start.textContent = "Add department…";
			addSelect.appendChild(start);
			
			daDepartments
				.filter(d => !daUserDepartments.includes(Number(d.REF)))
				.forEach(d => {
					const opt = document.createElement("option");
					opt.value = d.REF;
					opt.textContent = d.DEPARTMENT;
					addSelect.appendChild(opt);
				});
			
			// ✅ put it right here
			if (addSelect.options.length === 1) { // only "Add department…" exists
				addSelect.disabled = true;
				addSelect.style.opacity = "0.65";
				start.textContent = "All departments assigned";
			} else {
				addSelect.disabled = false;
				addSelect.style.opacity = "1";
				start.textContent = "Add department…";
			}
			
			addSelect.onchange = () => {
				const val = Number(addSelect.value);
				if (val && !daUserDepartments.includes(val)) {
					daUserDepartments.push(val);
					daUserDepartments.sort((a,b) => a-b);
					renderEditor();
				}
				addSelect.value = "";
			};
	
			container.appendChild(addSelect);
	
			// tags
			daUserDepartments.forEach(ref => {
				const dept = daDepartments.find(d => d.REF == ref);
				if (!dept) return;
	
				const tag = document.createElement("span");
				tag.className = "tag";
				const label = document.createElement("span");
				label.textContent = dept.DEPARTMENT;
				
				const btn = document.createElement("button");
				btn.type = "button";
				btn.setAttribute("aria-label", "Remove department");
				btn.textContent = "×";
				
				btn.onclick = () => {
					daUserDepartments = daUserDepartments.filter(r => r != ref);
					renderEditor();
				};
				
				tag.appendChild(label);
				tag.appendChild(btn);
	
				container.appendChild(tag);
			});
		}
	}
	
	async function saveAssignments(){
		if (!daSelectedUser) return;
	
		let payload = [];
	
		if (daSelectedUser.ACCESS_LEVEL == 7) {
			const val = document.getElementById("deptSelect")?.value || "";
			if (!val) {
				toast("Department Head must have one department", { type: "error" });
				return;
			}
			payload = [Number(val)];
		}
	
		if (daSelectedUser.ACCESS_LEVEL == 8 || daSelectedUser.ACCESS_LEVEL == 5) {
			payload = daUserDepartments.map(Number);
		}
	
		const res = await fetch("../api/setUserDepartments.php", {
			method: "POST",
			headers: { "Content-Type":"application/json", "X-CSRF-Token": daCsrfToken },
			body: JSON.stringify({ userRef: daSelectedUser.REF, departments: payload })
		});
	
		const data = await res.json();
		if (data.status !== "success") {
			toast(data.message || "Save failed", { type: "error" });
			return;
		}
	
		toast("Saved", { type: "success" });
		await loadUsers();
	}
	
	async function clearAssignments(){
		if (!daSelectedUser) return;
	
		const res = await fetch("../api/setUserDepartments.php", {
			method: "POST",
			headers: { "Content-Type":"application/json", "X-CSRF-Token": daCsrfToken },
			body: JSON.stringify({ userRef: daSelectedUser.REF, departments: [] })
		});
	
		const data = await res.json();
		if (data.status !== "success") {
			toast(data.message || "Clear failed", { type: "error" });
			return;
		}
	
		daUserDepartments = [];
		renderEditor();
		toast("Cleared", { type: "success" });
		await loadUsers();
	}
	
	document.getElementById("saveAssignmentsBtn").addEventListener("click", saveAssignments);
	document.getElementById("clearAssignmentsBtn").addEventListener("click", clearAssignments);
	
	document.getElementById("userSearch")?.addEventListener("input", () => {
		clearTimeout(daSearchTimer);
		daSearchTimer = setTimeout(() => {
			loadUsers();
		}, 250);
	});
	document.getElementById("roleFilter")?.addEventListener("change", loadUsers);
	document.getElementById("showInactive")?.addEventListener("change", loadUsers);
	
	// Initialise immediately (works for direct load AND injected load)
	(async () => {
		await fetchDepartments();
		await loadUsers();
	})();
}
</script>


<?php require_once("../includes/footer.php"); ?>