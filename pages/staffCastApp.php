<?php
// /pages/staffCastApp.php
$inject = isset($_GET['inject']) && $_GET['inject'] == '1';

require_once("../includes/functions.php"); // ensure $pdo exists in both modes
if (!$inject) {
	require_once("../includes/header.php");
}

// ---- session identifiers (defensive) ----
$userref   = $_SESSION['USERREF']   ?? $_SESSION['userref']   ?? null;
$companyId = $_SESSION['COMPANY_ID'] ?? $_SESSION['company_id'] ?? $_SESSION['COMPANY'] ?? null;

// if you have a single canonical session key, replace the above with that.

// ---- access level ----
$accessLevel = null;
if ($userref !== null) {
	$stmt = $pdo->prepare("SELECT ACCESS_LEVEL FROM user_access WHERE USERREF = :u LIMIT 1");
	$stmt->execute([':u' => $userref]);
	$accessLevel = (int)($stmt->fetchColumn() ?? 0);
}

// Access levels that should be dept-restricted in StaffCast
$deptRestrictedLevels = [5, 7, 8]; // VIEW_ANALYST, DEPT_MGR, Functional Head (based on your screenshots)

// ---- load allowed depts (if restricted) ----
$allowedDepartments = [];
$hasDeptRestriction = in_array($accessLevel, $deptRestrictedLevels, true);

if ($hasDeptRestriction) {

	// Your department table naming convention looks like "{companyId}_departments"
	$deptTable = intval($companyId) . "_departments";

	$sql = "
		SELECT d.REF AS dept_ref, d.DEPARTMENT AS dept_name
		FROM user_departments ud
		JOIN {$deptTable} d ON d.REF = ud.DEPT_REF
		WHERE ud.COMPANY_ID = :cid AND ud.USERREF = :u
		ORDER BY d.DEPARTMENT
	";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([':cid' => $companyId, ':u' => $userref]);
	$allowedDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// If they are restricted but have no departments, we should block (same behaviour as Current Position)
	if (!$allowedDepartments) {
		// You already implemented admin list on Current Position; re-use that exact query/pattern here.
		// For now: simple message. If you want, paste your working “admin contacts” block and I’ll splice it in cleanly.
		http_response_code(403);
		echo "<div class='padded'><h2>Department access not configured</h2><p>Please contact your administrator to assign your department access (Company Settings → Department Assignments).</p></div>";
		exit;
	}
}
?>

<style>
	.summary-right{
		min-height: 350px;
	}
	.chart-card{
		min-height: 350px;
	}
</style>
<div class="padded">
	<div id="peopleForecastFilterContainer">
		
		<div id="monthFilter" class="peopleForecastFilters">
			<div>
				<label for="months"><strong>Month</strong></label>
			</div>
			<div>
				<select name="months" id="months" onchange="refreshStaffCastTables();"></select>
			</div>
		</div>
		
		<div id="departmentFilter" class="peopleForecastFilters">
			<div>
				<label for="departmentDisplaySelector"><strong>Department</strong></label>
			</div>
			<div>
				<select id="departmentDisplaySelector" name="departmentDisplaySelector"></select>
			</div>
		</div>
		
		<div id="employeeTypeFilter" class="peopleForecastFilters">
			<div>
				<label for="contractType"><strong>Type</strong></label>
			</div>
			<div>
				<select name="contractType" id="contractType" onchange="changeContractTypeView();">
					<option value="0">All</option>
					<option value="1">Permanent</option>
					<option value="2">Fixed Term</option>
					<option value="3">Contractor</option>
					<option value="4">Temporary</option>
				</select>
			</div>
		</div>
		
		<div id="payTypeDiv" class="peopleForecastFilters">
			<div>
				<label for="payType"><strong>Pay element</strong></label>
			</div>
			<div>
				<select name="payType" id="payType" onchange="refreshStaffCastTables();">
				</select>
			</div>
		</div>
		
		<div id="forecastFilter" class="peopleForecastFilters">
			<div>
				<label for="forecastSelect"><strong>Forecast</strong></label>
				<select name="forecastSelect" id="forecastSelect" onchange="changeForecastView();">
				</select>
			</div>	
		</div>
	</div>
	<div id="dynamicToolbarView"></div>
	<h1>StaffCast</h1>
	<div>
		<h2>Departmental Summary</h2>
		<div style="display:flex; gap:20px; align-items:flex-start;">
			
			<div class="summary-row">
			  
			  <!-- LEFT: Departmental Summary -->
			  <div class="table-wrap summary-wrap">
			  	<div id="summarisedOutturnView" class="summary-left"></div>
			  </div>
			
			  <!-- RIGHT: Chart -->
			  <aside class="summary-right">
				<div class="chart-card">
				  <canvas id="peopleCostsChart" aria-label="People costs chart"></canvas>
				</div>
			  </aside>
			  
			</div>
			
			<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
		
		</div>
	</div>
	<div>
		<h2>Detailed Table</h2>
		<div class="table-wrap summary-wrap">
			<div id="monthlyOutturnView"></div>
		</div>
	</div>
</div>

<script>

	// Ensure #empty exists
	if (!document.getElementById('empty')) {
		const emptyDiv = document.createElement('div');
		emptyDiv.id = 'empty';
		emptyDiv.style.display = 'none'; // keep it invisible if it's just a loader container
		document.body.appendChild(emptyDiv);
	}
	
	// Now it's safe to load into it
	$('#empty').load("/scripts/getPayType.php");
	
	// Load the default monthArray, using the current month as a baseline
	monthArray = generateMonthRange(0); // Calls the function to populate monthArray with 7 months prior and 6 months future; offset is set at zero
	
	// populate the month selector with the months
	populateMonthSelector(monthOffset);
	offsetValue = document.getElementById('months').value;
	actualMonth = document.getElementById('months').options[6-offsetValue].text;
	fadeLoadContent("dynamicToolbarView","/modules/dynamicToolbar/dynamicToolbar.php",200,300); // function in javascript_functions.js
	fadeLoadContent("monthlyOutturnView","/modules/monthlyOutturn/monthlyOutturn.php",200,300); // function in javascript_functions.js
	fadeLoadContent("summarisedOutturnView","/modules/summarisedOutturn/summarisedOutturn.php",200,300); // function in javascript_functions.js
	if(getCookie("department")!=null){
		selectedDepartment = getCookie("department");
	}else{
		selectedDepartment = 0;
	}
	
	// PHP -> JS
	// PHP -> JS (safe for reinjection)
	window.STAFFCAST_HAS_DEPT_RESTRICTION =
		(typeof window.STAFFCAST_HAS_DEPT_RESTRICTION === 'undefined')
			? <?= $hasDeptRestriction ? 'true' : 'false' ?>
			: window.STAFFCAST_HAS_DEPT_RESTRICTION;
	
	window.STAFFCAST_ALLOWED_DEPTS =
		(typeof window.STAFFCAST_ALLOWED_DEPTS === 'undefined')
			? <?= json_encode($allowedDepartments, JSON_UNESCAPED_SLASHES) ?>
			: window.STAFFCAST_ALLOWED_DEPTS;
	
	function populateDepartmentSelector() {
		const sel = document.getElementById('departmentDisplaySelector');
		if (!sel) return;
	
		sel.innerHTML = '';
	
		// All (means all allowed if restricted)
		const optAll = document.createElement('option');
		optAll.value = '0';
		optAll.textContent = 'All';
		sel.appendChild(optAll);
	
		// If restricted, only show allowed list. If not restricted, you can keep current behaviour
		// (but ideally you should populate the full department list here too, as you do elsewhere).
		if (STAFFCAST_HAS_DEPT_RESTRICTION) {
			for (const d of STAFFCAST_ALLOWED_DEPTS) {
				const opt = document.createElement('option');
				opt.value = String(d.dept_ref);
				opt.textContent = d.dept_name;
				sel.appendChild(opt);
			}
		}
	
		// Validate cookie-selected department
		let dept = selectedDepartment ?? 0;
		dept = String(dept);
	
		if (STAFFCAST_HAS_DEPT_RESTRICTION && dept !== '0') {
			const allowedSet = new Set(STAFFCAST_ALLOWED_DEPTS.map(d => String(d.dept_ref)));
			if (!allowedSet.has(dept)) {
				dept = '0';
				setCookie('department', '0', 365);
			}
		}
	
		sel.value = dept;
	}
	
	// Optional: kick the page once after we set the selector,
	// but only when the injected module JS has finished loading.
	(function refreshAfterModulesReady(){
		const maxWaitMs = 4000;
		const start = Date.now();
	
		(function tick() {
			// Wait until the module JS has loaded
			if (typeof window.reloadMonthlyOutturn === 'function') {
	
				// Do ONE unified refresh only (both tables + chart)
				refreshStaffCastTables();
	
				return;
			}
	
			if (Date.now() - start > maxWaitMs) return;
			setTimeout(tick, 80);
		})();
	})();
	
	function refreshStaffCastTables() {
		// createTable() lives in monthlyOutturn.js (loaded via module)
		if (typeof window.createTable === 'function') {
			try { window.createTable(); } catch (e) { console.error('[StaffCast] createTable failed', e); }
		}

		// createSummaryTable() lives in summarisedOutturn.js (loaded via module)
		if (typeof window.createSummaryTable === 'function') {
			try { window.createSummaryTable(); } catch (e) { console.error('[StaffCast] createSummaryTable failed', e); }
		}
	}
	
	// Run after selectedDepartment is determined
	populateDepartmentSelector();
	
</script>