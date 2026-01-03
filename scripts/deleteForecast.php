<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

try {
	// ── Auth ────────────────────────────────────────────────────────────────────
	$user = checkUser();
	if (!$user) {
		http_response_code(401);
		echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
		exit;
	}

	// ── CSRF ───────────────────────────────────────────────────────────────────
	validateCsrfToken();

	// ── Input ──────────────────────────────────────────────────────────────────
	$raw  = file_get_contents('php://input');
	$json = json_decode($raw, true) ?: [];

	$mix     = isset($json['mix'])     ? trim((string)$json['mix'])    : '';
	$name    = isset($json['name'])    ? trim((string)$json['name'])   : '';
	$version = isset($json['version']) ? (int)$json['version']         : 0;

	if ($mix === '' || $name === '' || $version <= 0) {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Missing or invalid fields.']);
		exit;
	}

	// ── Company table ──────────────────────────────────────────────────────────
	$ref = getUsersCompanyId($user);
	if ($ref === null || $ref === '') {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Company not found for user.']);
		exit;
	}
	$table = $ref . '_forecasts';

	// ── Transaction ────────────────────────────────────────────────────────────
	$pdo->beginTransaction();

	// 0) Ensure the forecast exists
	$exists = $pdo->prepare("
		SELECT COUNT(*) 
		  FROM $table
		 WHERE ACTUAL_FORECAST = :mix
		   AND FORECAST_NAME   = :name
		   AND FORECAST_VERSION= :version
		LIMIT 1
	");
	$exists->execute([':mix'=>$mix, ':name'=>$name, ':version'=>$version]);
	if ((int)$exists->fetchColumn() === 0) {
		$pdo->rollBack();
		http_response_code(404);
		echo json_encode(['status'=>'error','message'=>'Forecast not found.']);
		exit;
	}

	// 1) Delete all rows for the selected forecast (all months in that window)
	$del = $pdo->prepare("
		DELETE FROM $table
		 WHERE ACTUAL_FORECAST = :mix
		   AND FORECAST_NAME   = :name
		   AND FORECAST_VERSION= :version
	");
	$del->execute([':mix'=>$mix, ':name'=>$name, ':version'=>$version]);
	$deletedRows = $del->rowCount();

	// 2) Compact: shift down versions > deleted version for the SAME (mix, name)
	$compact = $pdo->prepare("
		UPDATE $table
		   SET FORECAST_VERSION = FORECAST_VERSION - 1
		 WHERE ACTUAL_FORECAST = :mix
		   AND FORECAST_NAME   = :name
		   AND FORECAST_VERSION > :version
	");
	$compact->execute([':mix'=>$mix, ':name'=>$name, ':version'=>$version]);
	$compactedRows = $compact->rowCount();

	$pdo->commit();

	echo json_encode([
		'status'        => 'success',
		'deletedRows'   => $deletedRows,
		'compactedRows' => $compactedRows,
		'deleted'       => ['mix'=>$mix, 'name'=>$name, 'version'=>$version]
	], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
	if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
	http_response_code(500);
	echo json_encode(['status'=>'error','message'=>'Server error','detail'=>$e->getMessage()]);
}