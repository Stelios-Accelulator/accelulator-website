<?php
// /api/people_costs.php
session_start();
require_once('../includes/functions.php');
header('Content-Type: application/json');

// Optional auth
$user = checkUser();
if ($user === '') {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorised']);
  exit;
}

// Read JSON payload
$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!$in) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON']);
  exit;
}

/*
Expected payload:
{
  "actuals":   {"Jul-25":12345, ...},
  "outturn":   {"Jul-25":12345, ...},
  "forecast":  {"Jul-25":12345, ...},
  "selectedMonth": "Jul-25",
  "monthsBack": 3,
  "monthsForward": 8
}
*/

$actuals       = $in['actuals']   ?? [];
$outturn       = $in['outturn']   ?? [];
$forecast      = $in['forecast']  ?? [];
$selectedMonth = $in['selectedMonth'] ?? null;
$monthsBack    = isset($in['monthsBack']) ? (int)$in['monthsBack'] : 3;
$monthsFwd     = isset($in['monthsForward']) ? (int)$in['monthsForward'] : 8;

if (!$selectedMonth) {
  http_response_code(400);
  echo json_encode(['error' => 'selectedMonth is required (e.g. "Jul-25")']);
  exit;
}

function monToDate(string $mon): DateTime {
  // Accepts "Jul-25"
  $dt = DateTime::createFromFormat('M-y|', $mon, new DateTimeZone('Europe/London'));
  if (!$dt) $dt = DateTime::createFromFormat('M-y', $mon, new DateTimeZone('Europe/London'));
  if (!$dt) throw new Exception("Bad month key: $mon");
  // Normalise to first of month 00:00
  $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), 1)->setTime(0,0,0);
  return $dt;
}
function dateToMon(DateTime $d): string { return $d->format('M-y'); }

// Compute range around selectedMonth
try {
  $sel = monToDate($selectedMonth);
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
  exit;
}
$start = (clone $sel)->modify("-{$monthsBack} months");
$end   = (clone $sel)->modify("+{$monthsFwd} months");

// Build label sequence
$labels = [];
$cursor = clone $start;
while ($cursor <= $end) {
  $labels[] = dateToMon($cursor);
  $cursor->modify('+1 month');
}

// Define the “actuals cutoff” = last fully complete month (relative to server time Europe/London)
$now = new DateTime('now', new DateTimeZone('Europe/London'));
$cutoff = (clone $now)->modify('first day of this month')->modify('-1 month'); // e.g., if today is Aug-12-2025 => cutoff = Jul-01-2025
$cutoffKey = dateToMon($cutoff);

// Compose bar series & flags
$barValues = [];
$isActual  = []; // parallel to labels; true = actual month, false = outturn month
$forecastValues = [];

foreach ($labels as $m) {
  $a = isset($actuals[$m])  ? (float)$actuals[$m]  : 0.0;
  $o = isset($outturn[$m])  ? (float)$outturn[$m]  : 0.0;
  $f = isset($forecast[$m]) ? (float)$forecast[$m] : 0.0;

  // Actual up to and including cutoff month; Outturn after that
  // Compare month-order by dates
  $mDate = monToDate($m);
  $isAct = ($mDate <= $cutoff);
  $barValues[] = $isAct ? $a : $o;
  $isActual[]  = $isAct;
  $forecastValues[] = $f;
}

echo json_encode([
  'labels'   => $labels,
  'bar'      => $barValues,     // single bar series (actuals OR outturn by month)
  'isActual' => $isActual,      // parallel flags to colour per-point
  'forecast' => $forecastValues,
  'cutoff'   => $cutoffKey       // useful for UI copy/legend
]);