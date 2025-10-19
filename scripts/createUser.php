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
	$inviteLink = $scheme.'://'.$host.'/pages/set-password.php?t='.urlencode($token).'&email='.$email;
	
	// Compose email
	$subject = 'Set up your Accelulator access';
	$htmlBody = '
	  <!DOCTYPE html>
	  <html lang="en">
	  <head>
	  <meta charset="UTF-8">
	  <meta name="viewport" content="width=device-width, initial-scale=1.0">
	  <title>Set up your Accelulator access</title>
	  <style>
		body {
		  margin: 0;
		  padding: 0;
		  background-color: #fafafa;
		  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
		  color: #333;
		}
		.email-container {
		  max-width: 560px;
		  margin: 40px auto;
		  background: #ffffff;
		  border-radius: 12px;
		  box-shadow: 0 2px 6px rgba(0,0,0,0.08);
		  overflow: hidden;
		}
		.header {
		  background-color: #ffffff;
		  text-align: center;
		  padding: 30px 0 10px 0;
		}
		.header img {
		  width: 72px;
		  height: 72px;
		}
		.content {
		  padding: 0 40px 30px 40px;
		}
		h1 {
		  font-size: 20px;
		  color: #a62317;
		  margin-bottom: 16px;
		  text-align: center;
		}
		p {
		  font-size: 15px;
		  line-height: 1.6;
		  margin: 10px 0;
		}
		.button {
		  display: inline-block;
		  background-color: #2d6f7d;
		  color: #ffffff;
		  padding: 12px 28px;
		  margin: 25px 0 20px;
		  border-radius: 6px;
		  text-decoration: none;
		  font-weight: 600;
		  letter-spacing: 0.3px;
		}
		.footer {
		  font-size: 12px;
		  color: #888;
		  text-align: center;
		  padding: 20px 40px 30px;
		}
	  </style>
	  </head>
	  <body>
		<div class="email-container">
		  <div class="header">
			<img src="https://accelulator.com/assets/accelulator_home_icon.png" alt="Accelulator logo">
		  </div>
		  <div class="content">
			<h1>Set up your Accelulator access</h1>
			<p>Hi ' . htmlspecialchars($firstName) . ',</p>
			<p>You’ve been invited to <strong>Accelulator</strong>. Click the button below to set your password and activate your account.</p>
			<div style="text-align: center;">
			  <a href="' . $inviteLink . '" class="button">Set your password</a>
			</div>
			<p>If the button doesn’t work, copy and paste this link into your browser:</p>
			<p style="word-break: break-all; color: #555; font-size: 13px;">' . htmlspecialchars($inviteLink) . '</p>
		  </div>
		  <div class="footer">
			This link will expire in 48 hours for security.<br>
			&copy; ' . date('Y') . ' Accelulator Ltd. All rights reserved.
		  </div>
		</div>
	  </body>
	  </html>
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