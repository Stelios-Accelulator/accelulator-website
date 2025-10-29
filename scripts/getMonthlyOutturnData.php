<?php
// /scripts/getMonthlyOutturnData.php
declare(strict_types=1);

// --- bootstrap ---
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crypto.php'; // keeps company_data_key() + decrypt_field()

header('Content-Type: application/json; charset=utf-8'); // Included as it being called from a fetch script in monthlyOutturn.js

try {
	$user = checkUser(); // will redirect if not logged-in
	$ref  = getUsersCompanyId($user);
	$GLOBALS['ref'] = $ref;         // used by crypto.php
	global $pdo;                    // use the already-initialized global (per your preference)

	// Params
	$depSel = 0; // default department
	$raw = file_get_contents('php://input');
	if ($raw) {
		$body = json_decode($raw, true);
		if (isset($body['dep']) && is_numeric($body['dep'])) {
			$depSel = (int)$body['dep'];
		}
	}
	
	// SANITY CHECK: Outputs the department, user, and company
	error_log("[getMonthlyOutturnData] depSel = $depSel for user $user in company $ref");

	// ---------- helpers ----------
	$canView = function_exists('can_view_names') ? can_view_names($_SESSION ?? []) : true;

	// Normalise encodings for decryption
	if (!function_exists('norm_enc')) {
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
	}
	if (!function_exists('safe_decrypt')) {
		function safe_decrypt($cipher, $iv=null, $tag=null): string {
			if (!function_exists('decrypt_field')) return '';
			[$cRaw, $cB64] = norm_enc($cipher);
			[$iRaw, $iB64] = norm_enc($iv);
			[$tRaw, $tB64] = norm_enc($tag);
			$companyRef = $GLOBALS['ref'] ?? null;

			try {
				$rf = new ReflectionFunction('decrypt_field');
				$argc = $rf->getNumberOfParameters();

				if ($argc >= 4) {
					$out = @decrypt_field($cipher, $iv, $tag, $companyRef);
					if (is_string($out) && $out !== '') return $out;
				}
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
						if (is_string($out) && $out !== '') return $out;
					}
				}
			} catch (Throwable $e) {}
			return '';
		}
	}

	// ---------- table names ----------
	$t_resources   = "{$ref}_resources";
	$t_details     = "{$ref}_details";
	$t_actuals     = "{$ref}_actuals";
	$t_roles       = "{$ref}_roles";
	$t_departments = "{$ref}_departments";
	$t_forecasts   = "{$ref}_forecasts";
	$t_paytype     = "{$ref}_paytype";
	$t_paygrp      = "{$ref}_paytype_group";
	$t_outturn     = "{$ref}_outturn";

	// ---------- columns present? ----------
	$colStmt = $pdo->prepare("
		SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl
	");
	$colStmt->execute([':tbl' => $t_resources]);
	$cols = array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME', 'COLUMN_NAME');
	$hasEnc = isset($cols['FIRSTNAME_ENC']) && isset($cols['SURNAME_ENC']);

	// ---------- RESOURCES (with name decryption) ----------
	if ($hasEnc) {
		$nameSelect = "
			r.FIRSTNAME_ENC, r.MIDDLENAME_ENC, r.SURNAME_ENC,
			" . (isset($cols['FIRSTNAME_IV'])  ? "r.FIRSTNAME_IV"  : "NULL AS FIRSTNAME_IV") . ",
			" . (isset($cols['MIDDLENAME_IV']) ? "r.MIDDLENAME_IV" : "NULL AS MIDDLENAME_IV") . ",
			" . (isset($cols['SURNAME_IV'])    ? "r.SURNAME_IV"    : "NULL AS SURNAME_IV") . ",
			" . (isset($cols['NAME_TAG'])      ? "r.NAME_TAG"      : "NULL AS NAME_TAG") . "
		";
		// include legacy columns as fallback only if the viewer is allowed
		if ($canView) {
			$nameSelect .= ",
				r.FIRSTNAME AS LEGACY_FIRSTNAME,
				r.MIDDLENAME AS LEGACY_MIDDLENAME,
				r.SURNAME AS LEGACY_SURNAME";
		} else {
			$nameSelect .= ",
				NULL AS LEGACY_FIRSTNAME, NULL AS LEGACY_MIDDLENAME, NULL AS LEGACY_SURNAME";
		}
	} else {
		$nameSelect = "r.FIRSTNAME, r.MIDDLENAME, r.SURNAME";
	}

	$resSql = "
		SELECT
			r.REF AS RES_REF,
			$nameSelect,
			d.START_DATE, d.END_DATE, d.ANNUAL_SALARY, d.FTE, d.PENSION,
			r.DEPARTMENT, r.CONTRACT_TYPE, d.EMP_KEY
		FROM $t_resources r
		LEFT JOIN $t_details d ON r.REF = d.EMP_KEY
		" . ($depSel !== 0 ? "WHERE r.DEPARTMENT = $depSel" : "") . "
	";
	$resources = [];
	foreach ($pdo->query($resSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$id = (int)$row['RES_REF'];

		if ($hasEnc) {
			if ($canView) {
				$tag = $row['NAME_TAG'] ?? null;
				$first  = safe_decrypt($row['FIRSTNAME_ENC']  ?? null, $row['FIRSTNAME_IV']  ?? null, $tag);
				$middle = safe_decrypt($row['MIDDLENAME_ENC'] ?? null, $row['MIDDLENAME_IV'] ?? null, $tag);
				$last   = safe_decrypt($row['SURNAME_ENC']    ?? null, $row['SURNAME_IV']    ?? null, $tag);
				if ($first === '') { // fallback if decrypt failed
					$first  = (string)($row['LEGACY_FIRSTNAME']  ?? '');
					$middle = (string)($row['LEGACY_MIDDLENAME'] ?? '');
					$last   = (string)($row['LEGACY_SURNAME']    ?? '');
				}
			} else {
				$first = 'Employee'; $middle = ''; $last = '#'.$id;
			}
		} else {
			$first  = (string)($row['FIRSTNAME']  ?? '');
			$middle = (string)($row['MIDDLENAME'] ?? '');
			$last   = (string)($row['SURNAME']    ?? '');
		}

		$resources[] = [
			'id' => $id,
			'first' => $first,
			'middle' => $middle,
			'last' => $last,
			'start' => $row['START_DATE'],
			'end' => $row['END_DATE'],
			'salary' => $row['ANNUAL_SALARY'],
			'fte' => $row['FTE'],
			'pension' => $row['PENSION'],
			'department' => $row['DEPARTMENT'],
			'contractType' => $row['CONTRACT_TYPE'],
		];
	}

	// ---------- ACTUALS ----------
	$actSql = ($depSel === 0)
		? "SELECT 
			a.EMP_KEY, 
			a.DATE, 
			p.PAYTYPE_GROUP_REF AS TYPE, 
			a.VALUE
		FROM $t_actuals a LEFT JOIN $t_paytype p ON a.TYPE = p.REF"
		: "SELECT 
			a.EMP_KEY, 
			a.DATE, 
			p.PAYTYPE_GROUP_REF AS TYPE, 
			a.VALUE
		FROM $t_actuals a
		LEFT JOIN $t_paytype p ON a.TYPE = p.REF
		LEFT JOIN $t_resources r ON a.EMP_KEY = r.REF
		WHERE r.DEPARTMENT = $depSel";

	$actuals = [];
	foreach ($pdo->query($actSql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$actuals[] = [
			'emp'  => (int)$r['EMP_KEY'],
			'date' => dateToMMM_YY($r['DATE']),
			'type' => $r['TYPE'], // was previously, incorrectly, designated as an int e.g. (int)$r['TYPE']
			'val'  => (float)$r['VALUE'],
		];
	}

	// ---------- ROLES ----------
	$roleSql = ($depSel === 0)
		? "SELECT * FROM $t_roles"
		: "SELECT * FROM $t_roles WHERE DEPARTMENT = $depSel";
	$roles = [];
	foreach ($pdo->query($roleSql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$roles[] = [
			'id' => (int)$r['REF'],
			'jobTitle' => $r['JOB_TITLE'],
			'department' => $r['DEPARTMENT'],
			'filledRef' => $r['FILLED_REFERENCE'],
			'status' => $r['STATUS'],
			'benchFte' => $r['BENCHMARK_FTE'],
			'benchSalary' => $r['BENCHMARK_SALARY'],
			'benchProrataSalary' => $r['BENCHMARK_PRORATA_SALARY'],
			'start' => $r['START_DATE'],
			'end'   => $r['END_DATE'],
			'contractType' => $r['CONTRACT_TYPE'],
			'pensionRate'  => 0.04,
		];
	}

	// ---------- DEPARTMENTS ----------
	$departments = [];
	foreach ($pdo->query("SELECT * FROM $t_departments")->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$departments[] = ['id' => (int)$r['REF'], 'name' => $r['DEPARTMENT']];
	}

	// ---------- USER OUTTURN ----------
	$outRows = $pdo->query("SELECT * FROM $t_outturn")->fetchAll(PDO::FETCH_ASSOC);
	$outturn = [];
	$typeLookup = $pdo->prepare("SELECT VALUE FROM $t_paygrp WHERE REF = :r LIMIT 1");
	foreach ($outRows as $o) {
		$typeLookup->execute([':r' => (int)$o['TYPE']]);
		$row = $typeLookup->fetch(PDO::FETCH_ASSOC);
		$outturn[] = [
			'emp' => (int)$o['EMP_KEY'],
			'res_rol' => $o['RES_ROL'],   // 'resource' | 'role'
			'date' => $o['DATE'],
			'type' => $row ? $row['VALUE'] : (string)((int)$o['TYPE']),
			'value'=> (float)$o['VALUE'],
		];
	}

	// ---------- FORECASTS (published) ----------
	$forecasts = [];
	$fcRows = $pdo->query("
		SELECT ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
		FROM $t_forecasts
		WHERE IS_PUBLISHED = 1
		GROUP BY ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
	")->fetchAll(PDO::FETCH_ASSOC);
	foreach ($fcRows as $r) {
		$forecasts[] = [
			'af'   => $r['ACTUAL_FORECAST'],
			'name' => $r['FORECAST_NAME'],
			'ver'  => $r['FORECAST_VERSION'],
		];
	}

	// ---------- NI bands ----------
	$ni = [];
	$niRows = $pdo->query("
		SELECT
		  DATE_FORMAT(FROM_DATE, '%Y-%m-%d') AS FROM_DATE,
		  DATE_FORMAT(TO_DATE,   '%Y-%m-%d') AS TO_DATE,
		  SECONDARY_THRESHOLD_MONTHLY, RATE
		FROM ni_employers_rates ORDER BY FROM_DATE ASC
	")->fetchAll(PDO::FETCH_ASSOC);
	foreach ($niRows as $r) {
		$ni[] = [
			'from' => $r['FROM_DATE'],
			'to'   => $r['TO_DATE'],
			'threshold' => (float)$r['SECONDARY_THRESHOLD_MONTHLY'],
			'rate' => $r['RATE'],
		];
	}

	echo json_encode([
		'ok' => true,
		'resources'   => $resources,
		'roles'       => $roles,
		'departments' => $departments,
		'actuals'     => $actuals,
		'outturn'     => $outturn,
		'forecasts'   => $forecasts,
		'ni'          => $ni,
	], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}