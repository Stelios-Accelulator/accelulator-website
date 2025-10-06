<?php
session_start();
// optional: a CSRF token
if (empty($_SESSION['csrf_fp'])) { $_SESSION['csrf_fp'] = bin2hex(random_bytes(16)); }

require_once('./header.php');

?>
<div class="auth-wrap">
  <section class="auth-card">
	<div class="auth-header">
	  <h1 class="auth-title">Forgot your password?</h1>
	  <p class="auth-subtitle">Enter your email and, if it matches an account, we’ll send a reset link.</p>
	</div>

	<form id="forgotForm" method="post" action="/scripts/auth_start_password_reset.php">
	  <div class="form-field">
		<label for="fp_email">Email address</label>
		<input class="form-input" type="email" id="fp_email" name="email" required placeholder="name@company.com" autocomplete="email">
	  </div>

	  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_fp'], ENT_QUOTES); ?>">
	  <button class="btn-auth" type="submit">Send reset link</button>

	  <p class="auth-links"><a href="/includes/login.php">Back to sign in</a></p>
	</form>
  </section>
</div>
<?php
	require_once('./footer.php');
?>