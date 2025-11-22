<?php
require_once __DIR__ . '/../../includes/functions.php';
header('Content-Type: application/json');

// However you track the current company prefix:
$companyRef = (int)$_SESSION['company_ref']; // e.g. 0, 1, 2
$year       = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

try {
	$weeklyTotals = getWeeklyTotalsFromMonthly($companyRef, $year);

	$data = [];
	foreach ($weeklyTotals as $weekNum => $value) {
		$data[] = [
			'week'  => $weekNum,
			'value' => $value,
		];
	}

	echo json_encode([
		'status' => 'success',
		'year'   => $year,
		'data'   => $data,
	]);
} catch (Throwable $e) {
	echo json_encode([
		'status'  => 'error',
		'message' => $e->getMessage(),
	]);
}