<?php
session_start();

/* ---------- super-robust bootstrap & fatal diagnostics ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
register_shutdown_function(function() use ($DEBUG) {
	$e = error_get_last();
	if ($e && ($e['type'] & (E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR))) {
		http_response_code(500);
		$msg = "[monthlyOutturn fatal] {$e['message']} in {$e['file']}:{$e['line']}";
		// echo to browser in debug, and always write server error_log
		if ($DEBUG) echo '<pre style="white-space:pre-wrap;color:#b00;background:#fee;border:1px solid #f99;padding:8px;">'
						. htmlspecialchars($msg) . '</pre>';
		error_log($msg);
	}
});

/* ---------- includes with guards ---------- */
require_once __DIR__ . '/../../includes/functions.php';

/* crypto is optional at bootstrap time — if it’s missing, we stub decrypt_field() so the page still loads */
$cryptoPath = __DIR__ . '/../../includes/crypto.php';
if (is_file($cryptoPath)) {
	require_once $cryptoPath;
}
if (!function_exists('decrypt_field')) {
	function decrypt_field($cipherB64, $ivB64) { return ''; } // safe no-op; names will come out blank/pseudonymised
}

/* ---------- make sure we actually have a PDO ---------- */
if (!isset($pdo) || !($pdo instanceof PDO)) {
	// Try a couple of common helpers your codebase uses. If neither exists, this will stay null and our later try/catch will report it.
	if (function_exists('getPdo')) {
		$pdo = getPdo();
	} elseif (function_exists('connectToDb')) {
		$pdo = connectToDb();
	}
}

// ---------- small helpers ----------
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

function dbg($msg){
	global $DEBUG;
	if ($DEBUG) {
		echo '<pre style="margin:6px 0;color:#b00;background:#fee;border:1px solid #f99;padding:6px;">[DEBUG] '
			 . htmlspecialchars($msg) . '</pre>';
	}
}

// ⬇️ ADD THIS GUARD (or just delete this whole block if you prefer)
if (!function_exists('can_view_names')) {
	function can_view_names(array $userCtx): bool {
		// fallback behaviour; your version in functions.php will take precedence
		return true;
	}
}

// ---------- initialise JS arrays exactly like legacy ----------
echo <<<_JS
<script>
  var employeeLibrary = [],
	  lib_resources   = [],
	  objects         = [],
	  departments     = [],
	  forecasts       = [],
	  userOutturn     = [],
	  niBands         = [],
	  niBandLookup    = [],
	  roles           = [];
</script>
_JS;

$user = checkUser();
if ($user === '') {
	// checkUser() should have redirected already
	exit;
}

try {
	// ---------- company & tables ----------
	$ref  = getUsersCompanyId($user);
	$depSel = 0;
	if (isset($_COOKIE['department']) && ctype_digit((string)$_COOKIE['department'])) {
		$depSel = (int)$_COOKIE['department'];
	}

	$t_resources   = "{$ref}_resources";
	$t_details     = "{$ref}_details";
	$t_actuals     = "{$ref}_actuals";
	$t_roles       = "{$ref}_roles";
	$t_departments = "{$ref}_departments";
	$t_forecasts   = "{$ref}_forecasts";
	$t_paytype     = "{$ref}_paytype";
	$t_paygrp      = "{$ref}_paytype_group";
	$t_outturn     = "{$ref}_outturn";

	dbg("Tables resolved for ref=$ref");

	/* ---------- detect encrypted name columns (IV/tag optional) ---------- */
	$colStmt = $pdo->prepare("
	  SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
	  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl
	");
	$colStmt->execute([':tbl' => $t_resources]);
	$cols = array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME', 'COLUMN_NAME');
	
	$hasEnc = isset($cols['FIRSTNAME_ENC']) && isset($cols['SURNAME_ENC']);
	$hasIv  = isset($cols['FIRSTNAME_IV'])  && isset($cols['SURNAME_IV']);
	$hasTagPerCol =
		(isset($cols['FIRSTNAME_TAG']) || isset($cols['NAME_TAG'])) &&
		(isset($cols['SURNAME_TAG'])   || isset($cols['NAME_TAG']));
	dbg('Encrypted columns present? ' . ($hasEnc ? 'YES' : 'NO') .
		' (IV cols: ' . ($hasIv ? 'YES' : 'NO') .
		', tag cols: ' . ($hasTagPerCol ? 'YES' : 'NO') . ')');
	
	/* ---------- flexible decrypt wrapper ---------- */
	if (!function_exists('safe_decrypt')) {
		function safe_decrypt($cipherB64, $ivB64 = null, $tagB64 = null){
			if (!$cipherB64) return '';
			try {
				if (!function_exists('decrypt_field')) return '';
				$rf = new ReflectionFunction('decrypt_field');
				$argc = $rf->getNumberOfParameters();
				if ($argc >= 3) {
					return decrypt_field($cipherB64, $ivB64, $tagB64) ?: '';
				} elseif ($argc == 2) {
					return decrypt_field($cipherB64, $ivB64) ?: '';
				} else {
					return decrypt_field($cipherB64) ?: '';
				}
			} catch (Throwable $e) {
				dbg('decrypt error: ' . $e->getMessage());
				return '';
			}
		}
	}
	
	/* ---------- build SELECT list for names ---------- */
	/* We only decide which columns to fetch here. Decryption happens later in the loop. */
	if ($hasEnc) {
		if (can_view_names($_SESSION ?? [])) {
			// fetch encrypted fields + ivs (if present) + a common tag (NAME_TAG) if you store one
			$nameSelect = "
				r.FIRSTNAME_ENC,
				r.MIDDLENAME_ENC,
				r.SURNAME_ENC,
				" . (isset($cols['FIRSTNAME_IV'])  ? "r.FIRSTNAME_IV,"  : "NULL AS FIRSTNAME_IV,") . "
				" . (isset($cols['MIDDLENAME_IV']) ? "r.MIDDLENAME_IV," : "NULL AS MIDDLENAME_IV,") . "
				" . (isset($cols['SURNAME_IV'])    ? "r.SURNAME_IV,"    : "NULL AS SURNAME_IV,") . "
				" . (isset($cols['NAME_TAG'])      ? "r.NAME_TAG"       : "NULL AS NAME_TAG") . "
			";
		} else {
			// don’t fetch any sensitive data if the user can’t view names
			$nameSelect = "
				NULL AS FIRSTNAME_ENC, NULL AS MIDDLENAME_ENC, NULL AS SURNAME_ENC,
				NULL AS FIRSTNAME_IV,  NULL AS MIDDLENAME_IV,  NULL AS SURNAME_IV,
				NULL AS NAME_TAG
			";
		}
	} else {
		// legacy plain columns
		$nameSelect = "r.FIRSTNAME, r.MIDDLENAME, r.SURNAME";
	}

	$sql = "
		SELECT
			r.REF AS RES_REF,
			$nameSelect,
			d.START_DATE, d.END_DATE, d.ANNUAL_SALARY, d.FTE, d.PENSION,
			r.DEPARTMENT, r.CONTRACT_TYPE, d.EMP_KEY
		FROM $t_resources r
		LEFT JOIN $t_details d ON r.REF = d.EMP_KEY
		" . ($depSel !== 0 ? "WHERE r.DEPARTMENT = $depSel" : "") . "
	";

	$resStmt = $pdo->query($sql);
	$resRows = $resStmt->fetchAll(PDO::FETCH_ASSOC);
	dbg('resources rows: ' . count($resRows));

	$x = 0;
	foreach ($resRows as $row) {
		$id           = (int)$row['RES_REF'];
		$start_date   = $row['START_DATE'];
		$end_date     = $row['END_DATE'];
		$annualSalary = $row['ANNUAL_SALARY'];
		$fte          = $row['FTE'];
		$pension      = $row['PENSION'];
		$department   = $row['DEPARTMENT'];
		$contractType = $row['CONTRACT_TYPE'];
	
		if ($hasEnc) {
			if (can_view_names($_SESSION ?? [])) {
				// use NAME_TAG if present; otherwise null is fine
				$tag = $row['NAME_TAG'] ?? null;
	
				$firstname  = safe_decrypt($row['FIRSTNAME_ENC']  ?? null, $row['FIRSTNAME_IV']  ?? null, $tag);
				$middlename = safe_decrypt($row['MIDDLENAME_ENC'] ?? null, $row['MIDDLENAME_IV'] ?? null, $tag);
				$surname    = safe_decrypt($row['SURNAME_ENC']    ?? null, $row['SURNAME_IV']    ?? null, $tag);
			} else {
				$firstname = 'Employee'; $middlename = ''; $surname = '#'.$id;
			}
		} else {
			$firstname  = (string)($row['FIRSTNAME']  ?? '');
			$middlename = (string)($row['MIDDLENAME'] ?? '');
			$surname    = (string)($row['SURNAME']    ?? '');
		}
	
		// JS-escape
		$fn = addslashes($firstname);
		$sn = addslashes($surname);
	
		echo <<<JS
	<script>
	  resource_$id = new Resource('$id','Unallocated','$fn','$sn','$start_date','$end_date','$annualSalary','$fte','$pension','$x','$department','$contractType');
	  lib_resources.push(resource_$id);
	</script>
	JS;
		$x++;
	}

	// ---------- ACTUALS ----------
	if ($depSel === 0) {
		$actSql = "
			SELECT a.EMP_KEY, a.DATE, p.PAYTYPE_GROUP_REF AS TYPE, a.VALUE
			FROM $t_actuals a
			LEFT JOIN $t_paytype p ON a.TYPE = p.REF
		";
	} else {
		$actSql = "
			SELECT a.EMP_KEY, a.DATE, p.PAYTYPE_GROUP_REF AS TYPE, a.VALUE
			FROM $t_actuals a
			LEFT JOIN $t_paytype p ON a.TYPE = p.REF
			LEFT JOIN $t_resources r ON a.EMP_KEY = r.REF
			WHERE r.DEPARTMENT = $depSel
		";
	}
	$actRows = $pdo->query($actSql)->fetchAll(PDO::FETCH_ASSOC);
	dbg('actuals rows: ' . count($actRows));

	foreach ($actRows as $row) {
		$emp = (int)$row['EMP_KEY'];
		$dt  = dateToMMM_YY($row['DATE']);
		$typ = (int)$row['TYPE'];
		$val = (float)$row['VALUE'];
		echo "<script>populateResourceActuals(resource_{$emp}, '$dt', $typ, $val);</script>";
	}

	// ---------- ROLES ----------
	$roleSql = ($depSel === 0)
		? "SELECT * FROM $t_roles"
		: "SELECT * FROM $t_roles WHERE DEPARTMENT = $depSel";
	$roleRows = $pdo->query($roleSql)->fetchAll(PDO::FETCH_ASSOC);
	dbg('roles rows: ' . count($roleRows));

	$x = 0;
	foreach ($roleRows as $row) {
		$k     = (int)$row['REF'];
		$jt    = addslashes($row['JOB_TITLE']);
		$dep   = $row['DEPARTMENT'];
		$filled= $row['FILLED_REFERENCE'];
		$stat  = $row['STATUS'];
		$bfte  = $row['BENCHMARK_FTE'];
		$bsal  = $row['BENCHMARK_SALARY'];
		$bpsal = $row['BENCHMARK_PRORATA_SALARY'];
		$sd    = $row['START_DATE'];
		$ed    = $row['END_DATE'];
		$ct    = $row['CONTRACT_TYPE'];
		$pens  = 0.04;

		echo <<<JS
<script>
  role_$x = new Role($k,'$jt','$dep','$filled','$stat','$bfte','$bsal','$bpsal','$sd','$ed','$ct','$pens','$x');
  roles.push(role_$x);
</script>
JS;
		$x++;
	}

	// ---------- DEPARTMENTS ----------
	$depRows = $pdo->query("SELECT * FROM $t_departments")->fetchAll(PDO::FETCH_ASSOC);
	dbg('departments rows: ' . count($depRows));

	$x = 0;
	foreach ($depRows as $row) {
		$id   = (int)$row['REF'];
		$name = addslashes($row['DEPARTMENT']);
		echo <<<JS
<script>
  department_$x = new Department($id,'$name');
  departments.push(department_$x);
</script>
JS;
		$x++;
	}

	// ---------- USER OUTTURN ----------
	$outRows = $pdo->query("SELECT * FROM $t_outturn")->fetchAll(PDO::FETCH_ASSOC);
	dbg('outturn rows: ' . count($outRows));

	$c = 0;
	$typeLookup = $pdo->prepare("SELECT VALUE FROM $t_paygrp WHERE REF = :r LIMIT 1");
	foreach ($outRows as $out) {
		$rRef    = (int)$out['EMP_KEY'];
		$res_rol = $out['RES_ROL'];
		$date    = dateToMMM_YY($out['DATE']);
		$typeId  = (int)$out['TYPE'];
		$value   = (float)$out['VALUE'];

		$typeLookup->execute([':r' => $typeId]);
		$row = $typeLookup->fetch(PDO::FETCH_ASSOC);
		$t = $row ? $row['VALUE'] : (string)$typeId;

		$library = ($res_rol === 'resource') ? 'lib_resources' : 'roles';

		echo <<<JS
<script>
  if (!userOutturn[$c]) userOutturn[$c] = {};
  if (!userOutturn[$c].outturn) userOutturn[$c].outturn = {};
  if (!userOutturn[$c].outturn['$date']) userOutturn[$c].outturn['$date'] = {};
  userOutturn[$c].outturn['$date']['$t'] = $value;
  userOutturn[$c].library = '$library';
  userOutturn[$c].ref = $rRef;
</script>
JS;
		$c++;
	}

	// ---------- FORECAST LIST (published only) ----------
	$fcRows = $pdo->query("
		SELECT ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
		FROM $t_forecasts
		WHERE IS_PUBLISHED = 1
		GROUP BY ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
	")->fetchAll(PDO::FETCH_ASSOC);
	dbg('forecasts rows: ' . count($fcRows));

	$x = 0;
	foreach ($fcRows as $row) {
		$af   = addslashes($row['ACTUAL_FORECAST']);
		$name = addslashes($row['FORECAST_NAME']);
		$ver  = addslashes($row['FORECAST_VERSION']);
		echo <<<JS
<script>
  temp = new ForecastList($x,'$af','$name','$ver');
  forecasts.push(temp);
</script>
JS;
		$x++;
	}

	// ---------- NI bands ----------
	$niStmt = $pdo->query("
		SELECT
		  DATE_FORMAT(FROM_DATE, '%Y-%m-%d') AS FROM_DATE,
		  DATE_FORMAT(TO_DATE,   '%Y-%m-%d') AS TO_DATE,
		  SECONDARY_THRESHOLD_MONTHLY, RATE
		FROM ni_employers_rates ORDER BY FROM_DATE ASC
	");
	$niRows = $niStmt->fetchAll(PDO::FETCH_ASSOC);
	dbg('NI bands: ' . count($niRows));

	foreach ($niRows as $row) {
		$from = $row['FROM_DATE'];
		$to   = $row['TO_DATE'];
		$thr  = (float)$row['SECONDARY_THRESHOLD_MONTHLY'];
		$rate = $row['RATE'];
		echo <<<JS
<script>
  temp = { FROM_DATE: '$from', TO_DATE: '$to', SECONDARY_THRESHOLD_MONTHLY: $thr, RATE: '$rate' };
  niBands.push(temp);
</script>
JS;
	}
	echo <<<JS
<script>
  niBands.forEach(function(b){
	var y = new Date(b.FROM_DATE).getFullYear();
	niBandLookup[y] = b;
  });
</script>
JS;

} catch (Throwable $e) {
	// Never white-screen: show a clear message (and 500 status) if something goes wrong.
	http_response_code(500);
	echo '<pre style="white-space:pre-wrap;color:#b00;background:#fee;border:1px solid #f99;padding:8px;">'.
		 "monthlyOutturn fatal error:\n" . htmlspecialchars($e->getMessage()) . "</pre>";
	// bail out early—don’t run the boot JS
	return;
}

// ---------- page boot JS (same order as legacy) ----------
echo '<script src="/modules/monthlyOutturn/monthlyOutturn.js"></script>';
echo <<<_BOOT
<script>
  (async function(){
	try{
	  applyRolesToEmployees();
	  applyDepartments();

	  // legacy call still used in UI
	  $('#empty').load('/scripts/getDepartments.php');

	  const res = await fetch('/scripts/getForecast.php', {
		credentials: 'same-origin',
		headers: { 'Accept': 'application/json' }
	  });
	  const txt = await res.text();
	  if (!res.ok) throw new Error('HTTP ' + res.status + ': ' + txt);
	  window.forecastRows = txt ? JSON.parse(txt) : {};

	  allocateForecast();
	  allocateRoles();
	  populateForecastOptions();
	  createTable();
	  if (typeof window.createSummaryTable === 'function') {
		window.createSummaryTable();
	  }
	} catch (e) {
	  console.error('[monthlyOutturn] init failed:', e);
	}
  })();
</script>
_BOOT;