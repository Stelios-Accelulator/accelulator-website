<?php
declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../includes/functions.php');
validateCsrfToken();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

try {
  $userEmailOrId = checkUser();
  $companyRef = getUsersCompanyId($userEmailOrId);

  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException('BAD_JSON');

  $userRef     = (int)($data['userRef'] ?? 0);
  $active      = (int)($data['active']  ?? -1);
  $accessLevel = $data['accessLevel'] ?? null;                 // <-- NEW
  if ($userRef <= 0 || ($active !== 0 && $active !== 1)) throw new RuntimeException('BAD_PARAMS');

  // Ensure the user belongs to this company
  $stmt = $pdo->prepare("SELECT ud.LINKED_COMPANY FROM user_details ud WHERE ud.USER_ID = :userRef");
  $stmt->execute([':userRef' => $userRef]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || (string)$row['LINKED_COMPANY'] !== (string)$companyRef) throw new RuntimeException('FORBIDDEN');

  // If accessLevel not provided, read current one (safer UPDATE)
  if ($accessLevel === null) {
	$stmt = $pdo->prepare("SELECT ACCESS_LEVEL FROM user_access WHERE USERREF = :userRef LIMIT 1");
	$stmt->execute([':userRef' => $userRef]);
	$al = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$al) throw new RuntimeException('MISSING_ROW');
	$accessLevel = $al['ACCESS_LEVEL'];
  }

  // Flip ACTIVE only for this user+role
  $stmt = $pdo->prepare("UPDATE user_access SET ACTIVE = :active WHERE USERREF = :userRef AND ACCESS_LEVEL = :al");
  $stmt->execute([':active' => $active, ':userRef' => $userRef, ':al' => $accessLevel]);

  if ($stmt->rowCount() < 1) {
	throw new RuntimeException('NO_UPDATE'); // Nothing matched / changed
  }

  echo json_encode(['status'=>'success']);
  exit;
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]); // <-- return detail
  exit;
}