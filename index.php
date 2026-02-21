<?php
$sessionLifetime = getenv('ACCELULATOR_SESSION_LIFETIME') ?: 14400; // fallback to 4h if unset

ini_set('session.gc_maxlifetime', $sessionLifetime);
ini_set('session.cookie_lifetime', $sessionLifetime);

require_once('./includes/header.php');

$accessLevel = $_SESSION['userAccess'];

$email = $_GET['email'] ?? '';
$email = sanitizeString($email);

$isLoggedIn = !empty($_SESSION['user']); // session-only truth

if ($isLoggedIn && $email === '') {

	switch ((int)$accessLevel) {
	
		// 0,3,4,5,6,7,8,9 → Current Position
		case 0:
		case 3:
		case 4:
		case 5:
		case 6:
		case 7:
		case 8:
		case 9:
		case 10:
			$landing = '/modules/currentPosition.php';
			break;
	
		// 1 → Import Actuals
		case 1:
			$landing = '/pages/uploadFileForm.php';
			break;
	
		// 2 → Company Settings
		case 2:
			$landing = '/pages/companySettings.php';
			break;
	
		// Explicit default
		default:
			$landing = '/main/home.php';
			break;
	}
	
	header("Location: {$landing}");
	exit;
} else if ($email !== '') {

	$email = filter_var($email, FILTER_SANITIZE_EMAIL);
	$email_js = json_encode($email); // safe JS string escaping

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
	echo "<script>$('#contentView').load('/main/home.php?inject=1')</script>";
}

require_once('./includes/footer.php');
?>