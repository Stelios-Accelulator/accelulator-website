<?php
// /scripts/getFutureOutturn.php
declare(strict_types=1);

// if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/outturn_engine.php';

$user = checkUser();
$ref  = (int)getUsersCompanyId($user);

header('Content-Type: application/json; charset=utf-8');

if (!isset($pdo) || !($pdo instanceof PDO)) {
	http_response_code(500);
	echo json_encode(['error' => 'PDO not available; ensure functions.php initialises global $pdo']);
	exit;
}

try {
	$now = new DateTimeImmutable('now');

	// You can pass ?months=7 to mimic aMonths cookie default
	$months = isset($_GET['months']) && ctype_digit((string)$_GET['months']) ? (int)$_GET['months'] : 7;
	$months = max(1, min(24, $months));

	$data = calculate_future_outturn($pdo, $ref, $now, $months);
	if ($DEBUG) {
		$t_resources = "{$ref}_resources";
		$t_details   = "{$ref}_details";
		$t_roles     = "{$ref}_roles";
	
		$sampleResources = $pdo->query("
			SELECT r.REF, r.DEPARTMENT, r.CONTRACT_TYPE, d.START_DATE, d.END_DATE, d.ANNUAL_SALARY
			FROM {$t_resources} r
			LEFT JOIN {$t_details} d ON d.EMP_KEY = r.REF
			ORDER BY r.REF ASC
			LIMIT 10
		")->fetchAll(PDO::FETCH_ASSOC);
	
		$sampleRoles = $pdo->query("
			SELECT REF, DEPARTMENT, CONTRACT_TYPE, START_DATE, END_DATE, BENCHMARK_SALARY, FILLED_REFERENCE, STATUS
			FROM {$t_roles}
			ORDER BY REF ASC
			LIMIT 10
		")->fetchAll(PDO::FETCH_ASSOC);
	
		$data['_debug'] = [
			'ref' => $ref,
			'now' => $now->format('Y-m-d H:i:s'),
			'resources_count' => (int)$pdo->query("SELECT COUNT(*) FROM {$t_resources}")->fetchColumn(),
			'details_count'   => (int)$pdo->query("SELECT COUNT(*) FROM {$t_details}")->fetchColumn(),
			'roles_count'     => (int)$pdo->query("SELECT COUNT(*) FROM {$t_roles}")->fetchColumn(),
			'sample_resources' => $sampleResources,
			'sample_roles'     => $sampleRoles,
		];
	}

	echo json_encode($data);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode([
		'error' => $e->getMessage(),
		'file'  => $DEBUG ? $e->getFile() : null,
		'line'  => $DEBUG ? $e->getLine() : null,
	]);
}