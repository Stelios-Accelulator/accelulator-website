<?php
// --- ensure session + resolve company ref early ---
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

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
$user = checkUser();
$ref = getUsersCompanyId($user);
$GLOBALS['ref'] = $ref; // <-- let crypto.php find the right company key

echo "<script>console.log('[env] MK len', ".strlen(getenv('ACCELULATOR_MASTER_KEY')).");</script>";


/* crypto is optional at bootstrap time — if it’s missing, we stub decrypt_field() so the page still loads */
$cryptoPath = __DIR__ . '/../../includes/crypto.php';
if (is_file($cryptoPath)) {
	require_once $cryptoPath;
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
	function can_view_names(array $userValue): bool {
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

if ($user === '') {
	// checkUser() should have redirected already
	exit;
}

try {
	// ---------- company & tables ----------
	
	if (function_exists('company_data_key')) {
	  try {
		$dk = company_data_key($pdo, $ref);
	  } catch (Throwable $e) {
	  }
	}
	
	
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
	
	
	/* ---------- flexible decrypt wrapper (robust) ---------- */
	/* ---------- flexible decrypt wrapper (robust + quiet) ---------- */
	if (!function_exists('safe_decrypt')) {
	
		/**
		 * Normalises DB cells into raw/base64 binary.
		 * Accepts hex ("0x..."), base64, or binary.
		 */
		function norm_enc($v): array {
			if ($v === null || $v === '') return ['', ''];
			if (is_string($v) && strncasecmp($v, '0x', 2) === 0) {
				$bin = hex2bin(substr($v, 2)) ?: '';
				return [$bin, base64_encode($bin)];
			}
			if (is_string($v) && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $v)) {
				$bin = base64_decode($v, true);
				if ($bin !== false) return [$bin, $v];
			}
			$bin = (string)$v;
			return [$bin, base64_encode($bin)];
		}
	
		/**
		 * Attempts to decrypt using the currently-loaded decrypt_field().
		 * Tries all common argument combinations automatically.
		 * Returns '' if all attempts fail.
		 */
		function safe_decrypt($cipher, $iv = null, $tag = null): string {
			if (!function_exists('decrypt_field')) return '';
	
			// Normalise to both raw + base64
			[$cRaw, $cB64] = norm_enc($cipher);
			[$iRaw, $iB64] = norm_enc($iv);
			[$tRaw, $tB64] = norm_enc($tag);
	
			$companyRef = $GLOBALS['ref'] ?? null;
			$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
	
			try {
				$rf = new ReflectionFunction('decrypt_field');
				$argc = $rf->getNumberOfParameters();
	
				// --- Fast 4-arg path (most likely success case) ---
				if ($argc >= 4) {
					$out = @decrypt_field($cipher, $iv, $tag, $companyRef);
					if (is_string($out) && $out !== '') {
						if ($DEBUG) echo "<script>console.log('[safe_decrypt] fast 4-arg ok');</script>";
						return $out;
					}
				}
	
				// --- Fallback permutations ---
				$attempts = [
					[4, fn() => decrypt_field($cRaw,  $iRaw ?: null, $tRaw ?: null, $companyRef)],
					[4, fn() => decrypt_field($cB64, $iB64 ?: null, $tB64 ?: null, $companyRef)],
					[3, fn() => decrypt_field($cRaw,  $iRaw ?: null, $tRaw ?: null)],
					[3, fn() => decrypt_field($cB64, $iB64 ?: null, $tB64 ?: null)],
					[2, fn() => decrypt_field($cRaw,  $iRaw ?: null)],
					[2, fn() => decrypt_field($cB64, $iB64 ?: null)],
					[1, fn() => decrypt_field($cRaw)],
					[1, fn() => decrypt_field($cB64)],
				];
	
				foreach ($attempts as [$need, $call]) {
					if ($argc >= $need) {
						$out = @($call) ?? '';
						if (is_string($out) && $out !== '') {
							if ($DEBUG) echo "<script>console.log('[safe_decrypt] fallback $need-arg ok');</script>";
							return $out;
						}
					}
				}
	
			} catch (Throwable $e) {
				if ($DEBUG) {
					echo "<script>console.warn('[safe_decrypt] exception', " . json_encode($e->getMessage()) . ");</script>";
				}
			}
	
			// If we get here, all attempts failed
			return '';
		}
	}
	
	/* ---------- build SELECT list for names (encrypt-only safe) ---------- */
	if ($hasEnc) {
		// always fetch encrypted fields + IV/tag if present
		$encSelect = "
			r.FIRSTNAME_ENC, r.MIDDLENAME_ENC, r.SURNAME_ENC,
			" . (isset($cols['FIRSTNAME_IV'])  ? "r.FIRSTNAME_IV,"  : "NULL AS FIRSTNAME_IV,") . "
			" . (isset($cols['MIDDLENAME_IV']) ? "r.MIDDLENAME_IV," : "NULL AS MIDDLENAME_IV,") . "
			" . (isset($cols['SURNAME_IV'])    ? "r.SURNAME_IV,"    : "NULL AS SURNAME_IV,") . "
			" . (isset($cols['NAME_TAG'])      ? "r.NAME_TAG"       : "NULL AS NAME_TAG") . "
		";
	
		// plaintext columns now may not exist; check before referencing them
		$hasPlainCols =
			isset($cols['FIRSTNAME']) ||
			isset($cols['MIDDLENAME']) ||
			isset($cols['SURNAME']);
	
		// If the plain columns are physically present and the user can view names,
		// we include them as LEGACY_*; otherwise we alias NULLs so SQL is valid.
		if ($hasPlainCols && can_view_names($_SESSION ?? [])) {
			$plainOrNullSelect = "
				, r.FIRSTNAME AS LEGACY_FIRSTNAME
				, r.MIDDLENAME AS LEGACY_MIDDLENAME
				, r.SURNAME    AS LEGACY_SURNAME
			";
		} else {
			$plainOrNullSelect = "
				, NULL AS LEGACY_FIRSTNAME
				, NULL AS LEGACY_MIDDLENAME
				, NULL AS LEGACY_SURNAME
			";
		}
	
		$nameSelect = $encSelect . $plainOrNullSelect;
	
	} else {
		// (Only used if encrypted columns don’t exist at all.)
		$nameSelect = "r.FIRSTNAME, r.MIDDLENAME, r.SURNAME";
	}

	/* --- DEBUG helpers (safe in production; remove later) --- */
	if (!function_exists('mo_console')) {
		function mo_console($label, $value = null) {
			$l = json_encode((string)$label);
			$v = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
		}
	}
	
	/* 1) show which can_view_names() we’re actually calling, and its result */
	try {
		$rf = new ReflectionFunction('can_view_names');
		$canSrc = ($rf->getFileName() ?: 'unknown') . ':' . $rf->getStartLine();
	} catch (Throwable $e) { $canSrc = 'reflection-failed'; }
	$canView = can_view_names($_SESSION ?? []);
	mo_console('can_view_names source', $canSrc);
	mo_console('can_view_names($_SESSION) =>', $canView);
	
	/* 2) log which name-select we’re using and the full SQL (collapsed in console) */
	mo_console('hasEnc / depSel', ['hasEnc' => $hasEnc, 'depSel' => $depSel]);
	
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
	mo_console('SQL (resources)', $sql);
	
	/* 3) run & count */
	$resStmt = $pdo->query($sql);
	$resRows = $resStmt->fetchAll(PDO::FETCH_ASSOC);
	
	if (!empty($resRows)) {
		$probe = $resRows[0]['FIRSTNAME_ENC'] ?? null;
		$probePt = '';
		if (function_exists('decrypt_field')) {
			$rf = new ReflectionFunction('decrypt_field');
			$argc = $rf->getNumberOfParameters();
			$companyRef = $GLOBALS['ref'] ?? null;
			if ($argc >= 4) $probePt = @decrypt_field($probe, null, null, $companyRef) ?: '';
			elseif ($argc >= 1) $probePt = @decrypt_field($probe) ?: '';
		}
	}
	
	dbg('resources rows: ' . count($resRows));
	mo_console('resources row count', count($resRows));
	
	/* 4) peek at the first few rows’ encrypted fields (lengths + head bytes) */
	for ($i = 0; $i < min(3, count($resRows)); $i++) {
		$r = $resRows[$i];
		$peek = [
			'RES_REF'          => (int)$r['RES_REF'],
			'FIRSTNAME_ENC_len'=> isset($r['FIRSTNAME_ENC']) ? strlen((string)$r['FIRSTNAME_ENC']) : null,
			'MIDDLENAME_ENC_len'=>isset($r['MIDDLENAME_ENC'])? strlen((string)$r['MIDDLENAME_ENC']) : null,
			'SURNAME_ENC_len'  => isset($r['SURNAME_ENC']) ? strlen((string)$r['SURNAME_ENC']) : null,
			'IV_first_len'     => isset($r['FIRSTNAME_IV']) ? strlen((string)$r['FIRSTNAME_IV']) : null,
			'TAG_len'          => isset($r['NAME_TAG']) ? strlen((string)$r['NAME_TAG']) : null,
			'ENC_head_hex'     => isset($r['FIRSTNAME_ENC']) ? substr(bin2hex((string)$r['FIRSTNAME_ENC']),0,16) : null,
		];
		mo_console('peek resource enc fields', $peek);
	}
	
	/* 5) normal render, with per-row logging of decryption + JS push */
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
			if ($canView) {
				$tag = $row['NAME_TAG'] ?? null;
		
				$firstname  = safe_decrypt($row['FIRSTNAME_ENC']  ?? null, $row['FIRSTNAME_IV']  ?? null, $tag);
				$middlename = safe_decrypt($row['MIDDLENAME_ENC'] ?? null, $row['MIDDLENAME_IV'] ?? null, $tag);
				$surname    = safe_decrypt($row['SURNAME_ENC']    ?? null, $row['SURNAME_IV']    ?? null, $tag);
		
				// ⬇️ fallback to legacy if decrypt failed or returned empty strings
				if ($firstname === '' && isset($row['LEGACY_FIRSTNAME'])) {
					$firstname  = (string)($row['LEGACY_FIRSTNAME']  ?? '');
					$middlename = (string)($row['LEGACY_MIDDLENAME'] ?? '');
					$surname    = (string)($row['LEGACY_SURNAME']    ?? '');
					mo_console('decrypted empty → using legacy', ['RES_REF'=>$id,'first'=>$firstname,'last'=>$surname]);
				} else {
					mo_console('decrypted names', ['RES_REF'=>$id,'first'=>$firstname,'middle'=>$middlename,'last'=>$surname]);
				}
			} else {
				$firstname = 'Employee'; $middlename = ''; $surname = '#'.$id;
				mo_console('masked names (no view)', ['RES_REF'=>$id,'first'=>$firstname,'last'=>$surname]);
			}
		} else {
			$firstname  = (string)($row['FIRSTNAME']  ?? '');
			$middlename = (string)($row['MIDDLENAME'] ?? '');
			$surname    = (string)($row['SURNAME']    ?? '');
			mo_console('legacy names used', ['RES_REF'=>$id,'first'=>$firstname,'last'=>$surname]);
		}
	
		// JS-escape
		$fn = addslashes($firstname);
		$sn = addslashes($surname);
	
		// 6) log the exact JS we’re about to push
		mo_console('push Resource()', [
			'RES_REF'=>$id,'first'=>$firstname,'last'=>$surname,
			'start'=>$start_date,'end'=>$end_date,'salary'=>$annualSalary,
			'fte'=>$fte,'pension'=>$pension,'department'=>$department,'contractType'=>$contractType
		]);
	
		echo "
		<script>
	    	resource_$id = new Resource('$id','Unallocated','$fn','$sn','$start_date','$end_date','$annualSalary','$fte','$pension','$x','$department','$contractType');
	  	  	lib_resources.push(resource_$id);
		</script>
		";
		
		$x++;
	}

	// ---------- ACTUALS ----------
	if ($depSel === 0) {
		$actSql = "
			SELECT 
				a.EMP_KEY, 
				a.DATE, 
				p.PAYTYPE_GROUP_REF AS TYPE, 
				a.VALUE
			FROM $t_actuals a
			LEFT JOIN $t_paytype p ON a.TYPE = p.REF
		";
	} else {
		$actSql = "
			SELECT 
				a.EMP_KEY, 
				a.DATE, 
				p.PAYTYPE_GROUP_REF AS TYPE, 
				a.VALUE
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
		$typ = $row['TYPE']; // was previously, incorrectly, designated as an int e.g. (int)$r['TYPE']
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
		
				if (typeof loadPayRises === 'function') await loadPayRises(); // ✅ add this
				renderMonthlyOutturn();
			} catch (e) {
				console.error('[monthlyOutturn] init failed:', e);
			}
		})();
	
	renderMonthlyOutturn();
</script>
_BOOT;