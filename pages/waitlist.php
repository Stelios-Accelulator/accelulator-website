<?
require_once('../includes/header.php');
require_once('../includes/waitlist.js');
?>

<div id="content">
	<h1>Join the Accelulator Beta</h1>
	<p>Just fill in the form below and we'll let you know when and how you can get access to the beta:</p>
	<div>
		<label>Name:</label><input id="name" type="text" placeholder="Enter your name here">
	</div>
	<div>
		<label>Email:</label><input id="email" type="text" placeholder="Enter your email address here">
	</div>
	<div id="submitDiv">
		<button onclick='addToWaitList();'>Submit</button>
	</div>
	<p>Once you've submitted your details, we'll keep you in the loop and you'll have the option of joining the beta for StaffCast.</p>
</div>
<div id='empty'></div>

<?
require_once('../includes/footer.php');
?>