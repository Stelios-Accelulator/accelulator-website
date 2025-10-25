<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
$user = checkUser();
if (!$user) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'Not logged in']); exit; }

validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);
$mix = $data['mix'] ?? '';
$name = $data['name'] ?? '';
$version = (int)($data['version'] ?? 0);
$published = (int)($data['published'] ?? 0);

$ref = getUsersCompanyId($user);
$table = $ref . '_forecasts';

$stmt = $pdo->prepare("UPDATE $table 
					   SET IS_PUBLISHED = :published
					   WHERE ACTUAL_FORECAST = :mix
						 AND FORECAST_NAME = :name
						 AND FORECAST_VERSION = :version");
$stmt->execute([':published'=>$published, ':mix'=>$mix, ':name'=>$name, ':version'=>$version]);

echo json_encode(['status'=>'success', 'rows'=>$stmt->rowCount()]);