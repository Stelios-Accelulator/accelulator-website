<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php'; // gives you $pdo and your helpers
if (session_status() === PHP_SESSION_NONE) session_start();

try {
	// ── Auth ────────────────────────────────────────────────────────────────────
	$user = checkUser();
	if ($user === '' || $user === null) {
		http_response_code(401);
		echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
		exit;
	}

	// ── CSRF ───────────────────────────────────────────────────────────────────
	validateCsrfToken();

	// ── Input ──────────────────────────────────────────────────────────────────
	$raw = file_get_contents('php://input');
	$json = json_decode($raw, true);

	$mix      = isset($json['mix'])      ? trim((string)$json['mix'])      : '';
	$name     = isset($json['name'])     ? trim((string)$json['name'])     : '';
	$version  = isset($json['version'])  ? (int)$json['version']           : 0;
	$newName  = isset($json['newName'])  ? trim((string)$json['newName'])  : '';

	if ($mix === '' || $name === '' || $version <= 0 || $newName === '') {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Missing or invalid fields.']);
		exit;
	}
	if (mb_strlen($newName) > 100) { // keep names sane
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'New name is too long (max 100 chars).']);
		exit;
	}

	// ── Company-scoped table ───────────────────────────────────────────────────
	$ref = getUsersCompanyId($user);
	if (!$ref) {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Company not found for user.']);
		exit;
	}
	$table_forecast = $ref . '_forecasts';

	// ── Do the rename (all rows for that forecast window) ──────────────────────
	$pdo->beginTransaction();

	$stmt = $pdo->prepare("
		UPDATE $table_forecast
		   SET FORECAST_NAME = :newName
		 WHERE ACTUAL_FORECAST  = :mix
		   AND FORECAST_NAME    = :name
		   AND FORECAST_VERSION = :version
	");
	$stmt->execute([
		':newName' => $newName,
		':mix'     => $mix,
		':name'    => $name,
		':version' => $version
	]);

	$rowCount = $stmt->rowCount();
	$pdo->commit();

	if ($rowCount === 0) {
		// Nothing matched (already renamed or wrong key)
		echo json_encode([
			'status'  => 'noop',
			'message' => 'No rows updated; check mix/name/version.',
			'updated' => 0
		]);
		exit;
	}

	echo json_encode([
		'status'  => 'success',
		'updated' => $rowCount,
		'mix'     => $mix,
		'name'    => $name,
		'version' => $version,
		'newName' => $newName
	], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
	if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
	http_response_code(500);
	echo json_encode(['status' => 'error', 'message' => 'Server error', 'detail' => $e->getMessage()]);
}