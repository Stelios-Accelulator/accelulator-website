<?php
// accelulator.com/modules/currentPostion.php

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$inject = isset($_GET['inject']) && $_GET['inject'] == '1';
if($inject == 1){
	
}else{
	require_once("../includes/header.php");
	require_once("../includes/functions.php");
}

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

require_once __DIR__ . '/../includes/functions.php';

$user = checkUser();
$ref  = (int)getUsersCompanyId($user);

$userRef    = (int)($_SESSION['userRef'] ?? 0);
$userAccess = (int)($_SESSION['userAccess'] ?? 0);

// Dept-restricted roles:
$isDeptRestricted = in_array($userAccess, [5,7,8], true);

function get_allowed_departments(PDO $pdo, int $companyId, int $userRef): array {
	$stmt = $pdo->prepare("
		SELECT DEPT_REF
		FROM user_departments
		WHERE COMPANY_ID = :c AND USERREF = :u
		ORDER BY DEPT_REF
	");
	$stmt->execute([':c' => $companyId, ':u' => $userRef]);
	$rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

	$out = [];
	foreach ($rows as $d) {
		$d = (int)$d;
		if ($d > 0) $out[] = $d; // exclude 0/unallocated
	}
	return array_values(array_unique($out));
}

$allowedDeptRefs = $isDeptRestricted ? get_allowed_departments($pdo, $ref, $userRef) : [];
if ($isDeptRestricted && count($allowedDeptRefs) === 0) {
	http_response_code(403);
	echo "<div style='padding:16px;max-width:900px;margin:0 auto;'>
		<h2 style='margin:0 0 8px;'>Department access not configured</h2>
		<p style='margin:0;'>Please contact your administrator to assign your department access (Company Settings → Department Assignments).</p>
	</div>";
	exit;
}

// Global $pdo is initialised in functions.php (per your preference)
if (!isset($pdo) || !($pdo instanceof PDO)) {
	http_response_code(500);
	echo "<pre>PDO not available. Ensure functions.php initialises global \$pdo.</pre>";
	exit;
}

// -----------------------------
// helpers
// -----------------------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money0($n): string { return '£' . number_format((float)$n, 0); }

function first_of_month(DateTimeImmutable $d): DateTimeImmutable {
	return new DateTimeImmutable($d->format('Y-m-01 00:00:00'));
}
function end_of_last_complete_month(DateTimeImmutable $now): DateTimeImmutable {
	return first_of_month($now)->modify('-1 second'); // end of last day of prior month
}
function month_label(DateTimeImmutable $d): string { return $d->format('M-y'); }

function month_list(DateTimeImmutable $fromFirst, DateTimeImmutable $toFirst): array {
	if ($fromFirst > $toFirst) return [];
	$out = [];
	$cur = $fromFirst;
	while ($cur <= $toFirst) {
		$out[] = month_label($cur);
		$cur = $cur->modify('+1 month');
	}
	return $out;
}

function get_company_year_end_monthno(PDO $pdo, int $ref): int {
	$stmt = $pdo->prepare("SELECT MONTHNO FROM companyYearEnd WHERE COMPANY = :c LIMIT 1");
	$stmt->execute([':c' => $ref]);
	$m = (int)($stmt->fetchColumn() ?: 0);
	if ($m < 1 || $m > 12) throw new RuntimeException("Invalid companyYearEnd.MONTHNO for company {$ref}");
	return $m;
}

/**
 * FY starts month after year-end month and ends on last day of year-end month.
 * Example: YE=Nov (11) => FY Dec..Nov
 */
function get_fy_bounds(PDO $pdo, int $ref, DateTimeImmutable $now): array {
	$ye = get_company_year_end_monthno($pdo, $ref);
	$fyStartMonth = ($ye % 12) + 1;

	$yearNow  = (int)$now->format('Y');
	$monthNow = (int)$now->format('n');

	$fyStartYear = ($monthNow < $fyStartMonth) ? ($yearNow - 1) : $yearNow;
	$fyStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $fyStartYear, $fyStartMonth));

	$fyEndYear = ($fyStartMonth <= $ye) ? $fyStartYear : ($fyStartYear + 1);
	$fyEndFirst = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $fyEndYear, $ye));
	$fyEnd = $fyEndFirst->modify('last day of this month')->setTime(23,59,59);

	return [$fyStart, $fyEnd];
}

function get_latest_published_forecast_set(PDO $pdo, int $ref): ?array {
	$tF = "{$ref}_forecasts";
	$sql = "
		SELECT
			ACTUAL_FORECAST,
			FORECAST_NAME,
			FORECAST_VERSION,
			MAX(DATESTAMP) AS LAST_UPDATED
		FROM {$tF}
		WHERE IS_PUBLISHED = 1
			AND IS_ACTUAL = 0
		GROUP BY ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
		ORDER BY LAST_UPDATED DESC
		LIMIT 1
	";
	$row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
	return $row ?: null;
}

/**
 * Actuals YTD: uses DATE range and TYPE mapping (tolerant).
 * If your actuals TYPE values are guaranteed to be lower-case keys, we can tighten this later.
 */
function sum_actuals_people_cost(PDO $pdo, int $ref, DateTimeImmutable $from, DateTimeImmutable $to): float {
	 $tA = "{$ref}_actuals";
 
	 $groupIds = [1,2,3,4,5,6,7,8,9,10];
	 $ph = implode(',', array_fill(0, count($groupIds), '?'));
 
	 $tP = "{$ref}_paytype";
	 
	 $sql = "
		 SELECT COALESCE(SUM(a.VALUE),0) AS total
		 FROM {$tA} a
		 JOIN {$tP} p
			 ON p.REF = a.TYPE
		 WHERE a.DATE >= ?
			 AND a.DATE <= ?
			 AND p.PAYTYPE_GROUP_REF IN ($ph)
	 ";
 
	 $params = array_merge(
		 [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
		 $groupIds
	 );
 
	 $stmt = $pdo->prepare($sql);
	 $stmt->execute($params);
	 return (float)$stmt->fetchColumn();
 }

function sum_forecast_people_cost(PDO $pdo, int $ref, array $fs, array $months, array $payElements, bool $includeActualsInForecastTable): float {
	 if (!$months) return 0.0;
 
	 $tF = "{$ref}_forecasts";
	 $tR = "{$ref}_resources";
	 $tRole = "{$ref}_roles";
 
	 $mPH  = implode(',', array_fill(0, count($months), '?'));
	 $pePH = implode(',', array_fill(0, count($payElements), '?'));
 
	 // When including actuals: accept IS_ACTUAL=1 regardless of ACTUAL_FORECAST.
	 // When not including: just the normal forecast rows for that set.
	 $actualClause = $includeActualsInForecastTable
		 ? "AND (f.IS_ACTUAL = 1 OR (f.IS_ACTUAL = 0 AND f.ACTUAL_FORECAST = ?))"
		 : "AND f.IS_ACTUAL = 0 AND f.ACTUAL_FORECAST = ?";
 
	 $sql = "
		 SELECT COALESCE(SUM(v),0) AS total
		 FROM (
			 -- resources
			 SELECT SUM(f.VALUE) AS v
			 FROM {$tF} f
			 JOIN {$tR} r ON r.REF = f.ROLE_REFERENCE
			 WHERE f.IS_PUBLISHED = 1
				 {$actualClause}
				 AND f.FORECAST_NAME = ?
				 AND f.FORECAST_VERSION = ?
				 AND f.TYPE = 'resource'
				 AND f.MONTH IN ($mPH)
				 AND f.PAY_ELEMENT IN ($pePH)
 
			 UNION ALL
 
			 -- roles (unallocated only)
			 SELECT SUM(f.VALUE) AS v
			 FROM {$tF} f
			 LEFT JOIN {$tRole} ro
				 ON ro.REF = f.ROLE_REFERENCE
				AND (ro.FILLED_REFERENCE IS NULL OR ro.FILLED_REFERENCE = 0)
			 WHERE f.IS_PUBLISHED = 1
				 {$actualClause}
				 AND f.FORECAST_NAME = ?
				 AND f.FORECAST_VERSION = ?
				 AND f.TYPE = 'role'
				 AND ro.REF IS NOT NULL
				 AND f.MONTH IN ($mPH)
				 AND f.PAY_ELEMENT IN ($pePH)
		 ) s
	 ";
 
	 // Params:
	 // If includeActualsInForecastTable is true, ACTUAL_FORECAST appears once per UNION leg.
	 // If false, same.
	 $params = [];
 
	 // resources leg
	 $params[] = $fs['ACTUAL_FORECAST'];
	 $params[] = $fs['FORECAST_NAME'];
	 $params[] = (int)$fs['FORECAST_VERSION'];
	 $params = array_merge($params, $months, $payElements);
 
	 // roles leg
	 $params[] = $fs['ACTUAL_FORECAST'];
	 $params[] = $fs['FORECAST_NAME'];
	 $params[] = (int)$fs['FORECAST_VERSION'];
	 $params = array_merge($params, $months, $payElements);
 
	 $stmt = $pdo->prepare($sql);
	 $stmt->execute($params);
	 return (float)$stmt->fetchColumn();
 }

function dept_actuals_ytd(PDO $pdo, int $ref, DateTimeImmutable $from, DateTimeImmutable $to): array {
	 $tA = "{$ref}_actuals";
	 $tP = "{$ref}_paytype";
	 $tR = "{$ref}_resources";
	 $tD = "{$ref}_departments";
 
	 $groupIds = [1,2,3,4,5,6,7,8,9,10];
	 $ph = implode(',', array_fill(0, count($groupIds), '?'));
 
	 $sql = "
		 SELECT
			 r.DEPARTMENT AS dept_ref,
			 d.DEPARTMENT AS dept_name,
			 COALESCE(SUM(a.VALUE),0) AS total
		 FROM {$tA} a
		 JOIN {$tP} p ON p.REF = a.TYPE
		 JOIN {$tR} r ON r.REF = a.EMP_KEY
		 LEFT JOIN {$tD} d ON d.REF = r.DEPARTMENT
		 WHERE a.DATE >= ?
			 AND a.DATE <= ?
			 AND p.PAYTYPE_GROUP_REF IN ($ph)
		 GROUP BY r.DEPARTMENT, d.DEPARTMENT
	 ";
 
	 $params = array_merge(
		 [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
		 $groupIds
	 );
 
	 $stmt = $pdo->prepare($sql);
	 $stmt->execute($params);
 
	 $out = [];
	 foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
		 $k = (int)$r['dept_ref'];
		 $out[$k] = [
			 'dept_ref'  => $k,
			 'dept_name' => $r['dept_name'] ?: 'Unallocated',
			 'total'     => (float)$r['total'],
		 ];
	 }
	 return $out;
 }

function dept_forecast(PDO $pdo, int $ref, array $fs, array $months, array $payElements, bool $includeActualsInForecastTable): array {
	if (!$months) return [];

	$tF = "{$ref}_forecasts";
	$tR = "{$ref}_resources";
	$tRole = "{$ref}_roles";
	$tD = "{$ref}_departments";

	$mPH  = implode(',', array_fill(0, count($months), '?'));
	$pePH = implode(',', array_fill(0, count($payElements), '?'));

	// Match the same semantics as the top-line forecast:
	// - include actual rows already loaded into forecasts (IS_ACTUAL=1)
	// - plus forecast rows for this forecast set (IS_ACTUAL=0 AND ACTUAL_FORECAST matches)
	$actualClause = $includeActualsInForecastTable
		? "AND (f.IS_ACTUAL = 1 OR (f.IS_ACTUAL = 0 AND f.ACTUAL_FORECAST = ?))"
		: "AND f.IS_ACTUAL = 0 AND f.ACTUAL_FORECAST = ?";

	$sql = "
		SELECT dept_ref, d.DEPARTMENT AS dept_name, COALESCE(SUM(total),0) AS total
		FROM (
			-- resources
			SELECT r.DEPARTMENT AS dept_ref, SUM(f.VALUE) AS total
			FROM {$tF} f
			JOIN {$tR} r ON r.REF = f.ROLE_REFERENCE
			WHERE f.IS_PUBLISHED = 1
				{$actualClause}
				AND f.FORECAST_NAME = ?
				AND f.FORECAST_VERSION = ?
				AND f.TYPE = 'resource'
				AND f.MONTH IN ($mPH)
				AND f.PAY_ELEMENT IN ($pePH)
			GROUP BY r.DEPARTMENT

			UNION ALL

			-- roles (unallocated only)
			SELECT ro.DEPARTMENT AS dept_ref, SUM(f.VALUE) AS total
			FROM {$tF} f
			LEFT JOIN {$tRole} ro
				ON ro.REF = f.ROLE_REFERENCE
			 AND (ro.FILLED_REFERENCE IS NULL OR ro.FILLED_REFERENCE = 0)
			WHERE f.IS_PUBLISHED = 1
				{$actualClause}
				AND f.FORECAST_NAME = ?
				AND f.FORECAST_VERSION = ?
				AND f.TYPE = 'role'
				AND ro.REF IS NOT NULL
				AND f.MONTH IN ($mPH)
				AND f.PAY_ELEMENT IN ($pePH)
			GROUP BY ro.DEPARTMENT
		) x
		LEFT JOIN {$tD} d ON d.REF = x.dept_ref
		GROUP BY dept_ref, d.DEPARTMENT
	";

	$params = [];

	// resources leg params
	$params[] = $fs['ACTUAL_FORECAST'];
	$params[] = $fs['FORECAST_NAME'];
	$params[] = (int)$fs['FORECAST_VERSION'];
	$params = array_merge($params, $months, $payElements);

	// roles leg params
	$params[] = $fs['ACTUAL_FORECAST'];
	$params[] = $fs['FORECAST_NAME'];
	$params[] = (int)$fs['FORECAST_VERSION'];
	$params = array_merge($params, $months, $payElements);

	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);

	$out = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$k = (int)$r['dept_ref'];
		$out[$k] = [
			'dept_ref'  => $k,
			'dept_name' => $r['dept_name'] ?: 'Unallocated',
			'total'     => (float)$r['total'],
		];
	}
	return $out;
}

function preload_cost_splits(PDO $pdo, int $ref, DateTimeImmutable $from, DateTimeImmutable $to): array {
	$tUsed = "{$ref}_cost_split_used";
	$tRule = "{$ref}_cost_split_rule";

	$fromMs = $from->format('Y-m-01');
	$toMsEx = (first_of_month($to))->modify('+1 month')->format('Y-m-01');

	$splitUsed = ['RESOURCE'=>[], 'ROLE'=>[]];

	$stmt = $pdo->prepare("
		SELECT SCOPE, SCOPE_REF, MONTH_START,
					 OPEX_PCT_USED, CAPEX_PCT_USED, EXCEPT_PCT_USED
		FROM {$tUsed}
		WHERE MONTH_START >= :fromMs AND MONTH_START < :toMs
	");
	$stmt->execute([':fromMs'=>$fromMs, ':toMs'=>$toMsEx]);
	while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$scope = strtoupper((string)$r['SCOPE']);
		if (!isset($splitUsed[$scope])) continue;
		$sr = (int)$r['SCOPE_REF'];
		$ms = substr((string)$r['MONTH_START'], 0, 10);

		$splitUsed[$scope][$sr][$ms] = [(float)$r['OPEX_PCT_USED'], (float)$r['CAPEX_PCT_USED'], (float)$r['EXCEPT_PCT_USED']];
	}

	$splitRules = ['RESOURCE'=>[], 'ROLE'=>[]];

	$stmt = $pdo->prepare("
		SELECT SCOPE, SCOPE_REF, EFFECTIVE_FROM, EFFECTIVE_TO,
					 OPEX_PCT, CAPEX_PCT, EXCEPT_PCT
		FROM {$tRule}
		WHERE EFFECTIVE_FROM <= :toDate
		ORDER BY SCOPE, SCOPE_REF, EFFECTIVE_FROM DESC
	");
	$stmt->execute([':toDate' => $to->format('Y-m-d')]);
	while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$scope = strtoupper((string)$r['SCOPE']);
		if (!isset($splitRules[$scope])) continue;
		$sr = (int)$r['SCOPE_REF'];

		$splitRules[$scope][$sr][] = [
			'from'   => substr((string)$r['EFFECTIVE_FROM'], 0, 10),
			'to'     => $r['EFFECTIVE_TO'] ? substr((string)$r['EFFECTIVE_TO'], 0, 10) : null,
			'opex'   => (float)$r['OPEX_PCT'],
			'capex'  => (float)$r['CAPEX_PCT'],
			'except' => (float)$r['EXCEPT_PCT'],
		];
	}

	$getSplit = function(string $scope, int $scopeRef, string $monthStart) use (&$splitUsed, &$splitRules): array {
		$scope = strtoupper($scope);

		// 1) Snapshot wins
		if (isset($splitUsed[$scope][$scopeRef][$monthStart])) {
			return $splitUsed[$scope][$scopeRef][$monthStart];
		}

		// 2) Rule fallback
		if (!empty($splitRules[$scope][$scopeRef])) {
			foreach ($splitRules[$scope][$scopeRef] as $ru) {
				if ($monthStart < $ru['from']) continue;
				if ($ru['to'] !== null && $monthStart > $ru['to']) continue;
				return [(float)$ru['opex'], (float)$ru['capex'], (float)$ru['except']];
			}
		}

		// 3) Default
		return [100.0, 0.0, 0.0];
	};

	return [$getSplit];
}

function normalise_split(array $split): array {
	$o = (float)($split[0] ?? 0);
	$c = (float)($split[1] ?? 0);
	$e = (float)($split[2] ?? 0);

	// If stored as fractions (1,0,0) etc, convert to percentages
	$max = max($o, $c, $e);
	if ($max > 0 && $max <= 1.0) {
		$o *= 100; $c *= 100; $e *= 100;
	}

	$sum = $o + $c + $e;

	// If nothing set, default to 100% opex
	if ($sum <= 0.00001) return [100.0, 0.0, 0.0];

	// If nonsense (e.g. 100/100/100), pick a sane winner
	// Rule: take the largest bucket; if tied, default to opex.
	if ($sum > 100.00001) {
		if ($o >= $c && $o >= $e) return [100.0, 0.0, 0.0];
		if ($c >= $o && $c >= $e) return [0.0, 100.0, 0.0];
		return [0.0, 0.0, 100.0];
	}

	// If slightly off 100, scale to 100
	if (abs($sum - 100.0) > 0.00001) {
		$scale = 100.0 / $sum;
		$o *= $scale; $c *= $scale; $e *= $scale;
	}

	return [$o, $c, $e];
}

function apply_split(float $amount, array $split): array {
	[$o,$c,$e] = normalise_split($split);

	return [
		'total'  => $amount,
		'opex'   => $amount * $o / 100.0,
		'capex'  => $amount * $c / 100.0,
		'except' => $amount * $e / 100.0,
	];
}

function blank_split(): array {
	return ['total'=>0.0,'opex'=>0.0,'capex'=>0.0,'except'=>0.0];
}

// -----------------------------
// main
// -----------------------------
try {
	$now = new DateTimeImmutable('now');

	$fs = get_latest_published_forecast_set($pdo, $ref);
	if (!$fs) {
		echo "<div style='padding:16px;'>No published forecast found.</div>";
		exit;
	}

	// Forecast pay elements (your canonical list)
	$payElements = [
		'base','overtime','onCall','bonus','other','welfare','pension','statutoryPay','employersNI','commission'
	];

	[$fyStart, $fyEnd] = get_fy_bounds($pdo, $ref, $now);

	$actualsEnd = end_of_last_complete_month($now);
	if ($actualsEnd < $fyStart) $actualsEnd = $now;

	$fyMonths = month_list(first_of_month($fyStart), first_of_month($fyEnd));

	$futureStart = first_of_month($actualsEnd)->modify('+1 month');
	$futureMonths = month_list($futureStart, first_of_month($fyEnd));

	// Preload split resolver for FY window (YTD + future)
	[$getSplit] = preload_cost_splits($pdo, $ref, $fyStart, $fyEnd);
	
	// -----------------------------
	// ACTUALS YTD (split aware)
	// -----------------------------
	$actualsSplit = blank_split();
	$deptActualsSplit = [];
	
	$tA = "{$ref}_actuals";
	$tP = "{$ref}_paytype";
	$tR = "{$ref}_resources";
	
	$groupIds = [1,2,3,4,5,6,7,8,9,10];
	$ph = implode(',', array_fill(0, count($groupIds), '?'));
	
	$deptClause = "";
	$deptParams = [];
	if ($isDeptRestricted) {
		$dph = implode(',', array_fill(0, count($allowedDeptRefs), '?'));
		$deptClause = " AND r.DEPARTMENT IN ($dph) ";
		$deptParams = $allowedDeptRefs;
	} else {
		// Even unrestricted users should not see unallocated in this view (per your decision)
		$deptClause = " AND r.DEPARTMENT IS NOT NULL AND r.DEPARTMENT <> 0 ";
	}
	
	$sql = "
		SELECT a.EMP_KEY, r.DEPARTMENT AS dept_ref, a.DATE, a.VALUE
		FROM {$tA} a
		JOIN {$tP} p ON p.REF = a.TYPE
		JOIN {$tR} r ON r.REF = a.EMP_KEY
		WHERE a.DATE >= ?
			AND a.DATE <= ?
			AND p.PAYTYPE_GROUP_REF IN ($ph)
			{$deptClause}
	";
	
	$params = array_merge(
		[$fyStart->format('Y-m-d H:i:s'), $actualsEnd->format('Y-m-d H:i:s')],
		$groupIds,
		$deptParams
	);
	
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$emp = (int)$row['EMP_KEY'];
		$dept = (int)$row['dept_ref'];
		$ms = (new DateTimeImmutable($row['DATE']))->format('Y-m-01');
		$val = (float)$row['VALUE'];
	
		$split = $getSplit('RESOURCE', $emp, $ms);
		$parts = apply_split($val, $split);
	
		foreach (['total','opex','capex','except'] as $k) {
			$actualsSplit[$k] += $parts[$k];
		}
	
		if (!isset($deptActualsSplit[$dept])) $deptActualsSplit[$dept] = blank_split();
		foreach (['total','opex','capex','except'] as $k) {
			$deptActualsSplit[$dept][$k] += $parts[$k];
		}
	}
	
	$actualsYTD = $actualsSplit['total']; // keep your existing variable names
	
	// -----------------------------
	// FORECAST FY + FUTURE (split aware)
	// -----------------------------
	function sum_forecast_split(
		PDO $pdo,
		int $ref,
		array $fs,
		array $months,
		array $payElements,
		bool $includeActualsInForecastTable,
		callable $getSplit,
		bool $byDept = false,
		bool $applyCostSplit = true,
		bool $isDeptRestricted = false,
		array $allowedDeptRefs = []
	): array {
		
		if (!$months) return $byDept ? [] : blank_split();
	
		$tF = "{$ref}_forecasts";
		$tR = "{$ref}_resources";
		$tRole = "{$ref}_roles";
	
		$mPH  = implode(',', array_fill(0, count($months), '?'));
		$pePH = implode(',', array_fill(0, count($payElements), '?'));
	
		$actualClause = $includeActualsInForecastTable
			? "AND (f.IS_ACTUAL = 1 OR (f.IS_ACTUAL = 0 AND f.ACTUAL_FORECAST = ?))"
			: "AND f.IS_ACTUAL = 0 AND f.ACTUAL_FORECAST = ?";
	
		// Pull raw rows so we can apply month-based split rules in PHP
		$deptFilterSql = "";
		$deptFilterParams = [];
		
		if ($isDeptRestricted) {
			$dph = implode(',', array_fill(0, count($allowedDeptRefs), '?'));
		
			// single IN() list (placeholders appear ONCE)
			$deptFilterSql = "
				AND (
					CASE
						WHEN f.TYPE='resource' THEN r.DEPARTMENT
						ELSE ro.DEPARTMENT
					END
				) IN ($dph)
			";
		
			$deptFilterParams = $allowedDeptRefs;
		} else {
			$deptFilterSql = "
				AND (
					CASE
						WHEN f.TYPE='resource' THEN r.DEPARTMENT
						ELSE ro.DEPARTMENT
					END
				) IS NOT NULL
				AND (
					CASE
						WHEN f.TYPE='resource' THEN r.DEPARTMENT
						ELSE ro.DEPARTMENT
					END
				) <> 0
			";
		}
		
		$sql = "
			SELECT
				f.TYPE AS f_type,
				f.ROLE_REFERENCE AS ref_id,
				f.MONTH AS mon,
				f.VALUE AS v,
				CASE
					WHEN f.TYPE='resource' THEN r.DEPARTMENT
					ELSE ro.DEPARTMENT
				END AS dept_ref
			FROM {$tF} f
			LEFT JOIN {$tR} r ON r.REF = f.ROLE_REFERENCE AND f.TYPE='resource'
			LEFT JOIN {$tRole} ro ON ro.REF = f.ROLE_REFERENCE AND f.TYPE='role'
			WHERE f.IS_PUBLISHED = 1
				{$actualClause}
				AND f.FORECAST_NAME = ?
				AND f.FORECAST_VERSION = ?
				AND f.MONTH IN ($mPH)
				AND f.PAY_ELEMENT IN ($pePH)
				AND (
					(f.TYPE='resource' AND r.REF IS NOT NULL)
					OR
					(f.TYPE='role' AND ro.REF IS NOT NULL AND (ro.FILLED_REFERENCE IS NULL OR ro.FILLED_REFERENCE=0))
				)
				{$deptFilterSql}
		";
	
		$params = [];
		$params[] = $fs['ACTUAL_FORECAST'];
		$params[] = $fs['FORECAST_NAME'];
		$params[] = (int)$fs['FORECAST_VERSION'];
		$params = array_merge($params, $months, $payElements, $deptFilterParams);
	
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
	
		$total = blank_split();
		$dept = [];
	
		while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$type = (string)$r['f_type'];
			$refId = (int)$r['ref_id'];
			$deptRef = (int)$r['dept_ref'];
			$val = (float)$r['v'];
	
			$dt = DateTimeImmutable::createFromFormat('M-y', (string)$r['mon']);
			if (!$dt) continue;
			$ms = $dt->format('Y-m-01');
	
			$scope = ($type === 'role') ? 'ROLE' : 'RESOURCE';
			
			if ($applyCostSplit) {
				$split = $getSplit($scope, $refId, $ms);
			} else {
				$split = [100.0, 0.0, 0.0]; // Forecast is 100% opex for now
			}
			
			$parts = apply_split($val, $split);
	
			foreach (['total','opex','capex','except'] as $k) {
				$total[$k] += $parts[$k];
			}
	
			if ($byDept) {
				if (!isset($dept[$deptRef])) $dept[$deptRef] = blank_split();
				foreach (['total','opex','capex','except'] as $k) {
					$dept[$deptRef][$k] += $parts[$k];
				}
			}
		}
	
		return $byDept ? $dept : $total;
	}
	
	// Include IS_ACTUAL rows so Dec-25 is included if it lives in forecasts as actualised
	$forecastFYSplit = sum_forecast_split($pdo, $ref, $fs, $fyMonths, $payElements, true,  $getSplit, false, false, $isDeptRestricted, $allowedDeptRefs);
	
	// This “future forecast” figure is no longer used for projection (projection uses Outturn), but keep it if you want it later
	$forecastFutureSplit = sum_forecast_split($pdo, $ref, $fs, $futureMonths, $payElements, false, $getSplit, false, false, $isDeptRestricted, $allowedDeptRefs);
	
	$forecastFY     = (float)$forecastFYSplit['total'];
	$forecastFuture = (float)$forecastFutureSplit['total'];
	
	// Dept forecast breakdown should match forecast semantics (no actual rows, 100% opex for now)
	$deptFcFYSplit  = sum_forecast_split($pdo, $ref, $fs, $fyMonths, $payElements, true,  $getSplit, true,  false, $isDeptRestricted, $allowedDeptRefs);
	$deptFcFutSplit = sum_forecast_split($pdo, $ref, $fs, $futureMonths, $payElements, false, $getSplit, true,  false, $isDeptRestricted, $allowedDeptRefs);
	
	// Keep your existing projection/variance for TOTAL as the default view
	$projectionFY = $actualsYTD; // JS will add Outturn once getFutureOutturn loads
	$variance     = $projectionFY - $forecastFY;

	// Department names map
	$deptNames = [];
	try {
		$tD = "{$ref}_departments";
		$stmt = $pdo->query("SELECT REF, DEPARTMENT FROM {$tD}");
		while ($rD = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$deptNames[(int)$rD['REF']] = $rD['DEPARTMENT'] ?: 'Unallocated';
		}
	} catch (Throwable $e) {
		// safe fallback
	}
	
	// UI scope indicator (only for dept-restricted roles)
	$scopeBadgeHtml = '';
	if ($isDeptRestricted) {
		$names = [];
		foreach ($allowedDeptRefs as $dref) {
			$names[] = $deptNames[(int)$dref] ?? ('Dept ' . (int)$dref);
		}
	
		$namesText = implode(', ', $names);
		$countText = count($names);
	
		$scopeBadgeHtml = "
			<div class='cp-scope' title='".h($namesText)."'>
				<span class='badge'>
					<span class='dot'></span>
					<span>Filtered</span>
					<span class='muted'>({$countText})</span>
				</span>
				<span class='list'>".h($namesText)."</span>
			</div>
		";
	}
	
	// Keys must come from the split-aware arrays we actually have
	$deptKeys = array_unique(array_merge(
		array_keys($deptActualsSplit),
		array_keys($deptFcFYSplit),
		array_keys($deptFcFutSplit)
	));
	
	$deptRows = [];
	
	foreach ($deptKeys as $k) {
		$k = (int)$k;
	
		$actS  = $deptActualsSplit[$k] ?? blank_split();
		$fcFYS = $deptFcFYSplit[$k] ?? blank_split();
		$fcFuS = $deptFcFutSplit[$k] ?? blank_split();
	
		$act  = (float)$actS['total'];
		$fcFY = (float)$fcFYS['total'];
		$fcFu = (float)$fcFuS['total'];
	
		$proj = $act + $fcFu;
		$var  = $proj - $fcFY;
	
		$name = $deptNames[$k] ?? ($k === 0 ? 'Unallocated' : 'Dept ' . $k);
	
		$deptRows[] = [
			'dept_ref'     => $k,
			'dept_name'    => $name,
			'actuals_ytd'  => $act,
			'forecast_fy'  => $fcFY,
			'projection_fy'=> $proj,
			'variance'     => $var,
	
			// store split arrays so the HTML loop can output the correct data-* per row
			'actS'         => $actS,
			'fcS'          => $fcFYS,
		];
	}

	usort($deptRows, fn($a,$b) => ($b['variance'] <=> $a['variance']));

	$absVar = abs($variance);
	$matOk = max(100.0, $forecastFY * 0.001);      // 0.1%
	$matAction = max(1000.0, $forecastFY * 0.005); // 0.5%
	
	if ($absVar <= $matOk) {
		$stateEmoji = '✅';
		$stateText  = 'All clear';
	} elseif ($absVar <= $matAction) {
		$stateEmoji = '⚠️';
		$stateText  = 'Watch';
	} else {
		$stateEmoji = '❌';
		$stateText  = 'Action needed';
	}

	$forecastLabel = sprintf(
		"%s %s v%s",
		$fs['ACTUAL_FORECAST'],
		$fs['FORECAST_NAME'],
		(int)$fs['FORECAST_VERSION']
	);

	$fyLabel  = $fyStart->format('d M Y') . " → " . $fyEnd->format('d M Y');
	$ytdLabel = $fyStart->format('d M Y') . " → " . $actualsEnd->format('d M Y');

	$futLabel = $futureMonths
		? (reset($futureMonths) . " → " . end($futureMonths))
		: "—";

} catch (Throwable $e) {
	http_response_code(500);
	echo "<pre style='white-space:pre-wrap;padding:12px;background:#fee;border:1px solid #f99;color:#900;'>".
		"currentPostion fatal:\n".h($e->getMessage()).
	"</pre>";
	exit;
}
?>

<style>
	.cp-wrap{ padding:16px; max-width:980px; margin:0 auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; }
	.cp-line{ height:1px; background:rgba(0,0,0,.12); margin:12px 0; }
	.cp-title{ font-size:14px; letter-spacing:.12em; opacity:.7; margin-bottom:6px; }
	.cp-forecast{ font-size:18px; font-weight:800; margin-bottom:4px; }
	.cp-sub{ font-size:13px; opacity:.75; margin-bottom:14px; }
	.cp-verdict .main{ font-size:22px; font-weight:900; }
	.cp-verdict .mini{ font-size:13px; opacity:.75; margin-top:4px; }
	.cp-grid{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px; }
	.cp-card{ border:1px solid rgba(0,0,0,.12); border-radius:10px; padding:12px; }
	.cp-card .label{ font-size:12px; opacity:.7; margin-bottom:6px; }
	.cp-card .value{ font-size:18px; font-weight:900; }
	.cp-section{ font-size:13px; font-weight:900; margin:14px 0 8px; }
	.cp-row{ width:100%; display:flex; justify-content:space-between; align-items:center; gap:12px;
		padding:10px 0; border-top:1px solid rgba(0,0,0,.08);
		background:transparent; border-left:0; border-right:0; border-bottom:0;
		text-align:left; cursor:pointer;
	}
	.cp-row:first-of-type{ border-top:0; }
	.cp-row .lhs{ display:flex; flex-direction:column; }
	.cp-row .name{ font-size:13px; font-weight:800; }
	.cp-row .meta{ font-size:12px; opacity:.7; }
	.cp-row .rhs{ font-variant-numeric: tabular-nums; font-weight:900; }
	.cp-pill{ display:inline-block; font-size:12px; padding:2px 8px; border-radius:999px; border:1px solid rgba(0,0,0,.15); opacity:.9; }
	.cp-bad{ border-color: rgba(200,0,0,.35); }
	.cp-good{ border-color: rgba(0,140,0,.35); }
	.cp-warn{ border-color: rgba(204, 138, 0, .55); }
	.cp-hidden{ display:none; }
	.cp-note{ font-size:12px; opacity:.7; margin-top:10px; }
	
	/* INTRODUCED BECAUSE GLOBAL CSS WAS REMOVING */
	
	/* Defensive reset: your global site CSS is likely styling buttons/spans */
	.cp-row{
		-webkit-appearance: none;
		appearance: none;
		background: transparent !important;
		color: #111 !important;
		border: 0;
		border-top: 1px solid rgba(0,0,0,.08);
		padding: 10px 0;
		width: 100%;
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
		text-align: left;
		cursor: pointer;
	}
	
	.cp-row *{ color: inherit !important; } /* forces spans inside to match */
	
	.cp-row:first-of-type{ border-top:0; }
	
	.cp-row:hover{ background: rgba(0,0,0,.03) !important; }
	.cp-row:focus{ outline: 2px solid rgba(0,0,0,.25); outline-offset: 2px; }
	
	.cp-row .name{ font-size:13px; font-weight:800; }
	.cp-row .meta{ font-size:12px; color: rgba(0,0,0,.7) !important; }
	.cp-row .rhs{ font-variant-numeric: tabular-nums; font-weight:900; }
	
	.cp-pill-toggle {
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
		margin-top: 10px;
	}
	
	/* Base pill */
	.cp-dim-pill {
		border: 0;
		border-radius: 999px;          /* very oval */
		padding: 7px 14px;
		font-size: 12px;
		font-weight: 800;
		letter-spacing: .02em;
		cursor: pointer;
		line-height: 1;
		transition:
			transform .05s ease,
			opacity .15s ease,
			filter .15s ease;
		color: #fff;
	}
	
	/* Status colours */
	.cp-dim-pill.is-ok {
		background: #07A4BC;
	}
	
	.cp-dim-pill.is-bad {
		background: rgb(166, 42, 23);
	}
	
	/* Non-selected = muted but visible */
	.cp-dim-pill:not(.is-active) {
		opacity: 0.35;                 /* faint but readable */
		filter: saturate(85%);
	}
	
	/* Selected = strong */
	.cp-dim-pill.is-active {
		opacity: 1;
		filter: none;
	}
	
	/* Interaction polish */
	.cp-dim-pill:hover {
		opacity: 0.75;
	}
	
	.cp-dim-pill.is-active:hover {
		opacity: 1;
	}
	
	.cp-dim-pill:active {
		transform: scale(0.97);
	}
	
	.cp-dim-pill.is-warn {
		background: rgb(204, 138, 0); /* amber */
	}
	
	.cp-scope {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		margin-top: 6px;
		font-size: 12px;
		opacity: .75;
	}
	
	.cp-scope .badge {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 4px 10px;
		border-radius: 999px;
		border: 1px solid rgba(0,0,0,.12);
		background: rgba(0,0,0,.03);
		font-weight: 800;
		letter-spacing: .01em;
	}
	
	.cp-scope .badge .dot {
		width: 7px;
		height: 7px;
		border-radius: 999px;
		background: #07A4BC; /* your teal */
		opacity: .9;
	}
	
	.cp-scope .list {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		max-width: 620px; /* keeps it subtle, avoids wrapping */
	}
	
	.cp-scope .muted {
		opacity: .7;
	}
	
</style>

<div class="cp-wrap"
	data-act-total="<?= (float)$actualsSplit['total'] ?>"
	data-act-opex="<?= (float)$actualsSplit['opex'] ?>"
	data-act-capex="<?= (float)$actualsSplit['capex'] ?>"
	data-act-except="<?= (float)$actualsSplit['except'] ?>"
	
	data-fc-total="<?= (float)$forecastFYSplit['total'] ?>"
	data-fc-opex="<?= (float)$forecastFYSplit['opex'] ?>"
	data-fc-capex="<?= (float)$forecastFYSplit['capex'] ?>"
	data-fc-except="<?= (float)$forecastFYSplit['except'] ?>"
>


	<div class="cp-title">CURRENT POSITION</div>
	<div class="cp-forecast"><?=h($forecastLabel)?></div>
	<?=$scopeBadgeHtml?>
	<div class="cp-sub">Last updated: <?=h($fs['LAST_UPDATED'])?> · FY: <?=h($fyLabel)?></div>
	
	<div class="cp-pill-toggle" id="cpDimToggle">
		<button type="button" class="cp-dim-pill is-active" data-dim="total">Total</button>
		<button type="button" class="cp-dim-pill" data-dim="opex">Opex</button>
		<button type="button" class="cp-dim-pill" data-dim="capex">Capex</button>
		<button type="button" class="cp-dim-pill" data-dim="except">Exceptional</button>
	</div>
	
	<div class="cp-line"></div>

	<div class="cp-verdict">
		<div class="main" id="cpVerdictLine"
			 data-matok="<?= (float)$matOk ?>"
			 data-mataction="<?= (float)$matAction ?>"
		>
			<?=$stateEmoji?>
			<?=h($stateText)?> (<?=money0($absVar)?> <?= ($variance < 0 ? "under" : "over") ?>)
		</div>
		<div class="mini" id="cpVerdictMini">
			YTD actuals: <?=h($ytdLabel)?> · Future: <?=h($futLabel)?> · Thresholds: <?=money0($matOk)?> / <?=money0($matAction)?>
		</div>
	</div>

	<div class="cp-grid">
		<div class="cp-card">
			<div class="label">Forecast (full FY)</div>
			<div class="value" id="cpForecastValue"><?=money0($forecastFY)?></div>
		</div>
		<div class="cp-card">
			<div class="label">Projection (full FY)</div>
			<div class="value" id="cpProjectionValue"><?=money0($projectionFY)?></div>
		</div>
	</div>

	<div style="display:flex; gap:10px; align-items:center; margin:10px 0 2px;">
		<button
			type="button"
			id="exportCurrentPositionBtn"
			data-export-url="/scripts/exportCurrentPosition.php"
			style="display:inline-block; padding:8px 12px; border-radius:10px; border:1px solid rgba(0,0,0,.15);
			font-size:12px; font-weight:800; color:#111; background:#fff; cursor:pointer;"
		>
			Export (Excel)
		</button>
		<span style="font-size:12px; opacity:.65;">Exports FY projection vs FY forecast (hybrid) by department</span>
	</div>

	<div class="cp-line"></div>

	<div class="cp-section">Composition (click to inspect)</div>

	<button class="cp-row" type="button" data-toggle="compositionYtd">
		<span class="lhs">
			<span class="name">YTD actuals</span>
			<span class="meta"><?=h($ytdLabel)?></span>
		</span>
		<span class="rhs" id="cpYtdValue"><?=money0($actualsSplit['total'])?></span>
	</button>

	<button class="cp-row" type="button" data-toggle="compositionFuture">
		<span class="lhs">
			<span class="name">Outturn (future)</span>
			<span class="meta"><?=h($futLabel)?></span>
		</span>
		<span class="rhs" id="cpFutureValue"><?=money0($forecastFuture)?></span>
	</button>

	<div id="compositionYtd" class="cp-card cp-hidden" style="margin-top:10px;">
		<div class="label">YTD actuals split</div>
		<div class="cp-note">
			Opex: <span id="cpYtdOpex"><?=money0($actualsSplit['opex'])?></span><br>
			Capex: <span id="cpYtdCapex"><?=money0($actualsSplit['capex'])?></span><br>
			Exceptional: <span id="cpYtdExcept"><?=money0($actualsSplit['except'])?></span>
		</div>
	</div>
	
	<div id="compositionFuture" class="cp-card cp-hidden" style="margin-top:10px;">
		<div class="label">Future split (outturn)</div>
		<div class="cp-note">
			Opex: <span id="cpFutureOpex">—</span><br>
			Capex: <span id="cpFutureCapex">—</span><br>
			Exceptional: <span id="cpFutureExcept">—</span>
		</div>
	</div>

	<div class="cp-line"></div>

	<div class="cp-section">Departments</div>
	
	<div style="display:flex; justify-content:space-between; gap:12px; padding:6px 0; font-size:12px; opacity:.7;">
		
		<div class="cp-note">
			Sorted by variance (worst first). Click a row for the breakdown.
		</div>
		<div style="font-weight:700;">Projected FY outturn</div>
	</div>

	<?php if (!$deptRows): ?>
		<div class="cp-card" style="margin-top:10px;">No department data available.</div>
	<?php else: ?>
		<?php foreach ($deptRows as $r):
			$var = (float)$r['variance'];
			$abs = abs($var);
			
			// 3-state indicator (same semantics as later JS)
			if ($abs <= $matOk) {
				$tag = '✅';
				$cls = 'cp-pill cp-good';
			} elseif ($var < 0) {
				// materially UNDER (attention, but not red)
				$tag = '⚠️';
				$cls = 'cp-pill cp-warn';
			} elseif ($abs <= $matAction) {
				$tag = '⚠️';
				$cls = 'cp-pill cp-good';
			} else {
				$tag = '❌';
				$cls = 'cp-pill cp-bad';
			}
			
			$id  = 'dept_' . (int)$r['dept_ref'];
		?>
			<button
				class="cp-row"
				type="button"
				data-toggle="<?=$id?>"
				data-deptref="<?= (int)$r['dept_ref'] ?>"
				data-act-total="<?= (float)$r['actS']['total'] ?>"
				data-act-opex="<?= (float)$r['actS']['opex'] ?>"
				data-act-capex="<?= (float)$r['actS']['capex'] ?>"
				data-act-except="<?= (float)$r['actS']['except'] ?>"
				
				data-fc-total="<?= (float)$r['fcS']['total'] ?>"
				data-fc-opex="<?= (float)$r['fcS']['opex'] ?>"
				data-fc-capex="<?= (float)$r['fcS']['capex'] ?>"
				data-fc-except="<?= (float)$r['fcS']['except'] ?>"
			>
				<span class="lhs">
					<span class="name"><?=h($r['dept_name'])?></span>
					<span class="meta">
						Variance vs forecast:
						<span class="<?=$cls?>" id="deptVarPill_<?= (int)$r['dept_ref'] ?>">
							<?=$tag?> <?= money0($abs) . ($var < 0 ? " under" : " over") ?>
						</span>
					</span>
				</span>
				<span class="rhs" id="deptProj_<?= (int)$r['dept_ref'] ?>"><?=money0($r['projection_fy'])?></span>
			</button>

			<div id="<?=$id?>" class="cp-card cp-hidden" style="margin-top:8px;">
				<div class="label">Breakdown</div>
				<div class="cp-note">
					Forecast (FY): <strong id="deptFcFY_<?= (int)$r['dept_ref'] ?>"><?=money0($r['forecast_fy'])?></strong><br>
					Projection (FY): <strong id="deptProjB_<?= (int)$r['dept_ref'] ?>"><?=money0($r['projection_fy'])?></strong><br>
					YTD actuals: <strong id="deptAct_<?= (int)$r['dept_ref'] ?>"><?=money0($r['actuals_ytd'])?></strong><br>
					Variance: <strong id="deptVarB_<?= (int)$r['dept_ref'] ?>"><?= money0($abs) . ($var < 0 ? " under" : " over") ?></strong>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<div class="cp-line"></div>

	<!-- This is a first spine. Next upgrades are: pay-element drill-down, and (later) Opex/Capex/Exceptionals once you start tagging. -->

</div>

<script>
document.addEventListener('click', function(e){
	const btn = e.target.closest('[data-toggle]');
	if (!btn) return;
	const id = btn.getAttribute('data-toggle');
	const el = document.getElementById(id);
	if (!el) return;
	el.classList.toggle('cp-hidden');
});
</script>
<script>
(function(){
	const notify = (msg, opts) => {
		if (typeof window.toast === 'function') return window.toast(msg, opts);
		console.log('[toast missing]', msg, opts || '');
	};

	function wireExport(){
		const btn = document.getElementById('exportCurrentPositionBtn');
		if (!btn) { console.warn('[export] button not found'); return; }

		// Prevent double-binding if the page is injected/reloaded
		if (btn.dataset.bound === '1') return;
		btn.dataset.bound = '1';

		btn.addEventListener('click', async (ev) => {
			ev.preventDefault();
			ev.stopPropagation();

			console.log('[export] click'); // DEBUG
			const url = btn.getAttribute('data-export-url');
			if (!url) { notify('Export URL missing', {type:'error'}); return; }

			try {
				btn.disabled = true;
				notify('Preparing export…', { type: 'info', duration: 6000 });

				const res = await fetch(url, {
					method: 'GET',
					credentials: 'same-origin',
					headers: {
						'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, text/csv, application/json'
					}
				});

				const ct = (res.headers.get('Content-Type') || '').toLowerCase();

				if (!res.ok) {
					let msg = `Export failed (HTTP ${res.status})`;
					if (ct.includes('application/json')) {
						const j = await res.json().catch(() => null);
						if (j && j.error) msg = j.error;
					} else {
						const t = await res.text().catch(() => '');
						if (t) msg = t;
					}
					throw new Error(msg);
				}

				const blob = await res.blob();

				let filename = 'CurrentPosition.xlsx';
				const cd = res.headers.get('Content-Disposition') || '';
				const m = cd.match(/filename="([^"]+)"/i);
				if (m && m[1]) filename = m[1];

				const a = document.createElement('a');
				a.href = URL.createObjectURL(blob);
				a.download = filename;
				document.body.appendChild(a);
				a.click();
				a.remove();
				URL.revokeObjectURL(a.href);

				notify('Download started', { type: 'success' });
			} catch (e) {
				console.warn('[export] failed', e);
				notify(e.message || 'Export failed', { type: 'error', duration: 6000 });
			} finally {
				btn.disabled = false;
			}
		});

		console.log('[export] wired'); // DEBUG
	}

	// Works whether script runs before or after DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', wireExport);
	} else {
		wireExport();
	}
})();
</script>
<script>
(function(){
	function money0(n){
		const v = Math.round(Number(n) || 0);
		return '£' + v.toLocaleString();
	}
	
	const wrap = document.querySelector('.cp-wrap');
	
	function getWrapNumber(attr){
		return Number(wrap?.getAttribute(attr) || 0) || 0;
	}
	
	function matOkFor(forecastFY){
		return Math.max(100, forecastFY * 0.001);
	}
	function matActionFor(forecastFY){
		return Math.max(1000, forecastFY * 0.005);
	}
	
	function dimKey(dim){
		if (dim === 'except') return 'except';
		return dim; // total/opex/capex
	}
	
	let currentDim = 'total';
	let futureData = null; // stores /getFutureOutturn response
	let deptFuture = new Map(); // deptRef -> {total, opex, capex, except}
	
	function getFutureForDim(dim){
		if (!futureData) return 0;
		if (dim === 'total') return Number(futureData.total) || 0;
		return Number(futureData.split?.[dimKey(dim)]) || 0;
	}
	
	function computePillStatus(dim){
		const act = getWrapNumber('data-act-' + dim);
		const fc  = getWrapNumber('data-fc-' + dim);
		const fut = getFutureForDim(dim);
	
		const proj = act + fut;
		const vari = proj - fc;
		const abs  = Math.abs(vari);
	
		const matOk = matOkFor(fc);
		const matAction = matActionFor(fc);
	
		// 3-state:
		// - bad: overspent beyond action threshold
		// - warn: underspent beyond ok threshold (attention but not red)
		// - ok: everything else
		const bad  = (vari > matAction);
		const warn = (!bad && vari < -matAction);
	
		return { bad, warn, proj, vari, fc, act, fut, matOk, matAction };
	}
	
	function setPillClasses(){
		document.querySelectorAll('#cpDimToggle .cp-dim-pill').forEach(p => {
			const dim = p.getAttribute('data-dim');
	
			// Active
			p.classList.toggle('is-active', dim === currentDim);
	
			// Status colour (always visible)
			const st = computePillStatus(dim);
			p.classList.toggle('is-ok', (!st.bad && !st.warn));
			p.classList.toggle('is-warn', st.warn);
			p.classList.toggle('is-bad', st.bad);
		});
	}
	
	function renderDim(dim){
		currentDim = dim;
	
		// 1) Top cards + future row
		const fc = getWrapNumber('data-fc-' + dim);
		const act = getWrapNumber('data-act-' + dim);
		const fut = getFutureForDim(dim);
		const proj = act + fut;
		const vari = proj - fc;
		const absVar = Math.abs(vari);
		
		// Forecast card
		const fcEl = document.getElementById('cpForecastValue');
		if (fcEl) fcEl.textContent = money0(fc);
		
		// YTD actuals row value
		const ytdEl = document.getElementById('cpYtdValue');
		if (ytdEl) ytdEl.textContent = money0(act);
		
		// Future row value
		const futEl = document.getElementById('cpFutureValue');
		if (futEl) futEl.textContent = money0(fut);
	
		// Projection card
		const projEl = document.getElementById('cpProjectionValue');
		if (projEl) projEl.textContent = money0(proj);
	
		// Verdict line (use thresholds per-dimension)
		const matOk = matOkFor(fc);
		const matAction = matActionFor(fc);
	
		let emoji, label;
		if (absVar <= matOk) { emoji='✅'; label='All clear'; }
		else if (absVar <= matAction) { emoji='⚠️'; label='Watch'; }
		else { emoji='❌'; label='Action needed'; }
	
		const verdictEl = document.getElementById('cpVerdictLine');
		if (verdictEl) verdictEl.textContent = emoji + " " + label + " (" + money0(absVar) + (vari < 0 ? " under" : " over") + ")";
	
		// Mini line thresholds update (keep your existing DOM-build approach)
		const mini = document.getElementById('cpVerdictMini');
		if (mini) {
			mini.innerHTML = "";
			const ytdStrong = document.createElement('strong');
			const ytdSpan = document.createElement('span');
			const futureStrong = document.createElement('strong');
			const futureSpan = document.createElement('span');
			const thresholdsStrong = document.createElement('strong');
			const thresholdsSpan = document.createElement('span');
	
			ytdStrong.textContent = "YTD actuals: ";
			ytdSpan.textContent = <?=json_encode($ytdLabel)?> + " · ";
			futureStrong.textContent = "Future: ";
			futureSpan.textContent = <?=json_encode($futLabel)?> + " · ";
			thresholdsStrong.textContent = "Thresholds: ";
			thresholdsSpan.textContent = money0(matOk) + " / " + money0(matAction);
	
			mini.appendChild(ytdStrong);
			mini.appendChild(ytdSpan);
			mini.appendChild(futureStrong);
			mini.appendChild(futureSpan);
			mini.appendChild(thresholdsStrong);
			mini.appendChild(thresholdsSpan);
		}
	
		// 2) Departments (use future map per dimension + per-dept act/fc from data attrs)
		document.querySelectorAll('button.cp-row[data-deptref]').forEach(btn => {
			const deptRef = Number(btn.getAttribute('data-deptref'));
	
			const actD = Number(btn.getAttribute('data-act-' + dim)) || 0;
			const fcD  = Number(btn.getAttribute('data-fc-' + dim)) || 0;
	
			const futObj = deptFuture.get(deptRef) || { total:0, opex:0, capex:0, except:0 };
			const futD = Number(futObj[dimKey(dim)] || 0);
	
			const projD = actD + futD;
			const variD = projD - fcD;
			const absD = Math.abs(variD);
	
			// RHS projection
			const rhs = document.getElementById('deptProj_' + deptRef);
			if (rhs) rhs.textContent = money0(projD);
	
			// Variance pill
			const pill = document.getElementById('deptVarPill_' + deptRef);
			if (pill) {
				const matOkD = matOkFor(fcD);
				const matActionD = matActionFor(fcD);
				
				let tag, cls;
				
				// 3-state
				if (absD <= matOkD) {
					tag = '✅'; cls = 'cp-good';
				} else if (variD < 0) {
					// materially UNDER
					tag = '⚠️'; cls = 'cp-warn';
				} else if (absD <= matActionD) {
					tag = '⚠️'; cls = 'cp-good';
				} else {
					tag = '❌'; cls = 'cp-bad';
				}
				
				pill.classList.remove('cp-good','cp-warn','cp-bad');
				pill.classList.add(cls);
				pill.textContent = tag + ' ' + money0(absD) + (variD < 0 ? ' under' : ' over');
			}
	
			// Breakdown
			const bProj = document.getElementById('deptProjB_' + deptRef);
			if (bProj) bProj.textContent = money0(projD);
	
			const bVar = document.getElementById('deptVarB_' + deptRef);
			if (bVar) bVar.textContent = (variD <= 0) ? (money0(Math.abs(variD)) + " under") : (money0(variD) + " over");
	
			// Also update FY Forecast + YTD actual lines in breakdown for selected dim
			const bFc = document.getElementById('deptFcFY_' + deptRef);
			if (bFc) bFc.textContent = money0(fcD);
	
			const bAct = document.getElementById('deptAct_' + deptRef);
			if (bAct) bAct.textContent = money0(actD);
		});
	
		setPillClasses();
	}
	
	function wirePills(){
		document.getElementById('cpDimToggle')?.addEventListener('click', (e) => {
			const b = e.target.closest('.cp-dim-pill');
			if (!b) return;
			const dim = b.getAttribute('data-dim');
			if (!dim) return;
			renderDim(dim);
		});
	}

	async function run(){
		// Calls your new outturn engine endpoint
		const res = await fetch('/scripts/getFutureOutturn.php', { credentials: 'same-origin' });
		const text = await res.text();
		if(!res.ok) throw new Error('HTTP ' + res.status + ': ' + text);

		const data = JSON.parse(text);

		// 1) Future value (outturn future)
		const futureEl = document.getElementById('cpFutureValue');
		if (futureEl) futureEl.textContent = money0(data.total);
		
		// Future split (opex/capex/exceptional)
		if (data.split) {
			const o = document.getElementById('cpFutureOpex');
			const c = document.getElementById('cpFutureCapex');
			const e = document.getElementById('cpFutureExcept');
		
			if (o) o.textContent = money0(data.split.opex);
			if (c) c.textContent = money0(data.split.capex);
			if (e) e.textContent = money0(data.split.except);
		}

		// Pull YTD from first composition row (YTD actuals)
		const ytdText = document.querySelector('button.cp-row[data-toggle="composition"] .rhs')?.textContent || '£0';
		const ytd = Number(ytdText.replace(/[^0-9.-]/g,'')) || 0;

		// 2) Projection + verdict
		const projection = ytd + (Number(data.total) || 0);

		const projEl = document.getElementById('cpProjectionValue');
		if (projEl) projEl.textContent = money0(projection);

		const forecastFY = Number(document.querySelector('.cp-wrap')?.getAttribute('data-forecastfy') || 0);
		const variance = projection - forecastFY;

		const verdictEl = document.getElementById('cpVerdictLine');
		const absVar = Math.abs(variance);
		
		// Thresholds from PHP (fallbacks if missing)
		const verdictNode = document.getElementById('cpVerdictLine');
		const matOk = Number(verdictNode?.getAttribute('data-matok')) || Math.max(100, forecastFY * 0.001);
		const matAction = Number(verdictNode?.getAttribute('data-mataction')) || Math.max(1000, forecastFY * 0.005);
		
		let emoji, label;
		if (absVar <= matOk) {
			emoji = '✅';
			label = 'All clear';
		} else if (absVar <= matAction) {
			emoji = '⚠️';
			label = 'Watch';
		} else {
			emoji = '❌';
			label = 'Action needed';
		}
		
		if (verdictEl) {
			verdictEl.textContent = emoji + " " + label + " (" + money0(absVar) + (variance < 0 ? " under" : " over") + ")";
		}
		
		const mini = document.getElementById('cpVerdictMini');
		const ytdStrong = document.createElement('strong');
		const ytdSpan = document.createElement('span');
		const futureStrong = document.createElement('strong');
		const futureSpan = document.createElement('span');
		const thresholdsStrong = document.createElement('strong');
		const thresholdsSpan = document.createElement('span');
		ytdStrong.textContent = "YTD actuals: ";
		ytdSpan.textContent = <?=json_encode($ytdLabel)?> + " · ";
		futureStrong.textContent = "Future: ";
		futureSpan.textContent = <?=json_encode($futLabel)?> + " · ";
		thresholdsStrong.textContent = "Thresholds: ";
		thresholdsSpan.textContent = money0(matOk) + " / " + money0(matAction);
		
		if (mini) {
			mini.innerHTML = "";
			mini.appendChild(ytdStrong);
			mini.appendChild(ytdSpan);
			mini.appendChild(futureStrong);
			mini.appendChild(futureSpan);
			mini.appendChild(thresholdsStrong);
			mini.appendChild(thresholdsSpan);
//		mini.textContent = "YTD actuals: " + <?=json_encode($ytdLabel)?> + " · Future: " + <?=json_encode($futLabel)?> + " · Thresholds: " + money0(matOk) + " / " + money0(matAction);
		}

		// 3) Departments – build future split map ONLY
		futureData = data;
		deptFuture = new Map();
		
		if (Array.isArray(data.by_department)) {
			data.by_department.forEach(d => {
				const k = Number(d.dept_ref);
				if (Number.isNaN(k)) return;
		
				deptFuture.set(k, {
					total:  Number(d.total) || 0,
					opex:   Number(d.split?.opex) || 0,
					capex:  Number(d.split?.capex) || 0,
					except: Number(d.split?.except) || 0,
				});
			});
		}
		
		// Initial render (Total is default)
		wirePills();
		renderDim('total');
	}

	run().catch(err => {
		console.warn('[getFutureOutturn] failed', err);
	});
})();
</script>