<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

try {
	$in  = json_decode(file_get_contents('php://input'), true) ?? [];
	$ref = $in['ref'] ?? null;

	// basic validation
	$resourceRef   = isset($in['resourceRef']) ? (int)$in['resourceRef'] : 0;
	$effectiveDate = $in['effectiveDate'] ?? null;     // expected 'YYYY-MM-01'
	$kind          = $in['kind'] ?? null;              // 'PCT' | 'ABS' | 'NEW'
	$value         = isset($in['value']) ? (float)$in['value'] : null;
	$note          = $in['note'] ?? null;
	$applied       = (int)!!($in['applied'] ?? 0);

	if (!$resourceRef || !$effectiveDate || !$kind || $value === null) {
		http_response_code(422);
		echo json_encode(['status' => 'error', 'error' => 'Missing required fields']);
		exit;
	}

	// (optional) clamp ridiculous dates to 1st-of-month
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
		http_response_code(422);
		echo json_encode(['status' => 'error', 'error' => 'Bad date format']);
		exit;
	}

	$user    = checkUser();
	$company = getUsersCompanyId($user);
	$table   = "{$company}_pay_rises";

	// Build SQL + params that actually match the branch
	if ($ref) {
		$sql = "
			UPDATE $table SET
				RESOURCE_REF   = :res,
				EFFECTIVE_DATE = :dt,
				RISE_KIND      = :kind,
				VALUE          = :val,
				NOTE           = :note,
				APPLIED_FLAG   = :applied
			WHERE REF = :ref
		";
		$params = [
			':ref'     => (int)$ref,
			':res'     => $resourceRef,
			':dt'      => $effectiveDate,
			':kind'    => $kind,
			':val'     => $value,
			':note'    => $note,
			':applied' => $applied,
		];
	} else {
		$sql = "
			INSERT INTO $table (
				RESOURCE_REF, EFFECTIVE_DATE, RISE_KIND, VALUE, NOTE, APPLIED_FLAG
			) VALUES (
				:res, :dt, :kind, :val, :note, :applied
			)
		";
		$params = [
			':res'     => $resourceRef,
			':dt'      => $effectiveDate,
			':kind'    => $kind,
			':val'     => $value,
			':note'    => $note,
			':applied' => $applied,
		];
	}

	// use the shared $pdo from functions.php
	global $pdo;
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);

	// return the new/updated row id so UI can refresh precisely if desired
	$id = $ref ?: (int)$pdo->lastInsertId();

	echo json_encode(['status' => 'success', 'id' => $id]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}