<?php
// /includes/outturn_engine.php

declare(strict_types=1);

if (!function_exists('h')) {
	function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * FY starts month after year-end month and ends on last day of year-end month.
 * YE is stored in companyYearEnd.MONTHNO (1-12).
 */
function outturn_get_company_year_end_monthno(PDO $pdo, int $ref): int {
	$stmt = $pdo->prepare("SELECT MONTHNO FROM companyYearEnd WHERE COMPANY = :c LIMIT 1");
	$stmt->execute([':c' => $ref]);
	$m = (int)($stmt->fetchColumn() ?: 0);
	if ($m < 1 || $m > 12) throw new RuntimeException("Invalid companyYearEnd.MONTHNO for company {$ref}");
	return $m;
}

function outturn_get_fy_bounds(PDO $pdo, int $ref, DateTimeImmutable $now): array {
	$ye = outturn_get_company_year_end_monthno($pdo, $ref);
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

function outturn_first_of_month(DateTimeImmutable $d): DateTimeImmutable {
	return new DateTimeImmutable($d->format('Y-m-01 00:00:00'));
}
function outturn_last_day_of_month(DateTimeImmutable $firstOfMonth): DateTimeImmutable {
	return $firstOfMonth->modify('last day of this month')->setTime(23,59,59);
}

/**
 * Percentage of days worked in a given month, based on overlap of [start,end] with that month.
 */
function outturn_percentage_worked_in_month(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $monthFirst): float {
	$monthStart = $monthFirst;
	$monthEnd   = outturn_last_day_of_month($monthFirst);

	// If end date is "open", you may store as null/0000-00-00; caller should normalise.
	$rangeStart = ($start > $monthStart) ? $start : $monthStart;
	$rangeEnd   = ($end   < $monthEnd)   ? $end   : $monthEnd;

	if ($rangeEnd < $rangeStart) return 0.0;

	// inclusive day count
	$daysInMonth = (int)$monthEnd->format('j'); // last day number
	$workedDays = (int)$rangeEnd->diff($rangeStart)->days + 1;

	return max(0.0, min(1.0, $workedDays / $daysInMonth));
}

/**
 * Employer NI based on ni_employers_rates table (monthly threshold + rate, date-ranged).
 * Mirrors your JS niBandLookup behaviour.
 */
function outturn_load_ni_bands(PDO $pdo): array {
	$rows = $pdo->query("
		SELECT
			FROM_DATE,
			TO_DATE,
			SECONDARY_THRESHOLD_MONTHLY,
			RATE
		FROM ni_employers_rates
		ORDER BY FROM_DATE ASC
	")->fetchAll(PDO::FETCH_ASSOC);

	$bands = [];
	foreach ($rows as $r) {
		$bands[] = [
			'from' => new DateTimeImmutable($r['FROM_DATE']),
			'to'   => new DateTimeImmutable($r['TO_DATE']),
			'threshold' => (float)$r['SECONDARY_THRESHOLD_MONTHLY'],
			'rate' => (float)$r['RATE'],
		];
	}
	return $bands;
}

function outturn_employers_ni(array $bands, float $monthlyIncomeForNI, DateTimeImmutable $monthFirst): float {
	foreach ($bands as $b) {
		if ($monthFirst >= $b['from'] && $monthFirst <= $b['to']) {
			$charge = max(0.0, $monthlyIncomeForNI - $b['threshold']);
			return round($charge * $b['rate'], 2);
		}
	}
	// If no band matches, be safe: 0 (or throw). I choose 0 for resilience.
	return 0.0;
}

/**
 * Load user overrides from {$ref}_outturn.
 * Keyed by [res_rol][emp_key][monthKey][typeId] => value
 * monthKey is "Y-m" (e.g. 2026-01).
 */
function outturn_load_overrides(PDO $pdo, int $ref): array {
	$t_outturn = "{$ref}_outturn";
	$rows = $pdo->query("SELECT EMP_KEY, RES_ROL, DATE, TYPE, VALUE FROM {$t_outturn}")->fetchAll(PDO::FETCH_ASSOC);

	$ov = [];
	foreach ($rows as $r) {
		$emp = (int)$r['EMP_KEY'];
		$rr  = (string)$r['RES_ROL']; // 'resource'|'role'
		$type= (int)$r['TYPE']; // pay group id
		$val = (float)$r['VALUE'];

		$dt = new DateTimeImmutable($r['DATE']);
		$monthKey = $dt->format('Y-m');

		$ov[$rr][$emp][$monthKey][$type] = $val;
	}
	return $ov;
}

/**
 * Weighted history (v1): average monthly value over last N closed months for each emp_key and type.
 * Uses {$ref}_actuals where TYPE is paytype_group id (1..10).
 * Returns [emp_key][typeId] => avgMonthly
 */
function outturn_weighted_history_from_actuals(PDO $pdo, int $ref, DateTimeImmutable $asOf, int $monthsBack = 7): array {
	 $tA = "{$ref}_actuals";
	 $tP = "{$ref}_paytype";
 
	 $end = outturn_first_of_month($asOf)->modify('-1 second');
	 $start = outturn_first_of_month($end)->modify(sprintf('-%d months', $monthsBack-1));
 
	 $sql = "
		 SELECT
			 a.EMP_KEY,
			 p.PAYTYPE_GROUP_REF AS TYPE_GROUP,
			 DATE_FORMAT(a.DATE, '%Y-%m') AS ym,
			 SUM(a.VALUE) AS m_total
		 FROM {$tA} a
		 INNER JOIN {$tP} p
			 ON p.REF = a.TYPE
		 WHERE a.DATE >= ?
			 AND a.DATE <= ?
			 AND p.PAYTYPE_GROUP_REF BETWEEN 1 AND 10
		 GROUP BY a.EMP_KEY, p.PAYTYPE_GROUP_REF, ym
	 ";
 
	 $stmt = $pdo->prepare($sql);
	 $stmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
	 $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
	 $tmp = [];
	 foreach ($rows as $r) {
		 $emp  = (int)$r['EMP_KEY'];
		 $type = (int)$r['TYPE_GROUP']; // << now truly 1..10 group id
		 $ym   = (string)$r['ym'];
		 $val  = (float)$r['m_total'];
 
		 if (!isset($tmp[$emp][$type])) $tmp[$emp][$type] = ['sum'=>0.0,'months'=>[]];
		 $tmp[$emp][$type]['sum'] += $val;
		 $tmp[$emp][$type]['months'][$ym] = true;
	 }
 
	 $out = [];
	 foreach ($tmp as $emp => $types) {
		 foreach ($types as $type => $bucket) {
			 $monthCount = max(1, count($bucket['months']));
			 $out[(int)$emp][(int)$type] = round($bucket['sum'] / $monthCount, 2);
		 }
	 }
	 return $out;
 }

/**
 * Apply rises up to (and including) the given monthFirst (1st of month).
 * Mirrors annualAt(monthStr) in JS.
 *
 * $risesForResource rows should contain EFFECTIVE_DATE, RISE_KIND, VALUE.
 */
function outturn_annual_at_month(float $baseAnnual, array $risesForResource, DateTimeImmutable $monthFirst): float {
	$annual = $baseAnnual;

	foreach ($risesForResource as $r) {
		$effRaw = (string)($r['EFFECTIVE_DATE'] ?? '');
		if ($effRaw === '' || $effRaw === '0000-00-00') continue;

		$eff = new DateTimeImmutable($effRaw . ' 00:00:00');
		if ($eff > $monthFirst) continue;

		$kind = strtoupper((string)($r['RISE_KIND'] ?? 'PCT'));
		$val  = (float)($r['VALUE'] ?? 0);

		if ($kind === 'PCT') {
			$annual = round($annual * (1 + ($val / 100)), 2);
		} elseif ($kind === 'ABS') {
			$annual = round($annual + $val, 2);
		} elseif ($kind === 'NEW') {
			$annual = round($val, 2);
		}
	}

	return $annual;
}

/**
 * Calculate future outturn totals (this month -> FY end), by dept.
 *
 * v1 assumptions:
 * - base pay uses annual_salary/12 (resources), benchmarkSalary/12 (roles)
 * - other elements use average monthly from last N closed months actuals
 * - proration by days worked per month between start/end
 * - pension = 4% of (base+overtime+onCall+bonus+commission), 0 for contractType==3
 * - employers NI from ni_employers_rates, 0 for contractType==3
 * - overrides from {$ref}_outturn replace specific element month values
 */
function calculate_future_outturn(PDO $pdo, int $ref, DateTimeImmutable $now, int $actualMonthsBack = 7): array {
	$t_resources = "{$ref}_resources";
	$t_roles     = "{$ref}_roles";
	$t_deps      = "{$ref}_departments";

	[$fyStart, $fyEnd] = outturn_get_fy_bounds($pdo, $ref, $now);

	$outturnStart = outturn_first_of_month($now);
	if ($outturnStart > $fyEnd) {
		return [
			'from' => $outturnStart->format('Y-m-d'),
			'to'   => $fyEnd->format('Y-m-d'),
			'total'=> 0.0,
			'by_department' => [],
		];
	}

	$niBands = outturn_load_ni_bands($pdo);
	$overrides = outturn_load_overrides($pdo, $ref);
	$hist = outturn_weighted_history_from_actuals($pdo, $ref, $now, $actualMonthsBack);
	
	// Pay rises (resource-level), grouped by RESOURCE_REF
	$t_pay_rises = "{$ref}_pay_rises";
	$payRisesByResource = [];
	
	try {
		$stmt = $pdo->prepare("
			SELECT
				RESOURCE_REF,
				EFFECTIVE_DATE,
				RISE_KIND,
				VALUE
			FROM {$t_pay_rises}
			ORDER BY EFFECTIVE_DATE ASC, REF ASC
		");
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
		foreach ($rows as $row) {
			$k = (string)$row['RESOURCE_REF'];
			if (!isset($payRisesByResource[$k])) $payRisesByResource[$k] = [];
			$payRisesByResource[$k][] = $row;
		}
	} catch (Throwable $e) {
		// If table not present for this company yet, just behave as "no rises"
		$payRisesByResource = [];
	}
	
	// Dept names
	$depRows = $pdo->query("SELECT REF, DEPARTMENT FROM {$t_deps}")->fetchAll(PDO::FETCH_ASSOC);
	$depName = [];
	foreach ($depRows as $d) $depName[(int)$d['REF']] = (string)$d['DEPARTMENT'];

	// Load resources
	$t_details = "{$ref}_details";
	
	// Load resources (salary + dates come from *_details)
	$resRows = $pdo->query("
		SELECT
			r.REF,
			r.DEPARTMENT,
			r.CONTRACT_TYPE,
			d.START_DATE,
			d.END_DATE,
			d.ANNUAL_SALARY,
			d.PENSION,
			d.FTE
		FROM {$t_resources} r
		LEFT JOIN {$t_details} d ON d.EMP_KEY = r.REF
	")->fetchAll(PDO::FETCH_ASSOC);

	// Load roles (unallocated only)
	$roleRows = $pdo->query("
		SELECT
			REF,
			DEPARTMENT,
			BENCHMARK_SALARY,
			START_DATE,
			END_DATE,
			CONTRACT_TYPE,
			STATUS,
			FILLED_REFERENCE
		FROM {$t_roles}
		WHERE (FILLED_REFERENCE IS NULL OR FILLED_REFERENCE = 0)
	")->fetchAll(PDO::FETCH_ASSOC);

	$byDept = []; // [deptRef] => total
	$total = 0.0;
	
	$debugEmp1 = []; // ⚠️ monthKey => detail breakdown for Emp 1

	// helper to iterate month-firsts
	$monthFirst = $outturnStart;
	while ($monthFirst <= $fyEnd) {
		$monthKey = $monthFirst->format('Y-m');

		// -------- resources --------
		foreach ($resRows as $r) {
			$emp = (int)$r['REF'];
			$dept= (int)$r['DEPARTMENT'];
			$contractType = (int)$r['CONTRACT_TYPE'];

			$startRaw = (string)($r['START_DATE'] ?? '');
			$start = $startRaw
				? new DateTimeImmutable($startRaw . ' 00:00:00')
				: $fyStart; // if missing, assume started at FY start (so it doesn't zero everything)
			
			$endRaw = (string)($r['END_DATE'] ?? '');
			$end = ($endRaw && $endRaw !== '0000-00-00')
				? new DateTimeImmutable($endRaw . ' 23:59:59')
				: new DateTimeImmutable('2999-12-31 23:59:59');
			
			$pct = outturn_percentage_worked_in_month($start, $end, $monthFirst);
			if ($pct <= 0) continue;
			
			$annualBase = (float)($r['ANNUAL_SALARY'] ?? 0);
			
			// Apply scheduled rises effective on/before this month
			$risesForResource = $payRisesByResource[(string)$emp] ?? [];
			$annualForMonth = outturn_annual_at_month($annualBase, $risesForResource, $monthFirst);
			
			if ($emp === 1) {
					$debugEmp1[$monthKey] = [
							'contract_type'   => $contractType,
							'start'           => $start->format('Y-m-d'),
							'end'             => $end->format('Y-m-d'),
							'pct'             => $pct,
							'annual_base'     => $annualBase,
							'annual_for_month'=> $annualForMonth,
							// we’ll fill the cost components after we compute them
					];
			} // ⚠️
			
			if ($emp === 1 && $monthKey === '2026-02') {
					// TEMP DEBUG
					// error_log('hist emp=1: ' . json_encode($hist[$emp] ?? []));
			}
			
			$base = round(($annualForMonth / 12.0) * $pct, 2);

			// history-derived elements (monthly averages)
			$overtime   = round((($hist[$emp][2] ?? 0.0) * $pct), 2);
			$onCall     = round((($hist[$emp][3] ?? 0.0) * $pct), 2);
			$bonus      = round((($hist[$emp][4] ?? 0.0) * $pct), 2);
			$other      = round((($hist[$emp][5] ?? 0.0) * $pct), 2);
			$welfare    = round((($hist[$emp][6] ?? 0.0) * $pct), 2);
			$pensionBase= ($contractType === 3) ? 0.0 : round(0.04 * ($base + $overtime + $onCall + $bonus + (($hist[$emp][10] ?? 0.0) * $pct)), 2);
			$statPay    = round((($hist[$emp][8] ?? 0.0) * $pct), 2);
			$commission = round((($hist[$emp][10] ?? 0.0) * $pct), 2);

			// Apply overrides for this resource+month (TYPE ids)
			$ov = $overrides['resource'][$emp][$monthKey] ?? null;
			if ($ov) {
				if (isset($ov[1]))  $base = (float)$ov[1];
				if (isset($ov[2]))  $overtime = (float)$ov[2];
				if (isset($ov[3]))  $onCall = (float)$ov[3];
				if (isset($ov[4]))  $bonus = (float)$ov[4];
				if (isset($ov[5]))  $other = (float)$ov[5];
				if (isset($ov[6]))  $welfare = (float)$ov[6];
				if (isset($ov[7]))  $pensionBase = (float)$ov[7];
				if (isset($ov[8]))  $statPay = (float)$ov[8];
				if (isset($ov[10])) $commission = (float)$ov[10];
				// NI override (9) is handled after recompute below
			}

			$incomeForNI = $base + $overtime + $onCall + $bonus + $other + $commission;
			$ersNI = ($contractType === 3) ? 0.0 : outturn_employers_ni($niBands, $incomeForNI, $monthFirst);

			// Allow NI override after recompute
			if ($ov && isset($ov[9])) $ersNI = (float)$ov[9];

			// Recompute totals
			$monthTotal = $incomeForNI + $welfare + $pensionBase + $statPay + $ersNI;
			
			if ($emp === 1) {
					$debugEmp1[$monthKey] += [
							'base'        => $base,
							'overtime'    => $overtime,
							'oncall'      => $onCall,
							'bonus'       => $bonus,
							'other'       => $other,
							'commission'  => $commission,
							'welfare'     => $welfare,
							'pension'     => $pensionBase,
							'statpay'     => $statPay,
							'ers_ni'      => $ersNI,
							'month_total' => $monthTotal,
					];
			} // ⚠️
			
			$total += $monthTotal;
			$byDept[$dept] = ($byDept[$dept] ?? 0.0) + $monthTotal;
		}

		// -------- roles (unallocated) --------
		foreach ($roleRows as $ro) {
			$emp = (int)$ro['REF'];
			$dept= (int)$ro['DEPARTMENT'];
			$contractType = (int)$ro['CONTRACT_TYPE'];

			$startRaw = (string)($ro['START_DATE'] ?? '');
			$start = ($startRaw && $startRaw !== '0000-00-00')
				? new DateTimeImmutable($startRaw . ' 00:00:00')
				: $fyStart; // if missing, assume started at FY start
			$endRaw = (string)($ro['END_DATE'] ?? '');
			$end = ($endRaw && $endRaw !== '0000-00-00')
				? new DateTimeImmutable($endRaw . ' 23:59:59')
				: new DateTimeImmutable('2999-12-31 23:59:59');

			$pct = outturn_percentage_worked_in_month($start, $end, $monthFirst);
			if ($pct <= 0) continue;

			$annual = (float)$ro['BENCHMARK_SALARY'];
			$base = round(($annual / 12.0) * $pct, 2);

			$overtime   = round((($hist[$emp][2] ?? 0.0) * $pct), 2);
			$onCall     = round((($hist[$emp][3] ?? 0.0) * $pct), 2);
			$bonus      = round((($hist[$emp][4] ?? 0.0) * $pct), 2);
			$other      = round((($hist[$emp][5] ?? 0.0) * $pct), 2);
			$welfare    = round((($hist[$emp][6] ?? 0.0) * $pct), 2);
			$statPay    = round((($hist[$emp][8] ?? 0.0) * $pct), 2);
			$commission = round((($hist[$emp][10] ?? 0.0) * $pct), 2);

			$pensionBase= ($contractType === 3) ? 0.0 : round(0.04 * ($base + $overtime + $onCall + $bonus + $commission), 2);

			$ov = $overrides['role'][$emp][$monthKey] ?? null;
			if ($ov) {
				if (isset($ov[1]))  $base = (float)$ov[1];
				if (isset($ov[2]))  $overtime = (float)$ov[2];
				if (isset($ov[3]))  $onCall = (float)$ov[3];
				if (isset($ov[4]))  $bonus = (float)$ov[4];
				if (isset($ov[5]))  $other = (float)$ov[5];
				if (isset($ov[6]))  $welfare = (float)$ov[6];
				if (isset($ov[7]))  $pensionBase = (float)$ov[7];
				if (isset($ov[8]))  $statPay = (float)$ov[8];
				if (isset($ov[10])) $commission = (float)$ov[10];
			}

			$incomeForNI = $base + $overtime + $onCall + $bonus + $other + $commission;
			$ersNI = ($contractType === 3) ? 0.0 : outturn_employers_ni($niBands, $incomeForNI, $monthFirst);
			if ($ov && isset($ov[9])) $ersNI = (float)$ov[9];

			$monthTotal = $incomeForNI + $welfare + $pensionBase + $statPay + $ersNI;

			$total += $monthTotal;
			$byDept[$dept] = ($byDept[$dept] ?? 0.0) + $monthTotal;
		}

		$monthFirst = $monthFirst->modify('+1 month');
	}

	// Format response
	$deptOut = [];
	foreach ($byDept as $dref => $val) {
		$dref = (int)$dref;
		$deptOut[] = [
			'dept_ref' => $dref,
			'dept_name'=> $depName[$dref] ?? 'Unallocated',
			'total'    => round((float)$val, 2),
		];
	}
	usort($deptOut, fn($a,$b) => ($b['total'] <=> $a['total']));

	return [
		'from' => $outturnStart->format('Y-m-d'),
		'to'   => $fyEnd->format('Y-m-d'),
		'total'=> round($total, 2),
		'by_department' => $deptOut,
		'assumptions' => [
			'weighted_history_months' => $actualMonthsBack,
			'pension_rate_default' => 0.04,
			'contractor_contract_type_id' => 3,
			'notes' => [
				"Pay rises applied for resources via {$ref}_pay_rises (roles not yet).",
				'Weighted history uses average monthly actuals over last closed months.',
			],
		],
		'_debug_emp1' => $debugEmp1,
	];
}