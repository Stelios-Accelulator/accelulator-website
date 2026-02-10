<?
require_once('./includes/header.php');

if(isset($_SESSION['user'])){
	echo "<script>$('#contentView').load('/main/dashboard.php?inject=1')</script>";
}else{
	echo "<script>$('#contentView').load('/main/home.php')</script>";
};

require_once('./includes/footer.php');
?>