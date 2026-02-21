<?php
// /scripts/exportCurrentPosition.php

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/outturn_engine.php';

$user = checkUser();
$ref  = (int)getUsersCompanyId($user);

// Global $pdo is initialised in functions.php (per your preference)
if (!isset($pdo) || !($pdo instanceof PDO)) {
	http_response_code(500);
	echo "PDO not available.";
	exit;
}

$userRef    = (int)($_SESSION['userRef'] ?? 0);
$userAccess = (int)($_SESSION['userAccess'] ?? 0);

// Dept-restricted roles (keep aligned with currentPosition.php)
$isDeptRestricted = in_array($userAccess, [5,7,8], true);

// ------------------------------------------------------------
// Export token (reduces link-sharing risk)
// - token must match session
// - expires quickly
// - one-time use
// ------------------------------------------------------------
$token = (string)($_GET['t'] ?? '');
$tokenSession = (string)($_SESSION['export_cp_token'] ?? '');
$tokenTs = (int)($_SESSION['export_cp_token_ts'] ?? 0);

$maxAgeSeconds = 180; // 3 minutes (tweak if you like)

if ($token === '' || $tokenSession === '' || !hash_equals($tokenSession, $token)) {
	http_response_code(403);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => 'Export link expired. Please refresh the page and try again.']);
	exit;
}
if ($tokenTs <= 0 || (time() - $tokenTs) > $maxAgeSeconds) {
	unset($_SESSION['export_cp_token'], $_SESSION['export_cp_token_ts']);
	http_response_code(403);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => 'Export link expired. Please refresh the page and try again.']);
	exit;
}
// one-time use
unset($_SESSION['export_cp_token'], $_SESSION['export_cp_token_ts']);

// ------------------------------------------------------------
// Dept access helpers (same logic as currentPosition.php)
// ------------------------------------------------------------
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

// -----------------------------
// helpers (keep aligned with currentPosition.php)
// -----------------------------
function month_label(DateTimeImmutable $d): string { return $d->format('M-y'); }
function first_of_month(DateTimeImmutable $d): DateTimeImmutable { return new DateTimeImmutable($d->format('Y-m-01 00:00:00')); }
function end_of_last_complete_month(DateTimeImmutable $now): DateTimeImmutable { return first_of_month($now)->modify('-1 second'); }

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

// Dept names
function dept_names(PDO $pdo, int $ref): array {
	$out = []; // note: we do NOT include Unallocated here (by design)
	try {
		$tD = "{$ref}_departments";
		$stmt = $pdo->query("SELECT REF, DEPARTMENT FROM {$tD}");
		while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$k = (int)$r['REF'];
			if ($k > 0) $out[$k] = $r['DEPARTMENT'] ?: ("Dept {$k}");
		}
	} catch (Throwable $e) {}
	return $out;
}

// CSV injection hardening for the Department column (Excel formula injection)
function safe_cell_text(string $s): string {
	$s = trim($s);
	if ($s === '') return $s;
	$first = $s[0];
	if ($first === '=' || $first === '+' || $first === '-' || $first === '@') {
		return "'" . $s;
	}
	return $s;
}

// Actuals by dept by month (TOTAL people-cost only) with dept filtering
function actuals_by_dept_month(PDO $pdo, int $ref, DateTimeImmutable $from, DateTimeImmutable $to, bool $isDeptRestricted, array $allowedDeptRefs): array {
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
		// Always exclude unallocated (0) in this export
		$deptClause = " AND r.DEPARTMENT IS NOT NULL AND r.DEPARTMENT <> 0 ";
	}

	$sql = "
		SELECT
			r.DEPARTMENT AS dept_ref,
			DATE_FORMAT(a.DATE, '%Y-%m-01') AS month_start,
			COALESCE(SUM(a.VALUE),0) AS total
		FROM {$tA} a
		JOIN {$tP} p ON p.REF = a.TYPE
		JOIN {$tR} r ON r.REF = a.EMP_KEY
		WHERE a.DATE >= ?
			AND a.DATE <= ?
			AND p.PAYTYPE_GROUP_REF IN ($ph)
			{$deptClause}
		GROUP BY r.DEPARTMENT, DATE_FORMAT(a.DATE, '%Y-%m-01')
	";

	$params = array_merge(
		[$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
		$groupIds,
		$deptParams
	);

	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);

	$out = []; // [monthLabel][deptRef] = total
	while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$dept = (int)$r['dept_ref'];
		$ms   = (string)$r['month_start']; // YYYY-mm-01
		$dt   = new DateTimeImmutable($ms . " 00:00:00");
		$ml   = month_label($dt);
		$out[$ml][$dept] = (float)$r['total'];
	}
	return $out;
}

// Forecast month coverage (does this month have ANY forecast rows in the set?)
function forecast_month_has_rows(PDO $pdo, int $ref, array $fs, array $fyMonths, array $payElements): array {
	$tF = "{$ref}_forecasts";
	$mPH  = implode(',', array_fill(0, count($fyMonths), '?'));
	$pePH = implode(',', array_fill(0, count($payElements), '?'));

	$sql = "
		SELECT f.MONTH, COUNT(*) AS c
		FROM {$tF} f
		WHERE f.IS_PUBLISHED = 1
			AND f.IS_ACTUAL = 0
			AND f.ACTUAL_FORECAST = ?
			AND f.FORECAST_NAME = ?
			AND f.FORECAST_VERSION = ?
			AND f.MONTH IN ($mPH)
			AND f.PAY_ELEMENT IN ($pePH)
		GROUP BY f.MONTH
	";

	$params = array_merge(
		[$fs['ACTUAL_FORECAST'], $fs['FORECAST_NAME'], (int)$fs['FORECAST_VERSION']],
		$fyMonths,
		$payElements
	);

	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);

	$out = []; // [monthLabel] => true
	while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$out[(string)$r['MONTH']] = ((int)$r['c'] > 0);
	}
	return $out;
}

// Forecast sums by dept by month for the set (resources + unallocated roles) with dept filtering
function forecast_by_dept_month(PDO $pdo, int $ref, array $fs, array $fyMonths, array $payElements, bool $isDeptRestricted, array $allowedDeptRefs): array {
	$tF = "{$ref}_forecasts";
	$tR = "{$ref}_resources";
	$tRole = "{$ref}_roles";

	$mPH  = implode(',', array_fill(0, count($fyMonths), '?'));
	$pePH = implode(',', array_fill(0, count($payElements), '?'));

	$deptClause = "";
	$deptParams = [];

	if ($isDeptRestricted) {
		$dph = implode(',', array_fill(0, count($allowedDeptRefs), '?'));
		$deptClause = "
			AND (
				CASE
					WHEN f.TYPE='resource' THEN r.DEPARTMENT
					ELSE ro.DEPARTMENT
				END
			) IN ($dph)
		";
		$deptParams = $allowedDeptRefs;
	} else {
		$deptClause = "
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
			f.MONTH AS mon,
			CASE
				WHEN f.TYPE='resource' THEN r.DEPARTMENT
				ELSE ro.DEPARTMENT
			END AS dept_ref,
			COALESCE(SUM(f.VALUE),0) AS total
		FROM {$tF} f
		LEFT JOIN {$tR} r ON r.REF = f.ROLE_REFERENCE AND f.TYPE='resource'
		LEFT JOIN {$tRole} ro ON ro.REF = f.ROLE_REFERENCE AND f.TYPE='role'
		WHERE f.IS_PUBLISHED = 1
			AND f.IS_ACTUAL = 0
			AND f.ACTUAL_FORECAST = ?
			AND f.FORECAST_NAME = ?
			AND f.FORECAST_VERSION = ?
			AND f.MONTH IN ($mPH)
			AND f.PAY_ELEMENT IN ($pePH)
			AND (
				(f.TYPE='resource' AND r.REF IS NOT NULL)
				OR
				(f.TYPE='role' AND ro.REF IS NOT NULL AND (ro.FILLED_REFERENCE IS NULL OR ro.FILLED_REFERENCE=0))
			)
			{$deptClause}
		GROUP BY f.MONTH, dept_ref
	";

	$params = array_merge(
		[$fs['ACTUAL_FORECAST'], $fs['FORECAST_NAME'], (int)$fs['FORECAST_VERSION']],
		$fyMonths,
		$payElements,
		$deptParams
	);

	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);

	$out = []; // [monthLabel][deptRef] = total
	while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$ml = (string)$r['mon'];
		$dept = (int)$r['dept_ref'];
		$out[$ml][$dept] = (float)$r['total'];
	}
	return $out;
}

// -----------------------------
// main
// -----------------------------
try {
	// If dept-restricted, enforce configured departments
	$allowedDeptRefs = $isDeptRestricted ? get_allowed_departments($pdo, $ref, $userRef) : [];
	if ($isDeptRestricted && count($allowedDeptRefs) === 0) {
		http_response_code(403);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['error' => 'Department access not configured. Contact your administrator.']);
		exit;
	}

	$now = new DateTimeImmutable('now');

	$fs = get_latest_published_forecast_set($pdo, $ref);
	if (!$fs) throw new RuntimeException("No published forecast found.");

	$payElements = ['base','overtime','onCall','bonus','other','welfare','pension','statutoryPay','employersNI','commission'];

	[$fyStart, $fyEnd] = get_fy_bounds($pdo, $ref, $now);
	$actualsEnd = end_of_last_complete_month($now);
	if ($actualsEnd < $fyStart) $actualsEnd = $now;

	$fyMonths = month_list(first_of_month($fyStart), first_of_month($fyEnd));

	$deptNames = dept_names($pdo, $ref);

	// 1) Projection FY = actuals + future outturn
	$actByMonthDept = actuals_by_dept_month($pdo, $ref, $fyStart, $actualsEnd, $isDeptRestricted, $allowedDeptRefs);

	$nextMonth = first_of_month($now)->modify('+1 month');
	$fyEndFirst = first_of_month($fyEnd);

	$monthsAhead = 0;
	$cur = $nextMonth;
	while ($cur <= $fyEndFirst) {
		$monthsAhead++;
		$cur = $cur->modify('+1 month');
	}
	$monthsAhead = max(1, min(24, $monthsAhead));

	$future = calculate_future_outturn($pdo, $ref, $now, $monthsAhead);

	$projByDept = []; // deptRef => total
	foreach ($actByMonthDept as $ml => $row) {
		foreach ($row as $dept => $v) {
			$dept = (int)$dept;
			if ($dept <= 0) continue;
			$projByDept[$dept] = ($projByDept[$dept] ?? 0) + (float)$v;
		}
	}

	if (!empty($future['by_department']) && is_array($future['by_department'])) {
		foreach ($future['by_department'] as $d) {
			$dept = (int)($d['dept_ref'] ?? 0);
			if ($dept <= 0) continue;

			// If restricted, only include allowed depts (belt + braces)
			if ($isDeptRestricted && !in_array($dept, $allowedDeptRefs, true)) continue;

			$projByDept[$dept] = ($projByDept[$dept] ?? 0) + (float)($d['total'] ?? 0);
		}
	}

	// 2) Forecast FY (hybrid)
	$monthHasForecast = forecast_month_has_rows($pdo, $ref, $fs, $fyMonths, $payElements);
	$fcByMonthDept = forecast_by_dept_month($pdo, $ref, $fs, $fyMonths, $payElements, $isDeptRestricted, $allowedDeptRefs);

	$hybByDept = []; // deptRef => total
	foreach ($fyMonths as $ml) {
		$useForecast = !empty($monthHasForecast[$ml]);

		if ($useForecast) {
			foreach (($fcByMonthDept[$ml] ?? []) as $dept => $v) {
				$dept = (int)$dept;
				if ($dept <= 0) continue;
				$hybByDept[$dept] = ($hybByDept[$dept] ?? 0) + (float)$v;
			}
		} else {
			foreach (($actByMonthDept[$ml] ?? []) as $dept => $v) {
				$dept = (int)$dept;
				if ($dept <= 0) continue;
				$hybByDept[$dept] = ($hybByDept[$dept] ?? 0) + (float)$v;
			}
		}
	}

	// 3) Build export rows (dept + total), excluding unallocated
	$deptKeys = array_unique(array_merge(array_keys($projByDept), array_keys($hybByDept), array_keys($deptNames)));
	$deptKeys = array_values(array_filter($deptKeys, fn($d) => (int)$d > 0));
	sort($deptKeys);

	$rows = [];
	$totForecast = 0.0;
	$totProj = 0.0;

	foreach ($deptKeys as $dept) {
		$dept = (int)$dept;

		// Final gate (just in case)
		if ($isDeptRestricted && !in_array($dept, $allowedDeptRefs, true)) continue;

		$forecast = (float)($hybByDept[$dept] ?? 0);
		$proj = (float)($projByDept[$dept] ?? 0);
		$var = $proj - $forecast;

		if (abs($forecast) < 0.00001 && abs($proj) < 0.00001) continue;

		$deptLabel = $deptNames[$dept] ?? "Dept {$dept}";

		$rows[] = [
			'Department' => safe_cell_text($deptLabel),
			'Forecast FY (hybrid)' => $forecast,
			'Actuals + Outturn FY' => $proj,
			'Variance' => $var,
		];

		$totForecast += $forecast;
		$totProj += $proj;
	}

	$rows[] = [
		'Department' => 'TOTAL',
		'Forecast FY (hybrid)' => $totForecast,
		'Actuals + Outturn FY' => $totProj,
		'Variance' => ($totProj - $totForecast),
	];

	if (count($rows) === 1) {
		// Only TOTAL exists => no dept data
		throw new RuntimeException('No department data available for export.');
	}

	// -----------------------------
	// Output headers (no caching)
	// -----------------------------
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	header('Expires: 0');

	// -----------------------------
	// Write XLSX (PhpSpreadsheet) or fallback CSV
	// -----------------------------
	$baseName = "CurrentPosition_{$ref}_" . $fyStart->format('Ymd') . "_" . $fyEnd->format('Ymd');

	$autoload = __DIR__ . '/../vendor/autoload.php';
	if (file_exists($autoload)) require_once $autoload;

	if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
		$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$ws = $ss->getActiveSheet();
		$ws->setTitle('FY Comparison');

		$headers = array_keys($rows[0]);
		$ws->fromArray($headers, null, 'A1');

		$r = 2;
		foreach ($rows as $row) {
			// Ensure Department remains plain text
			$row['Department'] = (string)$row['Department'];
			$ws->fromArray(array_values($row), null, "A{$r}");
			$r++;
		}

		$lastRow = $r - 1;
		$ws->getStyle("A1:D1")->getFont()->setBold(true);
		$ws->getColumnDimension('A')->setWidth(28);
		$ws->getColumnDimension('B')->setWidth(18);
		$ws->getColumnDimension('C')->setWidth(18);
		$ws->getColumnDimension('D')->setWidth(14);

		$currency = '£#,##0;[Red]-£#,##0';
		$ws->getStyle("B2:D{$lastRow}")
			->getNumberFormat()
			->setFormatCode($currency);

		$ws->getStyle("A{$lastRow}:D{$lastRow}")->getFont()->setBold(true);

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="'.$baseName.'.xlsx"');
		header('Content-Security-Policy: sandbox'); // extra hardening for downloads

		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
		$writer->save('php://output');
		exit;
	}

	// Fallback CSV
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$baseName.'.csv"');

	$out = fopen('php://output', 'w');
	fputcsv($out, array_keys($rows[0]));
	foreach ($rows as $row) fputcsv($out, $row);
	fclose($out);
	exit;

} catch (Throwable $e) {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => $e->getMessage()]);
	exit;
}