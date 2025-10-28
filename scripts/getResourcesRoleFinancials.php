<?php
// /scripts/getResourcesRoleFinancials.php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json');

session_start();
require_once('../includes/functions.php');
require_once('../includes/crypto.php');

// --- Always send JSON, even on fatals ---
$__json_payload = ['status' => 'error', 'message' => 'Unknown error'];
register_shutdown_function(function () {
	$err = error_get_last();
	if ($err !== null && !headers_sent()) {
		http_response_code(500);
		echo json_encode([
			'status'  => 'error',
			'message' => '[shutdown] ' . $err['message'],
			'file'    => $err['file'],
			'line'    => $err['line']
		]);
	} else {
		// flush any buffered output (normal path)
		$out = ob_get_contents();
		if ($out !== false) {
			// If something wrote non-JSON, wrap it
			@json_decode($out);
			if (json_last_error() !== JSON_ERROR_NONE) {
				http_response_code(500);
				echo json_encode(['status' => 'error', 'message' => 'Non-JSON output', 'raw' => $out]);
			} else {
				// already JSON
				// do nothing; it was echoed explicitly
			}
		}
	}
	ob_end_flush();
});

try {
	// CSRF + auth (return JSON on failure)
	validateCsrfToken();
	$user = checkUser();
	if (!$user) {
		http_response_code(401);
		echo json_encode(['status' => 'error', 'message' => 'User not authenticated']);
		return;
	}

	// Company ref
	$ref = getUsersCompanyId($user);

	// Input
	$rawData = file_get_contents('php://input') ?: '{}';
	$data    = json_decode($rawData, true);
	if (!is_array($data)) $data = [];

	// Department: allow "All", "", 0, or numeric
	$departmentSelector = $data['department'] ?? 0;
	$hasDeptFilter = false;
	$deptParam     = null;
	if (is_numeric($departmentSelector)) {
		$deptParam = (int)$departmentSelector;
		$hasDeptFilter = ($deptParam !== 0);
	} elseif (is_string($departmentSelector)) {
		$val = trim(strtolower($departmentSelector));
		if ($val !== '' && $val !== 'all') {
			// if someone passed e.g. "3"
			if (ctype_digit($departmentSelector)) {
				$deptParam = (int)$departmentSelector;
				$hasDeptFilter = ($deptParam !== 0);
			}
		}
	}

	// Tables
	$table_resources      = $ref . '_resources';
	$table_details        = $ref . '_details';
	$table_actuals        = $ref . '_actuals';
	$table_roles          = $ref . '_roles';
	$table_departments    = $ref . '_departments';
	$table_forecasts      = $ref . '_forecasts';
	$table_outturn        = $ref . '_outturn';
	$table_paytype_group  = $ref . '_paytype_group';
	$table_paytype        = $ref . '_paytype';

	// Encryption-aware name projection
	$nameSel = res_name_select_sql($pdo, $table_resources, 'r');
	$canView = can_view_names($user);

	$response = [];

	// ---------- 1) RESOURCES ----------
	$sql = "
		SELECT 
			r.REF AS RES_REF,
			$nameSel,
			d.START_DATE,
			d.END_DATE,
			d.ANNUAL_SALARY,
			d.FTE,
			d.PENSION,
			r.DEPARTMENT,
			r.CONTRACT_TYPE
		FROM $table_resources r
		LEFT JOIN $table_details d ON r.REF = d.EMP_KEY
		" . ($hasDeptFilter ? "WHERE r.DEPARTMENT = :dept" : "");
	$stmt = $pdo->prepare($sql);
	if ($hasDeptFilter) $stmt->bindValue(':dept', $deptParam, PDO::PARAM_INT);
	$stmt->execute();
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$resources = [];
	foreach ($rows as $row) {
		[$first, $middle, $last] = res_name_from_row($row, $canView);
		$resources[] = [
			'RES_REF'       => (int)$row['RES_REF'],
			'FIRSTNAME'     => $first,
			'SURNAME'       => $last,
			'START_DATE'    => $row['START_DATE'],
			'END_DATE'      => $row['END_DATE'],
			'ANNUAL_SALARY' => $row['ANNUAL_SALARY'],
			'FTE'           => $row['FTE'],
			'PENSION'       => $row['PENSION'],
			'DEPARTMENT'    => $row['DEPARTMENT'],
			'CONTRACT_TYPE' => $row['CONTRACT_TYPE'],
		];
	}
	$response['resources'] = $resources;

	// ---------- 2) ACTUALS (TYPE = group value) ----------
	$sql = "
		SELECT 
			a.EMP_KEY, 
			a.DATE, 
			g.VALUE AS TYPE, 
			a.VALUE
		FROM $table_actuals a
		LEFT JOIN $table_paytype p ON a.TYPE = p.REF
		LEFT JOIN $table_paytype_group g ON p.PAYTYPE_GROUP_REF = g.REF
		" . ($hasDeptFilter ? "LEFT JOIN $table_resources r ON a.EMP_KEY = r.REF WHERE r.DEPARTMENT = :dept" : "");
	$stmt = $pdo->prepare($sql);
	if ($hasDeptFilter) $stmt->bindValue(':dept', $deptParam, PDO::PARAM_INT);
	$stmt->execute();
	$response['actuals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// ---------- 3) ROLES ----------
	$sql = "SELECT * FROM $table_roles " . ($hasDeptFilter ? "WHERE DEPARTMENT = :dept" : "");
	$stmt = $pdo->prepare($sql);
	if ($hasDeptFilter) $stmt->bindValue(':dept', $deptParam, PDO::PARAM_INT);
	$stmt->execute();
	$response['roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// ---------- 4) DEPARTMENTS ----------
	$stmt = $pdo->query("SELECT REF, DEPARTMENT FROM $table_departments");
	$response['departments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// ---------- 5) FORECASTS ----------
	$stmt = $pdo->query("
		SELECT ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
		FROM $table_forecasts
		GROUP BY ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
	");
	$response['forecasts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// ---------- 6) OUTTURNS ----------
	$stmt = $pdo->query("SELECT * FROM $table_outturn");
	$outturnsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$outturns = [];
	$typeLookup = $pdo->prepare("SELECT VALUE FROM $table_paytype_group WHERE REF = :type LIMIT 1");
	foreach ($outturnsRaw as $row) {
		$typeLookup->execute([':type' => $row['TYPE']]);
		$typeRow = $typeLookup->fetch(PDO::FETCH_ASSOC);
		$outturns[] = [
			'library' => $row['RES_ROL'] === 'resource' ? 'lib_resources' : 'roles',
			'ref'     => $row['EMP_KEY'],
			'date'    => dateToMMM_YY($row['DATE']),
			'type'    => $typeRow['VALUE'] ?? $row['TYPE'],
			'value'   => $row['VALUE'],
		];
	}
	$response['outturns'] = $outturns;

	// ---------- 7) NI BANDS ----------
	$stmt = $pdo->query("
		SELECT 
			DATE_FORMAT(FROM_DATE, '%Y-%m-%d') AS FROM_DATE,
			DATE_FORMAT(TO_DATE, '%Y-%m-%d')   AS TO_DATE,
			SECONDARY_THRESHOLD_MONTHLY, 
			RATE 
		FROM ni_employers_rates
		ORDER BY FROM_DATE ASC
	");
	$response['niBands'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

	echo json_encode(['status' => 'success'] + $response);

} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode([
		'status'  => 'error',
		'message' => $e->getMessage(),
		'where'   => basename(__FILE__),
		'trace'   => (isset($_GET['debug']) ? $e->getTraceAsString() : null),
	]);
}