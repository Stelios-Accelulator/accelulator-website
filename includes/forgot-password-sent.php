<?php
	require_once('./header.php');
?>

<div class="auth-wrap">
  <section class="auth-card">
	<div class="form-alert" role="status">
	  <div>ℹ️</div>
	  <div>If an account exists for that email, a reset link has been sent. The link will expire in about 60 minutes.</div>
	</div>
	<p class="auth-links"><a href="/includes/login.php">Back to sign in</a></p>
  </section>
</div>

<?php
	require_once('./footer.php');
?>