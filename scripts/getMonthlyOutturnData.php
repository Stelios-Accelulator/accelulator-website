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
	
	
	// OPEX/CAPEX/EXCEPTIONAL
	$today = (new DateTimeImmutable('now'))->format('Y-m-d');
	$tableSplit = "{$ref}_cost_split_rule";
	
	$costSplits = [
		'RESOURCE' => [],
		'ROLE' => []
	];
	
	// Pull the "current" rule per (SCOPE, SCOPE_REF) for today.
	// Uses max(EFFECTIVE_FROM) that is active on $today.
	$sqlSplits = "
			SELECT r.SCOPE, r.SCOPE_REF, r.OPEX_PCT, r.CAPEX_PCT, r.EXCEPT_PCT
			FROM `$tableSplit` r
			INNER JOIN (
					SELECT SCOPE, SCOPE_REF, MAX(EFFECTIVE_FROM) AS MAX_FROM
					FROM `$tableSplit`
					WHERE EFFECTIVE_FROM <= :today_from
						AND (EFFECTIVE_TO IS NULL OR EFFECTIVE_TO >= :today_to)
					GROUP BY SCOPE, SCOPE_REF
			) x
					ON x.SCOPE = r.SCOPE
			 AND x.SCOPE_REF = r.SCOPE_REF
			 AND x.MAX_FROM = r.EFFECTIVE_FROM
	";
	
	$stmt = $pdo->prepare($sqlSplits);
	$stmt->execute([
			':today_from' => $today,
			':today_to'   => $today,
	]);
	
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$scope = strtoupper((string)$row['SCOPE']);
		if ($scope !== 'RESOURCE' && $scope !== 'ROLE') continue;
	
		$scopeRef = (int)$row['SCOPE_REF'];
		$costSplits[$scope][(string)$scopeRef] = [
			'opex'        => (float)$row['OPEX_PCT'],
			'capex'       => (float)$row['CAPEX_PCT'],
			'exceptional' => (float)$row['EXCEPT_PCT'],
		];
	} // Pulls the Opex/Capex/Exceptional percentages for each resource & role
	
	
	
	// Params (accept 'department' and keep 'dep' for backwards-compat)
	$depSel = 0; // 0 = All (but for restricted users this means "all allowed")
	$raw = file_get_contents('php://input') ?: '';
	$body = $raw ? (json_decode($raw, true) ?: []) : [];
	
	$maybeDept = $body['department'] ?? $body['dep'] ?? 0;
	if (is_numeric($maybeDept)) {
		$depSel = (int)$maybeDept;
	}
	
	// SANITY CHECK: Outputs the department, user, and company
	$userRefForLog = is_array($user) ? (int)($user['REF'] ?? 0) : (int)$user;
	error_log("[getMonthlyOutturnData] depSel=$depSel userRef=$userRefForLog companyRef=$ref");
	
	// ---------- department restriction (server-side enforcement) ----------
	$userRef = (int)($_SESSION['userRef'] ?? 0);
	$accessLevel = (int)($_SESSION['userAccess'] ?? 0);
	
	// Dept restricted roles (as per Current Position decision)
	$deptRestrictedLevels = [5, 7, 8];
	$hasDeptRestriction = in_array($accessLevel, $deptRestrictedLevels, true);
	
	$allowedDeptRefs = [];
	if ($hasDeptRestriction) {
		$stmt = $pdo->prepare("
			SELECT DEPT_REF
			FROM user_departments
			WHERE COMPANY_ID = :c AND USERREF = :u
		");
		$stmt->execute([':c' => $ref, ':u' => $userRef]);
		$allowedDeptRefs = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
	
		// Exclude 0/unallocated defensively
		$allowedDeptRefs = array_values(array_filter($allowedDeptRefs, fn($d) => $d > 0));
	
		if (!$allowedDeptRefs) {
			http_response_code(403);
			echo json_encode(['ok' => false, 'error' => 'DEPT_ACCESS_NOT_CONFIGURED']);
			exit;
		}
	
		// If a specific dept was requested, it must be allowed
		if ($depSel > 0 && !in_array($depSel, $allowedDeptRefs, true)) {
			http_response_code(403);
			echo json_encode(['ok' => false, 'error' => 'DEPT_FORBIDDEN']);
			exit;
		}
	}

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
	
	function bind_in(array $vals, string $prefix = ':d'): array {
		$ph = [];
		$bind = [];
		foreach (array_values($vals) as $i => $v) {
			$key = $prefix . $i;
			$ph[] = $key;
			$bind[$key] = (int)$v;
		}
		return [$ph, $bind];
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
		// always fetch encrypted fields + IV/tag if present
		$nameSelect = "
			r.FIRSTNAME_ENC, r.MIDDLENAME_ENC, r.SURNAME_ENC,
			" . (isset($cols['FIRSTNAME_IV'])  ? "r.FIRSTNAME_IV"  : "NULL AS FIRSTNAME_IV") . ",
			" . (isset($cols['MIDDLENAME_IV']) ? "r.MIDDLENAME_IV" : "NULL AS MIDDLENAME_IV") . ",
			" . (isset($cols['SURNAME_IV'])    ? "r.SURNAME_IV"    : "NULL AS SURNAME_IV") . ",
			" . (isset($cols['NAME_TAG'])      ? "r.NAME_TAG"      : "NULL AS NAME_TAG") . "
		";
	
		// if legacy (plaintext) columns still exist, include them; otherwise alias NULL
		$hasPlainCols =
			isset($cols['FIRSTNAME']) ||
			isset($cols['MIDDLENAME']) ||
			isset($cols['SURNAME']);
	
		if ($hasPlainCols && $canView) {
			$nameSelect .= ",
				r.FIRSTNAME AS LEGACY_FIRSTNAME,
				r.MIDDLENAME AS LEGACY_MIDDLENAME,
				r.SURNAME AS LEGACY_SURNAME";
		} else {
			$nameSelect .= ",
				NULL AS LEGACY_FIRSTNAME,
				NULL AS LEGACY_MIDDLENAME,
				NULL AS LEGACY_SURNAME";
		}
	} else {
		// pure non-encrypted fallback (e.g. very early test data)
		$nameSelect = "
			r.FIRSTNAME,
			r.MIDDLENAME,
			r.SURNAME
		";
	}

	$resWhere = " WHERE 1=1 ";
	$resBind = [];
	
	// Apply restriction / filter
	if ($depSel > 0) {
		$resWhere .= " AND r.DEPARTMENT = :depSel ";
		$resBind[':depSel'] = $depSel;
	} elseif ($hasDeptRestriction) {
		[$ph, $bind] = bind_in($allowedDeptRefs, ':dep');
		$resWhere .= " AND r.DEPARTMENT IN (" . implode(',', $ph) . ") ";
		$resBind = array_merge($resBind, $bind);
		// do not leak unallocated for restricted users
		$resWhere .= " AND r.DEPARTMENT <> 0 ";
	}
	
	$resSql = "
		SELECT
			r.REF AS RES_REF,
			$nameSelect,
			d.START_DATE, d.END_DATE, d.ANNUAL_SALARY, d.FTE, d.PENSION,
			r.DEPARTMENT, r.CONTRACT_TYPE, d.EMP_KEY
		FROM $t_resources r
		LEFT JOIN $t_details d ON r.REF = d.EMP_KEY
		$resWhere
	";
	
	$stmt = $pdo->prepare($resSql);
	$stmt->execute($resBind);
	
	$resources = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
	$actBind = [];
	$actWhere = "";
	
	if ($depSel > 0) {
		$actWhere = " WHERE r.DEPARTMENT = :depSel ";
		$actBind[':depSel'] = $depSel;
	} elseif ($hasDeptRestriction) {
		[$ph, $bind] = bind_in($allowedDeptRefs, ':ad');
		$actWhere = " WHERE r.DEPARTMENT IN (" . implode(',', $ph) . ") AND r.DEPARTMENT <> 0 ";
		$actBind = array_merge($actBind, $bind);
	}
	
	$actSql = "
		SELECT
			a.EMP_KEY,
			a.DATE,
			p.PAYTYPE_GROUP_REF AS TYPE,
			a.VALUE
		FROM $t_actuals a
		LEFT JOIN $t_paytype p ON a.TYPE = p.REF
		LEFT JOIN $t_resources r ON a.EMP_KEY = r.REF
		$actWhere
	";
	
	$stmt = $pdo->prepare($actSql);
	$stmt->execute($actBind);
	
	$actuals = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$actuals[] = [
			'emp'  => (int)$r['EMP_KEY'],
			'date' => dateToMMM_YY($r['DATE']),
			'type' => $r['TYPE'],
			'val'  => (float)$r['VALUE'],
		];
	}

	// ---------- ROLES ----------
	$roleBind = [];
	$roleWhere = " WHERE 1=1 ";
	
	if ($depSel > 0) {
		$roleWhere .= " AND DEPARTMENT = :depSel ";
		$roleBind[':depSel'] = $depSel;
	} elseif ($hasDeptRestriction) {
		[$ph, $bind] = bind_in($allowedDeptRefs, ':rd');
		$roleWhere .= " AND DEPARTMENT IN (" . implode(',', $ph) . ") AND DEPARTMENT <> 0 ";
		$roleBind = array_merge($roleBind, $bind);
	}
	
	$roleSql = "SELECT * FROM $t_roles $roleWhere";
	
	$stmt = $pdo->prepare($roleSql);
	$stmt->execute($roleBind);
	
	$roles = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
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
	$deptBind = [];
	$deptWhere = "";
	
	if ($depSel > 0) {
		$deptWhere = " WHERE REF = :depSel ";
		$deptBind[':depSel'] = $depSel;
	} elseif ($hasDeptRestriction) {
		[$ph, $bind] = bind_in($allowedDeptRefs, ':dd');
		$deptWhere = " WHERE REF IN (" . implode(',', $ph) . ") ";
		$deptBind = array_merge($deptBind, $bind);
	}
	
	$deptSql = "SELECT REF, DEPARTMENT FROM $t_departments $deptWhere ORDER BY DEPARTMENT";
	
	$stmt = $pdo->prepare($deptSql);
	$stmt->execute($deptBind);
	
	$departments = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$departments[] = ['id' => (int)$r['REF'], 'name' => $r['DEPARTMENT']];
	}

	// ---------- USER OUTTURN ----------
	$outBind = [];
	$outWhere = " WHERE 1=1 ";
	
	if ($depSel > 0) {
		$outWhere .= " AND dept.DEPARTMENT = :depSel ";
		$outBind[':depSel'] = $depSel;
	} elseif ($hasDeptRestriction) {
		[$ph, $bind] = bind_in($allowedDeptRefs, ':od');
		$outWhere .= " AND dept.DEPARTMENT IN (" . implode(',', $ph) . ") AND dept.DEPARTMENT <> 0 ";
		$outBind = array_merge($outBind, $bind);
	}
	
	// join to resolve department for each outturn row (resource or role)
	$outSql = "
		SELECT o.*
			, CASE
				WHEN o.RES_ROL = 'resource' THEN r.DEPARTMENT
				ELSE ro.DEPARTMENT
				END AS DEPARTMENT
		FROM $t_outturn o
		LEFT JOIN $t_resources r ON o.RES_ROL = 'resource' AND o.EMP_KEY = r.REF
		LEFT JOIN $t_roles ro    ON o.RES_ROL = 'role'      AND o.EMP_KEY = ro.REF
		LEFT JOIN (
			SELECT REF, DEPARTMENT FROM $t_resources
			UNION ALL
			SELECT REF, DEPARTMENT FROM $t_roles
		) dept ON dept.REF = o.EMP_KEY
		$outWhere
	";
	
	$stmt = $pdo->prepare($outSql);
	$stmt->execute($outBind);
	$outRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
	$outturn = [];
	$typeLookup = $pdo->prepare("SELECT VALUE FROM $t_paygrp WHERE REF = :r LIMIT 1");
	
	foreach ($outRows as $o) {
		$typeLookup->execute([':r' => (int)$o['TYPE']]);
		$row = $typeLookup->fetch(PDO::FETCH_ASSOC);
	
		$outturn[] = [
			'emp' => (int)$o['EMP_KEY'],
			'res_rol' => $o['RES_ROL'],
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
		'costSplits'	=> $costSplits,
	], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}