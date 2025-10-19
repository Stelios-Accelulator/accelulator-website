<?php 
session_start();
require_once('../includes/functions.php');
validateCsrfToken();
header('Content-Type: application/json');

$rawData = file_get_contents("php://input");
$data = json_decode($rawData,true);

function registerEmail($error,$user,$pass,$business,$firstname,$surname){ // Registers the user if possible
	
	global $pdo; // Make $pdo available to this function so that I can call it later
	
	// Create the user in the users table
	$sql = "
		INSERT INTO users(
			EMAIL, 
			PASSWORD,
			MAIN
		) 
		VALUES (
			:email, 
			:password,
			:main
		)
	";
	
	$stmt = $pdo->prepare($sql); // Prepare the statement
	
	$stmt->execute([ // Execute the statement
		':email' 	=>	$user,
		':password' =>	$pass,
		':main' 	=>	'1',
	]);
	
	// Store the REF of the last inserted item 
	$lastId = $pdo->lastInsertId();
	$lid = $lastId;
	
	// Create the company in companies table
	$sql = "
		INSERT INTO companies(
			COMPANY_NAME,
			MAIN_ACCOUNT
		) VALUES (
			:company_name,
			:main_account
		)
	";
	
	$stmt = $pdo->prepare($sql); // Prepare the statement
	
	$stmt->execute([ // Execute the statement
		':company_name' =>	$business,
		':main_account'	=>	$lastId,
	]);
	
	// Store the REF of the last inserted item
	$lastCoId = $pdo->lastInsertId();
	
	// Expand the user via the user details table
	$sql = "
		INSERT INTO user_details(
			USERNAME, 
			FIRSTNAME, 
			SURNAME,  
			COMPANY_ID, 
			LINKED_COMPANY, 
			USER_ID
		) VALUES (
			:username, 
			:firstname, 
			:surname,  
			:company_id, 
			:linked_company, 
			:user_id
		)
	";
	
	$stmt = $pdo->prepare($sql);
	
	$stmt->execute([
		':username' => $user,
		':firstname' => $firstname,
		':surname' => $surname,
		':company_id' => '0',
		':linked_company' => $lastCoId,
		':user_id' => $lastId,
	]);
	
	// Insert a setting for the company's year end and established date
	$sql = "
		INSERT INTO companyYearEnd(
			COMPANY, 
			MONTHNO,
			MONTH,
			YEAR
		) VALUES (
			:companyRef,
			:monthNo,
			:month,
			:year
		)
	";
	
	$stmt = $pdo->prepare($sql);
	
	$stmt->execute([
		':companyRef'	=>	$lastCoId,
		':monthNo'		=>	3,
		':month'		=>	'March',
		':year'			=>	2025
	]);
	
	// get the reference for the user (to be used when creating the tables)
	$ref = getUsersCompanyId($user);
	
	$_SESSION['user']=$user;
	$_SESSION['pass']=$pass;
	
	// Need to insert the user into user-access
	
	$sql = "
		INSERT INTO user_access(
			USERREF,
			ACCESS_LEVEL
		) VALUES (
			:userref,
			:accesslevel
		)
	";
	
	$stmt = $pdo->prepare($sql);
	
	$stmt->execute([
		':userref' => $lastId,
		':accesslevel' => 0,
	]);
	
	// SETUP DEMO IN THE user_demo TABLE
	$sql = "
	INSERT INTO user_demo(
		USERREF
	) VALUES (
		:userref
	)
	";
	
	$stmt = $pdo->prepare($sql);
	
	$stmt->execute([
		':userref'	=>	$lid
	]);
	
	http_response_code(200);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'ok'		=>	true,
		'message'	=>	'Account created. Sign in to access your account',
		'redirect'	=>	'https://accelulator.com?email='.$user
	]);
	exit;
}

// Set the variables ahead of populating them with cookies
$email = '';
$pass = '';
$error = 'Something has gone wrong';
$business = '';
$firstname = '';
$surname = '';

if(isset($data['email'])){ // Check if the cookie exists, if it does, set it (after cleaning)
	$email = sanitizeString($data['email']);
}else{
	$email = '';
}

if(isset($data['pass'])){ // Check if the cookie exists, if it does, set it (after cleaning)
	$pass = password_hash(sanitizeString($data['pass']), PASSWORD_DEFAULT);
}else{
	$pass = '';
}

if(isset($data['business'])){ // Check if the cookie exists, if it does, set it (after cleaning)
	$business = sanitizeString($data['business']);
}else{
	$business = '';
}

if(isset($data['firstname'])){ // Check if the cookie exists, if it does, set it (after cleaning)
	$firstname = sanitizeString($data['firstname']);
} else {
	$firstname = '';
}

if(isset($data['surname'])){ // Check if the cookie exists, if it does, set it (after cleaning)
	$surname = sanitizeString($data['surname']);
} else {
	$surname = '';
}

// FUNDAMENTAL: I ACTUALLY WANT AN EMAIL TO GO OUT TO THE INDIVIDUAL FOR THEM TO CONFIRM THE REGISTRATION BEFORE I SET THEM UP TO COMBAT BOTS 
// FUNDAMENTAL QUESTION: AM I GOING TO ALLOW ANYONE TO SELECT ANY BUSINESS NAME?

// Check that the user doesn't already exist
$q = queryMySql("SELECT * FROM users WHERE EMAIL = '$email'");

if ($q && $q->rowCount() != 0) { // THE USER ALREADY EXISTS
	
	// Send the user to a "forgot password page" NEED TO CREATE THIS
	// Send an email to the user account NEED TO WRITE THIS
	
} else { // THE USER DOES NOT ALREADY EXIST
	// REWRITE THIS
	
	// SEND EMAIL TO THE USER
	// REDIRECT THE PAGE TO A PAGE STATING THAT IT'S AWAITING THEIR AUTHENTICATION
	// IS IT POSSIBLE TO SET A LISTENER THAT WAITS FOR THE USER TO ACTIVATE THE ACCOUNT AND THEN REDIRECT?
	
	// THIS WILL MOVE TO THE EMAIL
	registerEmail($error,$email,$pass,$business,$firstname,$surname);
			
	echo <<<_ALERT
		<script>
			setCookie('signedIn',1,'/');
		</script>
	_ALERT;	
}

?>