<?
// start output buffering:
ob_start();

// initialise a session:
session_start();
?>
<!DOCTYPE HTML>
<html>
<head>
	<meta charset='utf-8'>
	<meta name='viewport' content='width=device-width, initial-scale=1'>
	
	<!-- Set the CSS Styles for the site -->
	<style>@import url('https://fonts.googleapis.com/css2?family=Codystar:wght@300;400&family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&family=Public+Sans:ital,wght@0,100..900;1,100..900&family=Quicksand:wght@300..700&display=swap');</style>
	<link rel="stylesheet" href="/stylesheets/original_stylesheet.css">
	
	<!-- Import the jQuery libraries -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js"></script>
<!--	<script src="https://ajax.googleapis.com/ajax/libs/jquerymobile/1.4.5/jquery.mobile.min.js"></script> -->
	
	<?php // Start the PHP scripts
	require_once('functions.php');
	include_once('javascript_functions.js');
	
	$randstr = substr(md5(rand()),0,7);
	
	if(isset($_POST['status'])){ // TEST TO UNDERSTAND WHERE THE USER HAS COME FROM
		$status = sanitizeString($_POST['status']);
		
		divertUserForm($status);
	}
	
	$loggedIn = FALSE;
	
	checkIfUserIsSignedIn($loggedIn);
	
	?>
	<title>Accelulator</title>
</head>
<body>
	<ul class="navigation" id="navigation">
		<li id='goHomeLink'><span id='goHome'><a href='#'>Home</a></span></li>
		<li id='logInLink'><span id='logIn'><a href='#'>Sign In</a></span></li>
		<li id='registerLink'><span id='register'><a href='#'>Register</a></span></li>
		<li id='logOutLink'><span id='logOut'><a href='#'>Sign Out</a></span></li>
		<script>
			var userLoggedIn = getCookie('user');
			if(userLoggedIn == null){
				$('#logOutLink').hide();
			}else {
				$('#logInLink').hide();
				$('#registerLink').hide();
			}
		</script>
	</ul>
	<div id="contentView">
