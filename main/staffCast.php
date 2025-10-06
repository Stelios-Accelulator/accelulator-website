<?php
require_once('../includes/header.php');

if(isset($_SESSION['user']) && $_COOKIE['signedIn']==1){ // Check if the user is set
	echo '<script>fadeLoadContent("contentView","/pages/staffCastApp.php",200,500);</script>';
}else{
	echo '<script>fadeLoadContent("contentView","/main/home.php",200,500);</script>';
};

require_once('./includes/footer.php');
?>