<? // Register
session_start();
$error = $user = $pass = "";

if(isset($_SESSION['user'])){
	destroySession();
}
?>

<h1>Register</h1>
<form method='post'>
	<div data-role='fieldcontain'>
		<label></label>
		<input type='hidden' name='status' value='register'>
		Please enter your details to register an account
	</div>
	<div data-role='fieldcontain'>
		<label>Email</label>
		<input type='text' maxlength='50' name='user' value='<? $user ?>'>
	</div>
	<div data-role='fieldcontain'>
		<label>Password</label>
		<input type='password' maxlength='50' name='pass' value='<? $pass ?>'>
	</div>
	<div data-role='fieldcontain'>
		<label></label>
		<input data-transition='slide' type='submit' value='Register'>
	</div>
</form>