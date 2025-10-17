<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$token = $_POST['token'] ?? '';
$pw1   = $_POST['pw1'] ?? '';
$pw2   = $_POST['pw2'] ?? '';
$email = $_POST['email'] ?? '';

if (!$token || $pw1 === '' || $pw2 === '' || $pw1 !== $pw2) {
  exit('Invalid request.');
}

try {
  // look up token
  $tokenHash = hash('sha256', $token);
  $stmt = $pdo->prepare('SELECT user_id, expires_at, used FROM password_resets WHERE token_hash = ? LIMIT 1');
  $stmt->execute([$tokenHash]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) exit('Invalid or expired link.');
  if ((int)$row['used'] === 1) exit('This link has already been used.');
  if (new DateTime($row['expires_at'], new DateTimeZone('UTC')) < new DateTime('now', new DateTimeZone('UTC'))) {
	exit('Link expired.');
  }
  
  $hash = password_hash($pw1, PASSWORD_DEFAULT);
  
  $pdo->beginTransaction();
  $stmt = $pdo->prepare('UPDATE users SET PASSWORD = ? WHERE REF = ?');
  $stmt->execute([$hash, (int)$row['user_id']]);
  
  $stmt = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token_hash = ?');
  $stmt->execute([$tokenHash]);
  
  $pdo->commit();
  
  echo 'Password set. You can now sign in <a href="https://accelulator.com/?email='.$email.'">here</a>.';
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[completeInvite] ' . $e->getMessage());
  exit('Server error.');
}