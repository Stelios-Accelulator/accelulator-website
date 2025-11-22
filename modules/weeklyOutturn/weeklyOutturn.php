<?php
// modules/weeklyOutturn/weeklyOutturn.php

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }

require_once __DIR__ . '/../../includes/functions.php';

$user = checkUser();
if ($user === '') {
	// checkUser should redirect, but just in case:
	exit;
}

$ref = getUsersCompanyId($user);
$GLOBALS['ref'] = $ref;

// make sure $pdo exists if you need it later (for other includes)
if (!isset($pdo) || !($pdo instanceof PDO)) {
	if (function_exists('getPdo')) {
		$pdo = getPdo();
	} elseif (function_exists('connectToDb')) {
		$pdo = connectToDb();
	}
}

?>
<div class="staffcast-wrapper">
	<div class="staffcast-toolbar" id="weeklyOutturnToolbar">
		<h1>Weekly People Costs (4–4–5)</h1>

		<div class="toolbar-row">
			<label for="weeklyYear">Year</label>
			<select id="weeklyYear">
				<?php
				$y = (int)date('Y');
				for ($i = -1; $i <= 2; $i++) {
					$yr = $y + $i;
					$sel = $i === 0 ? " selected" : "";
					echo "<option value=\"{$yr}\"{$sel}>{$yr}</option>";
				}
				?>
			</select>

			<label for="weeklyDepartment">Department</label>
			<select id="weeklyDepartment">
				<option value="0">All departments</option>
				<?php
				// optional: pre-populate departments drop-down
				$t_departments = "{$ref}_departments";
				try {
					$rows = $pdo->query("SELECT REF, DEPARTMENT FROM $t_departments ORDER BY DEPARTMENT ASC")
						->fetchAll(PDO::FETCH_ASSOC);
					foreach ($rows as $r) {
						$id = (int)$r['REF'];
						$name = htmlspecialchars($r['DEPARTMENT'], ENT_QUOTES);
						echo "<option value=\"{$id}\">{$name}</option>";
					}
				} catch (Throwable $e) {
					// silently ignore; JS can fall back to cookie dep
				}
				?>
			</select>
		</div>
	</div>

	<div id="weeklyOutturnView"></div>

	<div class="chart-card">
		<canvas id="weeklyOutturnChart"></canvas>
	</div>
</div>

<script src="/modules/weeklyOutturn/weeklyOutturn.js"></script>
<script>
	(async function () {
		try {
			await loadWeeklyOutturn();
		} catch (e) {
			console.error('[weeklyOutturn] init failed:', e);
		}
	})();
</script>