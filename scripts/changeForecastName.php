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
	$newName = isset($json['newName']) ? trim((string)$json['newName']): '';

	if ($mix === '' || $name === '' || $version <= 0 || $newName === '') {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Missing or invalid fields.']);
		exit;
	}
	if (mb_strlen($newName) > 100) {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'New name is too long (max 100 chars).']);
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

	// 0) Ensure the source forecast exists (rows for this window)
	$check = $pdo->prepare("
		SELECT COUNT(*) AS cnt
		FROM $table
		WHERE ACTUAL_FORECAST = :mix
		  AND FORECAST_NAME   = :name
		  AND FORECAST_VERSION= :version
		LIMIT 1
	");
	$check->execute([':mix'=>$mix, ':name'=>$name, ':version'=>$version]);
	if ((int)$check->fetchColumn() === 0) {
		$pdo->rollBack();
		http_response_code(404);
		echo json_encode(['status'=>'error','message'=>'Source forecast not found.']);
		exit;
	}

	// 1) Determine the target version for (mix, newName)
	//    Use MAX(version)+1, or 1 if none exist. Lock relevant rows to avoid races.
	$qMax = $pdo->prepare("
		SELECT COALESCE(MAX(FORECAST_VERSION), 0)
		FROM $table
		WHERE ACTUAL_FORECAST = :mix AND FORECAST_NAME = :newName
		FOR UPDATE
	");
	$qMax->execute([':mix'=>$mix, ':newName'=>$newName]);
	$maxVer = (int)$qMax->fetchColumn();
	$newVersion = ($maxVer > 0) ? ($maxVer + 1) : 1;

	// 2) Move the selected forecast window to the new name + newVersion
	$move = $pdo->prepare("
		UPDATE $table
		   SET FORECAST_NAME = :newName,
			   FORECAST_VERSION = :newVersion
		 WHERE ACTUAL_FORECAST = :mix
		   AND FORECAST_NAME   = :name
		   AND FORECAST_VERSION= :version
	");
	$move->execute([
		':newName'    => $newName,
		':newVersion' => $newVersion,
		':mix'        => $mix,
		':name'       => $name,
		':version'    => $version
	]);
	$movedRows = $move->rowCount();

	// 3) Compact the old name’s versions: any version > removed version shifts down by 1
	//    (Safe because we already moved our rows to a different name/version.)
	$compact = $pdo->prepare("
		UPDATE $table
		   SET FORECAST_VERSION = FORECAST_VERSION - 1
		 WHERE ACTUAL_FORECAST = :mix
		   AND FORECAST_NAME   = :name
		   AND FORECAST_VERSION > :version
	");
	$compact->execute([
		':mix'     => $mix,
		':name'    => $name,
		':version' => $version
	]);
	$compactedRows = $compact->rowCount();

	$pdo->commit();

	echo json_encode([
		'status'         => 'success',
		'movedRows'      => $movedRows,
		'compactedRows'  => $compactedRows,
		'old'            => ['mix'=>$mix, 'name'=>$name, 'version'=>$version],
		'new'            => ['mix'=>$mix, 'name'=>$newName, 'version'=>$newVersion]
	], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
	if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
	http_response_code(500);
	echo json_encode(['status'=>'error','message'=>'Server error','detail'=>$e->getMessage()]);
}