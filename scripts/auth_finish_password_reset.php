<?php
session_start();
require_once('../includes/functions.php');

// CSRF
if (!isset($_POST['csrf']) || !isset($_SESSION['csrf_reset']) || !hash_equals($_SESSION['csrf_reset'], $_POST['csrf'])) {
  http_response_code(400); echo "Invalid request."; exit;
}
unset($_SESSION['csrf_reset']);

$token = $_POST['token'] ?? '';
$pass1 = $_POST['new_password'] ?? '';
$pass2 = $_POST['confirm_password'] ?? '';

if (!$token || !ctype_xdigit($token) || strlen($token) !== 64) {
  http_response_code(400); echo "Invalid token format."; exit;
}
if ($pass1 === '' || $pass1 !== $pass2) {
  http_response_code(400); echo "Passwords do not match."; exit;
}
if (strlen($pass1) < 10) {
  http_response_code(400); echo "Password too short."; exit;
}

$tokenHash = hash('sha256', $token, true);

// Find matching reset
$stmt = $pdo->prepare("
  SELECT pr.id, pr.user_id, pr.expires_at, pr.used
  FROM password_resets pr
  WHERE pr.token_hash = :th
  LIMIT 1
");
$stmt->execute([':th' => $tokenHash]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rec) { http_response_code(400); echo "Invalid or expired token."; exit; }
if ((int)$rec['used'] === 1) { http_response_code(400); echo "Token already used."; exit; }
if (new DateTime() > new DateTime($rec['expires_at'])) { http_response_code(400); echo "Token expired."; exit; }

$hash = password_hash($pass1, PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
  $up1 = $pdo->prepare("UPDATE users SET PASSWORD = :ph WHERE REF = :uid");
  $up1->execute([':ph' => $hash, ':uid' => $rec['user_id']]);

  $up2 = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = :id");
  $up2->execute([':id' => $rec['id']]);

  // Optional: clear any other outstanding tokens
  $pdo->prepare("DELETE FROM password_resets WHERE user_id = :uid AND used = 0")->execute([':uid' => $rec['user_id']]);

  $pdo->commit();
} catch(Exception $e){
  $pdo->rollBack();
  http_response_code(500); echo "Could not update password."; exit;
}

// Option: flash message; for now, send query param
header('Location: /includes/login.php?reset=success');
exit;