<?php
// accelulator.com/modules/currentPostion.php

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

require_once __DIR__ . '/../includes/functions.php';

$user = checkUser();
$ref  = (int)getUsersCompanyId($user);

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

	$actualsYTD = sum_actuals_people_cost($pdo, $ref, $fyStart, $actualsEnd);

	$forecastFY = sum_forecast_people_cost($pdo, $ref, $fs, $fyMonths, $payElements, true);
	$forecastFuture = sum_forecast_people_cost($pdo, $ref, $fs, $futureMonths, $payElements, false);

	$projectionFY = $actualsYTD + $forecastFuture;
	$variance = $projectionFY - $forecastFY;

	// dept breakdown
	$deptActuals = dept_actuals_ytd($pdo, $ref, $fyStart, $actualsEnd);
	$deptFcFY  = dept_forecast($pdo, $ref, $fs, $fyMonths, $payElements, true);
	$deptFcFut = dept_forecast($pdo, $ref, $fs, $futureMonths, $payElements, false);

	$deptKeys = array_unique(array_merge(array_keys($deptActuals), array_keys($deptFcFY), array_keys($deptFcFut)));
	$deptRows = [];

	foreach ($deptKeys as $k) {
		$name = $deptFcFY[$k]['dept_name'] ?? $deptActuals[$k]['dept_name'] ?? $deptFcFut[$k]['dept_name'] ?? 'Unallocated';
		$act  = $deptActuals[$k]['total'] ?? 0.0;
		$fcFY = $deptFcFY[$k]['total'] ?? 0.0;
		$fcFu = $deptFcFut[$k]['total'] ?? 0.0;

		$proj = $act + $fcFu;
		$var  = $proj - $fcFY;

		$deptRows[] = [
			'dept_ref' => (int)$k,
			'dept_name'=> $name,
			'actuals_ytd' => $act,
			'forecast_fy' => $fcFY,
			'projection_fy'=> $proj,
			'variance' => $var,
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
	
</style>

<div class="cp-wrap" data-forecastfy="<?= (float)$forecastFY ?>">
	<div class="cp-title">CURRENT POSITION</div>
	<div class="cp-forecast"><?=h($forecastLabel)?></div>
	<div class="cp-sub">Last updated: <?=h($fs['LAST_UPDATED'])?> · FY: <?=h($fyLabel)?></div>

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
			<div class="value"><?=money0($forecastFY)?></div>
		</div>
		<div class="cp-card">
			<div class="label">Projection (full FY)</div>
			<div class="value" id="cpProjectionValue"><?=money0($projectionFY)?></div>
		</div>
	</div>

	<div class="cp-line"></div>

	<div class="cp-section">Composition (click to inspect)</div>

	<button class="cp-row" type="button" data-toggle="composition">
		<span class="lhs">
			<span class="name">YTD actuals</span>
			<span class="meta"><?=h($ytdLabel)?></span>
		</span>
		<span class="rhs"><?=money0($actualsYTD)?></span>
	</button>

	<button class="cp-row" type="button" data-toggle="composition">
		<span class="lhs">
			<span class="name">Future (from forecast)</span>
			<span class="meta"><?=h($futLabel)?></span>
		</span>
		<span class="rhs" id="cpFutureValue"><?=money0($forecastFuture)?></span>
	</button>

	<div id="composition" class="cp-card cp-hidden" style="margin-top:10px;">
		<div class="label">Scope</div>
		<div class="cp-note">
			Includes resources + unallocated roles.<br>
			Pay elements: Base, Overtime, On-Call, Bonus, Other, Welfare, Pension, Statutory Pay, Employers NI, Commission.
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
			} elseif ($abs <= $matAction) {
				$tag = '⚠️';
				$cls = 'cp-pill cp-good'; // keep green border (matches your JS choice)
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
				data-actuals="<?= (float)$r['actuals_ytd'] ?>"
				data-forecastfy="<?= (float)$r['forecast_fy'] ?>"
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
	function money0(n){
		const v = Math.round(Number(n) || 0);
		return '£' + v.toLocaleString();
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

		// 3) Departments
		const deptFutureMap = new Map();
		if (Array.isArray(data.by_department)) {
			data.by_department.forEach(d => {
				const k = Number(d.dept_ref);
				if (!Number.isNaN(k)) deptFutureMap.set(k, Number(d.total) || 0);
			});
		}

		document.querySelectorAll('button.cp-row[data-deptref]').forEach(btn => {
			const deptRef = Number(btn.getAttribute('data-deptref'));
			const act = Number(btn.getAttribute('data-actuals')) || 0;
			const fcFY = Number(btn.getAttribute('data-forecastfy')) || 0;

			const fut = deptFutureMap.get(deptRef) || 0; // outturn future for this dept
			const proj = act + fut;
			const vari = proj - fcFY;

			// RHS projection
			const rhs = document.getElementById('deptProj_' + deptRef);
			if (rhs) rhs.textContent = money0(proj);

			// Variance pill (top line)
			const pill = document.getElementById('deptVarPill_' + deptRef);
			if (pill) {
				const abs = Math.abs(vari);
				
				let tag;
				let cls;
				if (abs <= matOk) { tag = '✅'; cls = 'cp-good'; }
				else if (abs <= matAction) { tag = '⚠️'; cls = 'cp-good'; }  // keep green border if you want “watch but ok-ish”
				else { tag = '❌'; cls = 'cp-bad'; }
				
				pill.classList.remove('cp-good','cp-bad');
				pill.classList.add(cls);
				pill.textContent = tag + ' ' + money0(abs) + (vari < 0 ? ' under' : ' over');
			}

			// Breakdown values
			const bProj = document.getElementById('deptProjB_' + deptRef);
			if (bProj) bProj.textContent = money0(proj);

			const bVar = document.getElementById('deptVarB_' + deptRef);
			if (bVar) bVar.textContent = (vari <= 0) ? (money0(Math.abs(vari)) + " under") : (money0(vari) + " over");
		});
	}

	run().catch(err => {
		console.warn('[getFutureOutturn] failed', err);
	});
})();
</script>