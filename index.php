<?php
require_once('./includes/header.php');

if(isset($_SESSION['user']) && $_COOKIE['signedIn']==1){ // Check if the user is set
	echo "<script>$('#contentView').load('/main/dashboard.php')</script>";
}else{
	echo "<script>$('#contentView').load('/main/home.php')</script>";
};

require_once('./includes/footer.php');
?>