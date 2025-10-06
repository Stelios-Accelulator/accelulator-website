<?php
session_start();
// CSRF for the reset form
if (empty($_SESSION['csrf_reset'])) { $_SESSION['csrf_reset'] = bin2hex(random_bytes(16)); }

$token = $_GET['token'] ?? '';
$validFormat = (is_string($token) && ctype_xdigit($token) && strlen($token) === 64);
if (!$validFormat) { http_response_code(400); echo "Invalid token."; exit; }
?>
<div class="auth-wrap">
  <section class="auth-card">
	<div class="auth-header">
	  <h1 class="auth-title">Choose a new password</h1>
	  <p class="auth-subtitle">Use a strong password you haven’t used before.</p>
	</div>

	<form method="post" action="/scripts/auth_finish_password_reset.php" id="resetForm" autocomplete="off">
	  <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
	  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_reset'], ENT_QUOTES); ?>">

	  <div class="form-field">
		<label for="new_password">New password</label>
		<input class="form-input" type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password">
	  </div>

	  <div class="form-field">
		<label for="confirm_password">Confirm new password</label>
		<input class="form-input" type="password" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password">
	  </div>

	  <button class="btn-auth" type="submit">Update password</button>
	  <p class="auth-links"><a href="/includes/login.php">Back to sign in</a></p>
	</form>
  </section>
</div>