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

$userRef    = (int)($_SESSION['userRef'] ?? 0);
$userAccess = (int)($_SESSION['userAccess'] ?? 0);
$companyId  = (int)($_SESSION['userCompany'] ?? 0);

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
		if ($d > 0) $out[] = $d;
	}
	return array_values(array_unique($out));
}

function filter_future_outturn_payload(array $data, array $allowedDeptRefs): array {
	$allowed = array_flip(array_map('intval', $allowedDeptRefs));

	// Filter department rows
	if (isset($data['by_department']) && is_array($data['by_department'])) {
		$data['by_department'] = array_values(array_filter(
			$data['by_department'],
			function ($row) use ($allowed) {
				if (!is_array($row)) return false;
				$d = (int)($row['dept_ref'] ?? 0);
				if ($d <= 0) return false; // always exclude unallocated/invalid
				return isset($allowed[$d]);
			}
		));
	}

	// Recalculate totals from filtered rows
	$total = 0.0;
	$split = ['opex' => 0.0, 'capex' => 0.0, 'except' => 0.0];

	foreach ($data['by_department'] ?? [] as $row) {
		$total += (float)($row['total'] ?? 0);

		$rSplit = $row['split'] ?? [];
		$split['opex']   += (float)($rSplit['opex'] ?? 0);
		$split['capex']  += (float)($rSplit['capex'] ?? 0);
		$split['except'] += (float)($rSplit['except'] ?? 0);
	}

	$data['total'] = $total;
	$data['split'] = $split;

	// Optional: avoid leaking other depts in debug samples (recommended)
	if (isset($data['_debug']['sample_resources']) && is_array($data['_debug']['sample_resources'])) {
		$data['_debug']['sample_resources'] = array_values(array_filter(
			$data['_debug']['sample_resources'],
			fn($r) => isset($allowed[(int)($r['DEPARTMENT'] ?? 0)])
		));
	}
	if (isset($data['_debug']['sample_roles']) && is_array($data['_debug']['sample_roles'])) {
		$data['_debug']['sample_roles'] = array_values(array_filter(
			$data['_debug']['sample_roles'],
			fn($r) => isset($allowed[(int)($r['DEPARTMENT'] ?? 0)])
		));
	}

	return $data;
}

$allowedDeptRefs = $isDeptRestricted ? get_allowed_departments($pdo, $companyId, $userRef) : [];
if ($isDeptRestricted && count($allowedDeptRefs) === 0) {
	http_response_code(403);
	echo json_encode([
		'status' => 'error',
		'message' => 'Department access not configured. Contact your administrator.'
	]);
	exit;
}

try {
	$now = new DateTimeImmutable('now');

	// You can pass ?months=7 to mimic aMonths cookie default
	$months = isset($_GET['months']) && ctype_digit((string)$_GET['months']) ? (int)$_GET['months'] : 7;
	$months = max(1, min(24, $months));

	$data = calculate_future_outturn($pdo, $ref, $now, $months);
	
	if ($isDeptRestricted) {
		$data = filter_future_outturn_payload($data, $allowedDeptRefs);
	}
	
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
	
	ini_set('serialize_precision', '-1');
	ini_set('precision', '14');
	
	echo json_encode($data);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode([
		'error' => $e->getMessage(),
		'file'  => $DEBUG ? $e->getFile() : null,
		'line'  => $DEBUG ? $e->getLine() : null,
	]);
}