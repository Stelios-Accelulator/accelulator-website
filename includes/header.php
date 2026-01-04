<?php
// start output buffering:
ob_start();

// initialise a session:
session_start();

// REMOVE this (causes fatal if /libs/PhpSpreadsheet/… is missing):
// require_once __DIR__ . '/../libs/PhpSpreadsheet/autoloader.php';

// KEEP this (it chooses vendor first, then falls back to libs safely):
require_once __DIR__ . '/../includes/loadPhpSpreadsheet.php';

?>
<!DOCTYPE HTML>
<html>
<head>
	<meta charset='utf-8'>
	<meta name='viewport' content='width=device-width, initial-scale=1'>
	
	<!-- Set the CSS Styles for the site -->
	<style>@import url('https://fonts.googleapis.com/css2?family=Codystar:wght@300;400&family=Figtree:ital,wght@0,300..900;1,300..900&family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&family=Public+Sans:ital,wght@0,100..900;1,100..900&family=Quicksand:wght@300..700&display=swap');</style>
	<link rel="stylesheet" href="/stylesheets/original_stylesheet.css">
	
	<!-- Import the jQuery libraries -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js"></script>
<!--	<script src="https://ajax.googleapis.com/ajax/libs/jquerymobile/1.4.5/jquery.mobile.min.js"></script> -->
	<!-- Import dompurify to clean user input -->
	<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.3/dist/purify.min.js"></script>
	
	<?php // Start the PHP scripts
	require_once __DIR__ . '/../includes/functions.php';
	?>
	
	<script src="/includes/javascript_functions.js"></script>
	<script src="/includes/peopleForecast.js"></script>
	<script src="/includes/register.js"></script>
	
	<?php
	$randstr = substr(md5(rand()),0,7);
	
	if(isset($_POST['status'])){ // TEST TO UNDERSTAND WHERE THE USER HAS COME FROM
		$status = sanitizeString($_POST['status']);
		
		divertUserForm($status);
	}
	
	$loggedIn = FALSE;
	
	checkIfUserIsSignedIn($loggedIn);
	
	?>
	<title>Accelulator</title>
	
	<!-- Apple Add to Homepage icon and settings -->
	<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes"> <!-- optional legacy -->
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
	<meta name="apple-mobile-web-app-title" content="Accelulator">
	<!-- ======================================= -->
	
	<script>
		window.csrfToken = "<?php echo generateCsrfToken(); ?>";
		const csrfToken = "<?php echo generateCsrfToken(); ?>";
	</script>
	<meta name="csrf-token" content="<?php echo getCsrfToken(); ?>">
</head>
<body>
	
	<ul class="navigation" id="navigation">
		<li id="homeIcon"><a href="https://accelulator.com/"><img src="/assets/accelulator_home_icon_newyear.png" alt="Home" height="25px" width="25px" /><span class="accelulatorBranding">Accelulator</span></a></li>
		<li id="goHome"><a href="https://accelulator.com/">Home</a></li>
		<li id='aboutLink'><a href='https://accelulator.com/pages/about.php'>About</a></li>
		<li id='staffCastLink'><a href='https://accelulator.com/pages/staffCast.php'>StaffCast</a></li>
		<li id='articlesLink'><a href='https://accelulator.com/pages/articles.php'>Articles</a></li>
		<li id='logInLink'><a href='#'>Sign In</a></li>
		<li id='registerLink'><a href='#'>Register</a></li>
		<li id='logOutLink'><a href='#'>Sign Out</a></li>
		<script>
			var userLoggedIn = getCookie('signedIn');
			if(userLoggedIn == null || userLoggedIn == 0){ // If the user is not logged in
				$('#logOutLink').hide(); // hide the logout link
				$('#aboutLink').show(); // show the About link
				$('#staffCastLink').hide(); // show the StaffCast link
				$('#logInLink').show(); // show the log in link
				$('#registerLink').show(); //show the register link
			}else {
				$('#aboutLink').hide(); // hide the About link
				$('#staffCastLink').hide(); // hide the StaffCast link
				$('#logInLink').hide(); // hide the log in link
				$('#registerLink').hide(); // hide the register link
				$('#logOutLink').show(); // show the log out link
			}
		</script>
	</ul>
	
	<div id="contentView">
