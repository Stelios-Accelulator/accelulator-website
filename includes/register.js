<script>

function processRegistrationDetails(){ // Script to check the entries
	
	// Clean the user inputs
	let email = scrub(document.getElementById("emailAddress").value);
	let pass = scrub(document.getElementById("pass").value);
	let business = scrub(document.getElementById("businessName").value);
	let firstname = scrub(document.getElementById("firstname").value);
	let surname = scrub(document.getElementById("surname").value);
	let validEmail = 0;
	let validPassword = 0;
	let validBusiness = 0;
	let validFirstname = 0;
	let validSurname = 0;
	let message = "";
	
	const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/; // Regex to match against for the email
	
	// Check that the email fulfils the regex requirements
	if (emailRegex.test(email)) {
		validEmail = 1;
	}else{
		validEmail = 0;
	}
	
	// Check that the password fulfils the requirements (longer than 7 characters)
	if (pass.length >= 7){
		validPassword = 1;
	} else {
		validPassword = 0;
		alert("Password must be at least 7 characters long.")
	}
	
	// Check that the business name is not empty
	if(business != ""){
		validBusiness = 1;
	}else{
		validBusiness = 0;
	}
	
	// Check that the first name is not empty
	if(firstname != ""){
		validFirstname = 1;
	}else{
		validFirstname = 0;
	}
	
	// Check that the surname is not empty
	if(surname != ""){
		validSurname = 1;
	}else{
		validSurname = 0;
	}
	
	if (validEmail==1 && validPassword==1 && validBusiness==1 && validFirstname==1 && validSurname==1){
		message = "Valid";
		
		fetch("/scripts/registerUser.php", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-CSRF-Token": window.csrfToken
			},
			body: JSON.stringify({
				email: email,
				pass: pass,
				business: business,
				firstname: firstname,
				surname: surname
			})
		})
		.then(r=>r.json())
		.then(data => {
			if(data.ok) {
				alert(data.message);
				window.location.assign(data.redirect);
			}else{
				alert(data.error || 'Something went wrong');
			}
		})
		.catch(() => alert('network error'));
	} else {
		message = "You have not filled out all of the entries correctly. Please review and try again."
	}
	
}

function checkFirstname(){
	let firstname = document.getElementById("firstname");
	let name = scrub(firstname.value);
	if (name != '') {
		firstname.style.borderColor = "";
		firstname.style.backgroundColor = "";
	}else{
		firstname.style.borderColor = "red";
		firstname.style.backgroundColor = "yellow";
	}
}

function checkSurname(){
	let surname = document.getElementById("surname");
	let name = scrub(surname.value);
	if (name != '') {
		surname.style.borderColor = "";
		surname.style.backgroundColor = "";
	}else{
		surname.style.borderColor = "red";
		surname.style.backgroundColor = "yellow";
	}
}

function checkEmailAddress(){
	let emailAddress = document.getElementById("emailAddress");
	let email = scrub(emailAddress.value);
	const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/; // Regex to match against for the email
	if (emailRegex.test(email)) {
		emailAddress.style.borderColor = "";
		emailAddress.style.backgroundColor = "";
	}else{
		emailAddress.style.borderColor = "red";
		emailAddress.style.backgroundColor = "yellow";
	}
}

function checkRegistrationPassword(){ // Script to check that the password is valid
	
	let passwordInput = document.getElementById("pass");
	let pass = scrub(passwordInput.value);
	if (pass.length < 7){
		passwordInput.style.borderColor = "red";
		passwordInput.style.backgroundColor = "yellow";
	} else {
		passwordInput.style.borderColor = "";
		passwordInput.style.backgroundColor = "";
	}
	
}

function checkBusinessName(){
	
	let businessInput = document.getElementById("businessName");
	let business = scrub(businessInput.value);
	if (business == ""){
		businessInput.style.borderColor = "red";
		businessInput.style.backgroundColor = "yellow";
	} else {
		businessInput.style.borderColor = "";
		businessInput.style.backgroundColor = "";
	}
	
}


</script>