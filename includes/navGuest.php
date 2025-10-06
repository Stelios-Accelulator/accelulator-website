<script>
$(document).ready(function(){
	$("#goHome").click(function(){
		$("#contentView").load("/main/home.php")
	});
	
	$("#logIn").click(function(){
		$("#navigation").load("./includes/navGuest.php")
		$("#contentView").load("/includes/login.php")
	});
	
	$("#register").click(function(){
		$("#navigation").load("./includes/navGuest.php")
		$("#contentView").load("/includes/register.php")
	});
});
</script>

<li><span id='goHome'><a href='#'>Home</a></span></li>
<li><span id='logIn'><a href='#'>Sign In</a></span></li>
<li><span id='register'><a href='#'>Register</a></span></li>