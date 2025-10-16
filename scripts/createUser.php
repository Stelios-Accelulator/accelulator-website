<?php
// scripts/createUser.php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';   // your DB + session + csrf bootstrap
// validate CSRF header here if you have a helper, e.g. requireValidCsrf();

global $pdo;

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email     = trim(strtolower($input['email'] ?? ''));
$firstName = trim($input['firstName'] ?? '');
$surname   = trim($input['surname'] ?? '');
$accessRef = intval($input['accessRef'] ?? 0);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $firstName === '' || $surname === '' || $accessRef <= 0) {
  echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
  exit;
}

try {
	$pdo->beginTransaction();

	$STEP = 'users.duplicate_check';
	// 1) ensure email not taken
	$stmt = $pdo->prepare('SELECT REF FROM users WHERE EMAIL = ? LIMIT 1');
	$stmt->execute([$email]);
	if ($stmt->fetchColumn()) {
		$pdo->rollBack();
		echo json_encode(['status' => 'error', 'step' => $STEP, 'message' => 'Email already exists']);
		exit;
	}

	$STEP = 'users.insert';
	// 2) create users row with a temporary unusable password
	$tmpHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
	$stmt = $pdo->prepare('INSERT INTO users (EMAIL, PASSWORD) VALUES (?, ?)');
	$stmt->execute([$email, $tmpHash]);
	$userId = (int)$pdo->lastInsertId();

	$STEP = 'company.resolve';
	// 3) resolve company ref (you already have helpers)
	$user = checkUser();
	$companyRef = getUsersCompanyId($user);
	if (!$companyRef) {
		throw new RuntimeException('Unable to resolve companyRef for current user');
	}

	$STEP = 'user_details.insert';
	$stmt = $pdo->prepare('INSERT INTO user_details (USERNAME, FIRSTNAME, SURNAME, COMPANY_ID, LINKED_COMPANY, USER_ID)
						   VALUES (?, ?, ?, ?, ?, ?)');
	$stmt->execute([$email, $firstName, $surname, $companyRef, $companyRef, $userId]);

	$STEP = 'user_access.insert';
	// 4) user_access (active now; paid_until end of this month; ACCESS_LEVEL from $accessRef)
	$endOfMonth = (new DateTime('last day of this month 23:59:59', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	$stmt = $pdo->prepare('INSERT INTO user_access (USERREF, ACCESS_LEVEL, ACTIVE, PAID_UNTIL)
						   VALUES (?, ?, 1, ?)');
	$stmt->execute([$userId, $accessRef, $endOfMonth]);

	$STEP = 'password_resets.insert';
	// 5) create invite token for password set
	$token   = bin2hex(random_bytes(32));          // raw token for the link
	$tokenHash = hash('sha256', $token);           // store hash if your column is token_hash
	$expires = (new DateTime('+48 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

	$stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, used)
						   VALUES (?, ?, ?, 0)');
	$stmt->execute([$userId, $tokenHash, $expires]);

	$pdo->commit();

	// On success, also pass back token so the caller can craft the email link
	// Build invite link and send the email (do not roll back user creation if mailing fails)
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$host   = $_SERVER['HTTP_HOST'] ?? 'accelulator.com';
	
	// Adjust the path to your password-set page
	$inviteLink = $scheme.'://'.$host.'/pages/set-password.php?t='.urlencode($token);
	
	// Compose email
	$subject = 'Set up your Accelulator access';
	$htmlBody = '
	  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;line-height:1.4">
		<p>Hi '.htmlspecialchars($firstName ?: 'there').',</p>
		<p>You have been invited to Accelulator. Click the button below to set your password and activate your account.</p>
		<p style="margin:24px 0">
		  <a href="'.htmlspecialchars($inviteLink).'"
			 style="background:#1f7a8c;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;display:inline-block">
			 Set your password
		  </a>
		</p>
		<p>If the button doesn’t work, copy and paste this link:</p>
		<p style="word-break:break-all;color:#444">'.htmlspecialchars($inviteLink).'</p>
		<p style="color:#666;font-size:12px">This link will expire in 48 hours for security.</p>
	  </div>
	';
	
	$emailSent = false;
	try {
		// Use your helper (or your project’s mailer if you have one)
		$emailSent = sendHtmlMail($email, $subject, $htmlBody);
	} catch (Throwable $mailErr) {
		error_log('[createUser][email] '.$mailErr->getMessage());
	}
	
	echo json_encode(['status' => 'success', 'emailSent' => $emailSent, 'token' => $token]);
} catch (Throwable $e) {
	if ($pdo->inTransaction()) $pdo->rollBack();
	// $STEP will be whatever label we last set
	error_log('[createUser]['.$STEP.'] '.$e->getMessage().' (code '.$e->getCode().')');
	echo json_encode([
		'status'  => 'error',
		'step'    => isset($STEP) ? $STEP : 'unknown',
		'message' => 'Server error'
	]);
}