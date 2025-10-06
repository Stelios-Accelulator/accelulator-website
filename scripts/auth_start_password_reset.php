<?php
session_start();
require_once('../includes/functions.php'); // must define $pdo
// Basic CSRF check
if (!isset($_POST['csrf']) || !isset($_SESSION['csrf_fp']) || !hash_equals($_SESSION['csrf_fp'], $_POST['csrf'])) {
  header('Location: /includes/forgot-password.php'); exit;
}
unset($_SESSION['csrf_fp']);

$email = strtolower(trim($_POST['email'] ?? ''));
$redirect = '/includes/forgot-password-sent.php';
if ($email === '') { header("Location: $redirect"); exit; }

// Look up user (adjust table/fields if needed)
$stmt = $pdo->prepare("SELECT REF, EMAIL FROM users WHERE LOWER(EMAIL) = :email LIMIT 1");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Always proceed generically, but only create token if user exists
if ($user) {
  $rawToken  = bin2hex(random_bytes(32));                 // 64 hex chars
  $tokenHash = hash('sha256', $rawToken, true);           // binary
  $expiresAt = (new DateTime('+60 minutes'))->format('Y-m-d H:i:s');

  $ins = $pdo->prepare("
	INSERT INTO password_resets (user_id, token_hash, expires_at, request_ip, user_agent)
	VALUES (:uid, :th, :exp, :ip, :ua)
  ");
  $ins->execute([
	':uid' => $user['REF'],
	':th'  => $tokenHash,
	':exp' => $expiresAt,
	':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
	':ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 255)
  ]);

  // Build absolute https link
  $scheme = 'https://';
  $host   = $_SERVER['HTTP_HOST'] ?? 'accelulator.com';
  $link   = $scheme . $host . '/includes/reset-password.php?token=' . urlencode($rawToken);

  // Send email — swap to PHPMailer / SMTP when ready
  $subject = 'Reset your Accelulator password';
  $body    = "Hi,\n\nWe received a request to reset your password. Use the link below within 60 minutes:\n\n$link\n\nIf you didn’t request this, you can ignore this email.\n\n— Accelulator";
  @mail($user['EMAIL'], $subject, $body, "From: Accelulator <contact@accelulator.com>\r\n");
}

header("Location: $redirect");
exit;