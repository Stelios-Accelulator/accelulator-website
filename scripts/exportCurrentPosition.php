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
	$out = [0 => 'Unallocated'];
	try {
		$tD = "{$ref}_departments";
		$stmt = $pdo->query("SELECT REF, DEPARTMENT FROM {$tD}");
		while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$out[(int)$r['REF']] = $r['DEPARTMENT'] ?: 'Unallocated';
		}
	} catch (Throwable $e) {}
	return $out;
}

// Actuals by dept by month (TOTAL people-cost only)
function actuals_by_dept_month(PDO $pdo, int $ref, DateTimeImmutable $from, DateTimeImmutable $to): array {
	$tA = "{$ref}_actuals";
	$tP = "{$ref}_paytype";
	$tR = "{$ref}_resources";

	$groupIds = [1,2,3,4,5,6,7,8,9,10];
	$ph = implode(',', array_fill(0, count($groupIds), '?'));

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
		GROUP BY r.DEPARTMENT, DATE_FORMAT(a.DATE, '%Y-%m-01')
	";
	$params = array_merge([$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')], $groupIds);

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

// Forecast sums by dept by month for the set (resources + unallocated roles)
function forecast_by_dept_month(PDO $pdo, int $ref, array $fs, array $fyMonths, array $payElements): array {
	$tF = "{$ref}_forecasts";
	$tR = "{$ref}_resources";
	$tRole = "{$ref}_roles";

	$mPH  = implode(',', array_fill(0, count($fyMonths), '?'));
	$pePH = implode(',', array_fill(0, count($payElements), '?'));

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
		GROUP BY f.MONTH, dept_ref
	";

	$params = array_merge(
		[$fs['ACTUAL_FORECAST'], $fs['FORECAST_NAME'], (int)$fs['FORECAST_VERSION']],
		$fyMonths,
		$payElements
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
	$now = new DateTimeImmutable('now');

	$fs = get_latest_published_forecast_set($pdo, $ref);
	if (!$fs) throw new RuntimeException("No published forecast found.");

	// Canonical list (same as your page)
	$payElements = ['base','overtime','onCall','bonus','other','welfare','pension','statutoryPay','employersNI','commission'];

	[$fyStart, $fyEnd] = get_fy_bounds($pdo, $ref, $now);
	$actualsEnd = end_of_last_complete_month($now);
	if ($actualsEnd < $fyStart) $actualsEnd = $now;

	$fyMonths = month_list(first_of_month($fyStart), first_of_month($fyEnd));

	// 1) Projection FY = actuals (month-by-month) + future outturn (already dept split)
	$deptNames = dept_names($pdo, $ref);

	$actByMonthDept = actuals_by_dept_month($pdo, $ref, $fyStart, $actualsEnd);

	// Months from next month to FY end (inclusive), capped sensibly
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
			$projByDept[$dept] = ($projByDept[$dept] ?? 0) + (float)$v;
		}
	}
	if (!empty($future['by_department']) && is_array($future['by_department'])) {
		foreach ($future['by_department'] as $d) {
			$dept = (int)($d['dept_ref'] ?? 0);
			$projByDept[$dept] = ($projByDept[$dept] ?? 0) + (float)($d['total'] ?? 0);
		}
	}

	// 2) Forecast FY (hybrid):
	// - if a month has ANY forecast rows in this set -> use forecast for that month
	// - else -> use actuals for that month (so “missing forecast months” don’t distort)
	$monthHasForecast = forecast_month_has_rows($pdo, $ref, $fs, $fyMonths, $payElements);
	$fcByMonthDept = forecast_by_dept_month($pdo, $ref, $fs, $fyMonths, $payElements);

	$hybByDept = []; // deptRef => total
	foreach ($fyMonths as $ml) {
		$useForecast = !empty($monthHasForecast[$ml]);

		if ($useForecast) {
			foreach (($fcByMonthDept[$ml] ?? []) as $dept => $v) {
				$hybByDept[$dept] = ($hybByDept[$dept] ?? 0) + (float)$v;
			}
		} else {
			foreach (($actByMonthDept[$ml] ?? []) as $dept => $v) {
				$hybByDept[$dept] = ($hybByDept[$dept] ?? 0) + (float)$v;
			}
		}
	}

	// 3) Build export rows (dept + total)
	$deptKeys = array_unique(array_merge(array_keys($projByDept), array_keys($hybByDept), array_keys($deptNames)));
	sort($deptKeys);

	$rows = [];
	$totForecast = 0.0;
	$totProj = 0.0;

	foreach ($deptKeys as $dept) {
		$dept = (int)$dept;
		$forecast = (float)($hybByDept[$dept] ?? 0);
		$proj = (float)($projByDept[$dept] ?? 0);
		$var = $proj - $forecast;

		// Skip true empties (optional: keep them if you prefer)
		if (abs($forecast) < 0.00001 && abs($proj) < 0.00001) continue;

		$rows[] = [
			'Department' => $deptNames[$dept] ?? ($dept === 0 ? 'Unallocated' : "Dept {$dept}"),
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

		// Headers
		$headers = array_keys($rows[0]);
		$ws->fromArray($headers, null, 'A1');

		// Data
		$r = 2;
		foreach ($rows as $row) {
			$ws->fromArray(array_values($row), null, "A{$r}");
			$r++;
		}

		// Formats
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

		// TOTAL row bold
		$ws->getStyle("A{$lastRow}:D{$lastRow}")->getFont()->setBold(true);

		// Output
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="'.$baseName.'.xlsx"');
		header('Cache-Control: max-age=0');

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