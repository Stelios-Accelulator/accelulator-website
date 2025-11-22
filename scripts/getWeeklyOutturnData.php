<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$user = checkUser();
$companyRef = getUsersCompanyId($user);

// read JSON body from weeklyOutturn.js
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$year = isset($body['year']) ? (int)$body['year'] : (int)date('Y');
// you are also posting dep, but we can ignore it for now or add it to the query later

if (!is_int($companyRef)) {
	echo json_encode([
		'ok'    => false,
		'error' => 'Missing company reference'
	]);
	exit;
}

try {
	// your existing function: [weekNum => value]
	$weeklyTotals = getWeeklyTotalsFromMonthly($companyRef, $year);

	$weeks = [];
	foreach ($weeklyTotals as $weekNum => $value) {
		$weeks[] = [
			'week'     => (int)$weekNum,
			'label'    => 'W' . (int)$weekNum,
			'start'    => null,          // you can fill these later from the week calendar
			'end'      => null,
			'actual'   => (float)$value, // for now treat this as “actual”
			'forecast' => 0.0
		];
	}

	echo json_encode([
		'ok'    => true,
		'weeks' => $weeks
	]);
} catch (Throwable $e) {
	error_log('[getWeeklyOutturnData] ' . $e->getMessage());
	echo json_encode([
		'ok'    => false,
		'error' => 'Failed to build weekly outturn'
	]);
}