<?php
	
	// find out if the login.php content has been injected into the page
	$isInject = isset($_GET['inject']) && $_GET['inject'] == '1';
	
	// Optional: also respect the common AJAX header if you add it in JS
	if (!$isInject && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
		$isInject = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
	}
	
	if(!$isInject) {
		require_once __DIR__ . '/header.php';
	}
?>

<script>
	document.addEventListener('DOMContentLoaded', function () {
		
		
		// Try the fetch, but don't let it break anything if it fails
		try {
			fetch("./scripts/destroySession.php").catch(() => {});
		} catch(_) {}
		
	});
	
</script>

<div class="auth-wrap">
  <section class="auth-card">
	<div class="auth-header">
	  <!-- <img class="logo" src="/images/icon.svg" alt=""> -->
	  <h1 class="auth-title">Sign in to your account</h1>
	  <p class="auth-subtitle">Access StaffCast and your finance tools securely.</p>
	</div>

	<div class="auth-meta">
	  Your credentials are encrypted and never shared. Need an account?
	  <a href="#" id="loginRegister">Register</a>.
	</div>

	<form id="loginForm" method="post">
	  <div class="form-field">
		<label for="user">Email address</label>
		<input class="form-input" type="email" id="user" name="user" placeholder="name@company.com" required>
	  </div>

	  <div class="form-field password-wrap">
		<label for="pass">Password</label>
		<input class="form-input" type="password" id="pass" name="pass" placeholder="Enter your password" required>
		<button type="button" class="password-toggle" onclick="togglePassword()">Show</button>
	  </div>

	  <div class="form-row">
		<span></span>
		<a href="/includes/forgot-password.php">Forgot password?</a>
	  </div>

	  <button class="btn-auth" name="signInButton">Sign In</button>
	  <input type='hidden' name='status' value='signin'>
	  <p class="auth-links">Don’t have an account?
		<a href="#" id="loginRegister2">Register here</a>
	  </p>
	</form>
  </section>
</div>

<?php
	if(!$isInject) {
		require_once __DIR__ . '/footer.php';
	}
?>