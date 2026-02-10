<?php
$sessionLifetime = getenv('ACCELULATOR_SESSION_LIFETIME') ?: 14400; // fallback to 4h if unset

// Configure session timeout and cookie lifetime
ini_set('session.gc_maxlifetime', $sessionLifetime);
ini_set('session.cookie_lifetime', $sessionLifetime);
require_once('./includes/header.php');
$email = $_GET['email'] ?? ''; // If the user has been sent here by the password reset function, save the email
$email = sanitizeString($email); // Strip special characters to protect again injection

if(isset($_SESSION['user']) && $_COOKIE['signedIn']==1 && $email == ''){ // Check if the user is set
	echo "<script>$('#contentView').load('/main/dashboard.php?inject=1')</script>";
}else if ($email!='') {
	// Sanitize for email and safely embed into JS
	$email = filter_var($email, FILTER_SANITIZE_EMAIL);
	$email_js = json_encode($email); // proper JS string escaping
	
	echo "<script>
	(function () {
		function loadLogin() {
			$('#contentView').load('/includes/login.php?inject=1', function () {
				var u = document.getElementById('user');
				if (u) { u.value = $email_js; }
				var p = document.getElementById('pass');
				if (p) p.focus();
			});
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', loadLogin);
		} else {
			loadLogin();
		}
	})();
	</script>";
} else {
	echo "<script>$('#contentView').load('/main/home.php')</script>";
};

require_once('./includes/footer.php');
?>