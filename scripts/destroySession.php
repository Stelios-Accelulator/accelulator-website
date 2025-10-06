<?php
session_start();
require_once('../includes/functions.php');

// Unset all session variables
$_SESSION = [];

// If session uses cookies, remove the session cookie
if (ini_get("session.use_cookies")) {
	$params = session_get_cookie_params();
	setcookie(
		session_name(),
		'',
		time() - 42000,
		$params["path"],
		$params["domain"],
		$params["secure"],
		$params["httponly"]
	);
}

// Finally, destroy the session
session_destroy();

// List of cookies to destroy
$cookies = [
	'user',
	'signedIn',
	'aMonths',
	'contractType',
	'department',
	'endDate',
	'forecastName',
	'forecastPrefix',
	'forecastVersion',
	'oMonths'
];

// Destroy each of the cookies
foreach ($cookies as $cookie) {
	if (isset($_COOKIE[$cookie])) {
		setcookie($cookie, '', time() - 3600, '/');
	}
}

// Redirect to home or login page
header("Location: ../index.php");
exit();
?>