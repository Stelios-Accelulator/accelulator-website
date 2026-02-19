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
<style>
	/* Dropdown container */
	.navDropdown {
		position: relative;
	}
	
	/* Menu hidden by default */
	.navDropdownMenu {
		display: none;
		position: absolute;
		top: 100%;
		left: 0;
		min-width: 220px;
		background: #fff;
		border: 1px solid rgba(0,0,0,0.08);
		border-radius: 10px;
		box-shadow: 0 10px 30px rgba(0,0,0,0.08);
		padding: 8px;
		z-index: 99999;
	}
	
	/* Links inside */
	.navDropdownMenu a {
		display: block;
		padding: 10px 12px;
		border-radius: 8px;
		text-decoration: none;
		white-space: nowrap;
	}
	
	/* Hover */
	.navDropdownMenu a:hover {
		background: rgba(0,0,0,0.04);
	}
	
	/* Divider */
	.navDropdownDivider {
		height: 1px;
		margin: 8px 6px;
		background: rgba(0,0,0,0.08);
	}
	
	/* Disabled item */
	.navDropdownMenu a.disabled {
		opacity: 0.45;
		pointer-events: none;
	}
	
	/* Optional "SOON" pill */
	.pillSoon {
		font-size: 11px;
		padding: 2px 8px;
		border-radius: 999px;
		margin-left: 8px;
		border: 1px solid rgba(0,0,0,0.15);
	}
	
	/* Open state */
	.navDropdown.open .navDropdownMenu {
		display: block;
	}
	
	/* Mobile: make dropdown full-width under the nav item */
	@media (max-width: 720px) {
		.navDropdownMenu {
			left: 0;
			right: 0;
			min-width: unset;
			width: calc(100vw - 28px);
		}
	}
</style>
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
	
	<!-- Plausible Analytics -->
	<script defer data-domain="accelulator.com" src="https://plausible.io/js/script.js"></script>

	
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
		<li id="homeIcon"><a href="https://accelulator.com/"><img src="/assets/accelulator_home_icon.png" alt="Home" height="25px" width="25px" /><span class="accelulatorBranding">Accelulator</span></a></li>
		<li id="goHome"><a href="https://accelulator.com/">Home</a></li>
		<li id="modulesLink" class="navDropdown">
			<a href="#" class="navDropdownToggle" aria-haspopup="true" aria-expanded="false">
				Modules <span class="navCaret">▾</span>
			</a>
		
			<div class="navDropdownMenu" role="menu" aria-label="Modules">
				<a role="menuitem" href="https://accelulator.com/modules/currentPosition.php">Current Position</a>
				<a role="menuitem" href="https://accelulator.com/pages/staffCastApp.php">StaffCast</a>
				<a role="menuitem" href="https://accelulator.com/pages/uploadFileForm.php">Import Actuals</a>
				<a role="menuitem" href="https://accelulator.com/pages/forecastHub.php">Forecast Hub</a>
				<a role="menuitem" href="https://accelulator.com/pages/companySettings.php">Company Settings</a>
		
				<div class="navDropdownDivider"></div>
		
				<a role="menuitem" class="disabled" href="#" tabindex="-1" aria-disabled="true">
					Coming Soon <span class="pillSoon">SOON</span>
				</a>
			</div>
		</li>
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
				$('#modulesLink').hide(); // hide the modules link
				$('#aboutLink').show(); // show the About link
				$('#staffCastLink').hide(); // show the StaffCast link
				$('#logInLink').show(); // show the log in link
				$('#registerLink').show(); //show the register link
			}else {
				$('#aboutLink').hide(); // hide the About link
				$('#staffCastLink').hide(); // hide the StaffCast link
				$('#logInLink').hide(); // hide the log in link
				$('#registerLink').hide(); // hide the register link
				$('#modulesLink').show(); // hide the modules link
				$('#logOutLink').show(); // show the log out link
			}
		</script>
	</ul>
	
	<div id="contentView">
