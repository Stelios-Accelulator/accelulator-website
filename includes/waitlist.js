<script>

function addToWaitList() {
	
	let name = document.getElementById("name").value;
	let email = document.getElementById("email").value;
	
	if (name == "" && email == ""){ // If both the name and email address are empty, tell the user
		alert("You have not entered a name or email address. Please check and try again.")
	} else if (name == "") { // if the name is blank, tell the user
		alert("You have not entered your name, please check and try again.")
	} else if (email == "") { // if the email address is blank, tell the user
		alert("You have not entered an email address, please check and try again.")
	} else { // run the script
		
		name = scrub(document.getElementById("name").value);
		email = scrub(document.getElementById("email").value);
		
		setCookie("name",name,0);
		setCookie("email",email,0);
		
		$('#empty').load("/scripts/addToWaitlist.php");
		
		let successString =""+
		"<h1>Successful Registration</h1>"+
		"<p>You have successfully registered to joing the Accelulator Beta. We will be in touch shortly, at your email address (" +
		email +
		") to let you know how to take part.</p>"+
		"<p>Thank you for your time and we hope you'll enjoy it as much we do.</p>"+
		"<p><a href='https://accelulator.com/'>Return to accelulator.com</a>.</p>"
		
		document.getElementById('content').innerHTML = successString;
		
	}
}

</script>