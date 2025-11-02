<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

try {
  $in = json_decode(file_get_contents('php://input'), true) ?? [];
  $id = isset($in['id']) ? (int)$in['id'] : 0;

  $user = checkUser();
  $company = (int)getUsersCompanyId($user);
  $table = sprintf('`%d_pay_rises`', $company);

  $stmt = $pdo->prepare("UPDATE $table SET APPLIED_FLAG = 1 WHERE REF = :id LIMIT 1");
  $stmt->execute([':id' => $id]);

  echo json_encode(['status' => 'success', 'updated' => $stmt->rowCount()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'detail' => $e->getMessage()]);
}