<?php // FUNCTIONS

require_once __DIR__ . '/../../httpd.private/env.php';
require_once __DIR__ . '/pdoSetup.php';

global $pdo;

function can_view_names(array $user): bool {
	// however you check Complete Access / company membership today
	return (int)$user['ACCESS_LEVEL'] >= -1;
}

function createTable($name, $query) { // Checks whether a table already exists and, if not, creates it
	
	queryMysql("CREATE TABLE IF NOT EXISTS $name($query)");
	echo "Table '$name' created or already exists.<br>";

}

function queryMysql($query) { // Issues a query to MySql, outputting an error message if it fails
	
	global $pdo;
	return $pdo->query($query);
	
}

// ---------------------------
// STRIPE / APP CONFIG LOADER
// ---------------------------
$cfgPath = __DIR__ . '/config.php';
if (file_exists($cfgPath)) {
	require_once $cfgPath; // DO NOT assign to a var; constants are defined inside
}

// Simple getter that works with constants in config.php
function cfg(string $key, $default = null) {
	return defined($key) ? constant($key) : $default;
}

// -------------------------

// Stripe-aligned monthly proration: fraction of the month remaining, by seconds (UTC)
function proration_fraction_utc(): float {
	$now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
	$start = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC'));
	$end   = new DateTimeImmutable('last day of this month 23:59:59', new DateTimeZone('UTC'));

	$total = max(1, $end->getTimestamp() - $start->getTimestamp());
	$rem   = max(0, $end->getTimestamp() - $now->getTimestamp());

	return min(1.0, $rem / $total);
}

// # destroySession : Used to clear out the session when a user logs out

function destroySession() { // Destroys a PHP session and clears its data to log users out
	// Unset all session variables
	$_SESSION = [];
	
	// If session uses cookies, remove the session cookie
	if (ini_get("session.use_cookies")) {
		$params = session_get_cookie_params();
		setcookie(
			session_name(),
			'',
			time() - 42000,
			$params["path"],
			$params["domain"],
			$params["secure"],
			$params["httponly"]
		);
	}
	
	// Finally, destroy the session
	session_destroy();
}

// -------------------------

// # destroyCookies : Used to clear out the cookies when a user logs out

function destroyCookie($cookieName) { // Destroys cookies and clears its data to log users out
	if(isset($_COOKIE[$cookieName])){
		unset($_COOKIE[$cookieName]);
		setcookie($cookieName,'',time()-2592000,'/');
		return true;
	}else{
		return false;
	}
}

// -------------------------

function sanitizeString($var) { // Removes potentially malicious code or tags from user input
	global $pdo;
	
	$var = strip_tags($var);
	$var = htmlentities($var);
	$var = stripslashes($var);
	
	$result = $pdo->quote($var);          // This adds single quotes
	return str_replace("'", "", $result); // So now remove them

	return ($var);
}

// -------------------------

// loginUser() : 
function loginUser($error,$user,$pass){ // Signs the user in if possible
	
	if(isset($_POST['status'])){ // if there has been a submitted form and it includes a status field
		
		if($user==""||$pass==""){ // if either the user value or the password value are empty
			
			$error = 'Not all fields were entered';
			
			echo <<<_EMPTYFIELDS
				<script>
					alert('$error')
				</script>
			_EMPTYFIELDS;
			
			setcookie('signedIn',0, time()+3600, '/');
			
		} else { // if neither of the fields are empty
			
			// run a query to find out if the user exists, returns rows where this user exists
			$q = queryMysql("SELECT * FROM users WHERE EMAIL = '$user'");
			
			$r = $q->fetch( PDO::FETCH_ASSOC );
			$r2 = $r['PASSWORD'];
			
			if($q->rowCount()==0){ // if there are no rows returned, then the user doesn't exist
				$error2="Invalid login attempt.";
				echo <<<_ALERT
					<script>
						alert('$error2')
					</script>
				_ALERT;
			} else {
				if(password_verify($pass, $r2)){
					$_SESSION['user'] = $user;
					$_SESSION['pass'] = $pass;
					setcookie('user',$user, time()+3600, '/');
					setcookie('signedIn',1, time()+3600, '/');
					require_once(__DIR__ . '/../scripts/getSettings.php');
					// Is this necessary?
					echo <<<_TOGGLENAV
						<script>
							// setCookie('signedIn',1,'/');
							toggleNavLinks();
						</script>
					_TOGGLENAV;
					header("Location: ../index.php");
					exit();
				} else {
					$error3 = "The password was not correct.";
					echo <<<_WRONGPASSWORD
						<script>
							alert('$error3')
						</script>
					_WRONGPASSWORD;
				}
			}
		
		}
	}
}

// -------------------------

// # registerUser() : 

function registerUser($error,$user,$pass){ // Registers the user if possible
	
	if(isset($_POST['status'])){
		
		if($user==""||$pass==""){
			
			$error = 'Not all fields were entered';
			
			echo <<<_EMPTYFIELDS
			<script>
				alert('$error')
			</script>
			_EMPTYFIELDS;
			
		} else {
			
			$result = queryMySql("SELECT * FROM users WHERE EMAIL='$user'");
			if($result->rowCount()){
				
				$error = 'That user address already exists.';
				echo <<<_USEREXISTS
				<script>
					alert('$error')
				</script>
				_USEREXISTS;
				
				
			} else {
				queryMysql("INSERT INTO users(EMAIL, PASSWORD) VALUES ('$user','$pass')");
				$_SESSION['user']=$user;
				$_SESSION['pass']=$pass;
				echo <<<_SUCCESS
				<script>
					alert("The email '$user' has been added to the system.")
				</script>
				_SUCCESS;
			}
		}
	}
}

// -------------------------

// divertUserForm() : 
function divertUserForm($status){ // Pass the $status variable to the script
	
    $error = $user = $pass = ""; // set $error, $user, and $pass to blank
	$user = sanitizeString($_POST['user']);
		
	if($status=='register'){
		
		$pass = password_hash(sanitizeString($_POST['pass']), PASSWORD_DEFAULT);
		registerUser($error,$user,$pass);
	
	} else {
		
		if ($status=='signin'){
			
			$pass = sanitizeString($_POST['pass']);
			loginUser($error,$user,$pass);
			
		} else {
			
		    $error = "Something has gone wrong with the signin/registration. Please contact support.";
            echo <<<_FINALERROR
            <script>
                alert($error)
            </script>
            _FINALERROR;
		
		}
	}
    
}

// -------------------------

// checkIfUserIsSignedIn() : 
function checkIfUserIsSignedIn($loggedIn){ // Script to check if the user is logged in or not
	if(isset($_COOKIE['signedIn'])){
		if($_COOKIE['signedIn']==1) {
			$loggedIn = TRUE;
			echo <<<_JS
				<script>
					$("contentView").load("/main/dashboard.php")
				</script>
			_JS;
			return($loggedIn);
		}
	} else {
		$loggedIn = FALSE;
		setcookie('signedIn', '0', time()+3600, '/');
		return($loggedIn);
	}
}

function objectToArray($data){ // Script from snipplr.com to convert an object to an array
	if(is_array($data) || is_object($data)) {
		$result = array();
		foreach($data as $key => $value){
			$result[$key] = $this->objectToArray($value);
		}
		return $result;
	}
	return $data;
}

function checkUser(){ // Checks that the user is logged in and, if not, sends them back to the homepage
	
	$user = '';
	
	if(isset($_SESSION['user'])){ // Check if the session is active (the user is logged in)
		
		// If they are logged in, set the $user variable to the session value
		$user = $_SESSION['user'];
		
		return $user;
		
	}else{
	
		// If not, they need to be alerted to the fact that they're not logged in
		echo <<<_ALERT
		<script>
			
			$("#empty").load("./scripts/destroySession.php");
			$("#logInLink").show();
			$("#registerLink").show();
			$("#logOutLink").hide();
			window.location.href = window.location.href.split('#')[0];
			
			alert('Your session has expired, please sign in again to verify your identity.')
			
		</script>
		_ALERT;
		
		return $user;
		
		// Have tested and, if the session does not exist, the user isn't sent back to the homepage
		// Need to run the sign out script
	
	};
}

function getUsersCompanyId($user){ // uses the user number provided to obtain the company that the user belongs to in order to reference the relevant tables for that company
	
	// Get the user's ID
	$q = queryMysql("SELECT * FROM users WHERE EMAIL = '$user'");
	$r = $q->fetch( PDO::FETCH_ASSOC );
	$ref = $r['REF'];
	
	// Get the company for which the user has access rights
	$q = queryMySQL("SELECT * FROM user_details WHERE USER_ID = '$ref'");
	$r = $q->fetch(PDO::FETCH_ASSOC);
	$ref = $r['COMPANY_ID'];
	
	return $ref;
}

function setupTables($ref){ // Creates the tables required for using the website
	
	// Create _actuals table
	queryMySql("CREATE TABLE ".$ref."_actuals AS SELECT REF, DATE, PERIOD, YEAR, EMP_KEY, TYPE, VALUE, CREATED FROM _actuals;");
	// Create _contract_type table
	queryMySql("CREATE TABLE ".$ref."_contract_type AS SELECT REF, NAME FROM _contract_type");
	// Create _departments table
	queryMySql("CREATE TABLE ".$ref."_departments AS SELECT REF, DEPARTMENT FROM _departments;");
	// Create _details table
	queryMySql("CREATE TABLE ".$ref."_details AS SELECT REF, EMP_KEY, START_DATE, END_DATE, ANNUAL_SALARY, FTE, PENSION, LAST_UPDATE FROM _details;");
	// Create _forecasts table
	queryMySql("CREATE TABLE ".$ref."_forecasts AS SELECT REF, ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION, ROLE_REFERENCE, TYPE, PAY_ELEMENT, IS_ACTUAL, MONTH, VALUE, IS_PUBLISHED, DATESTAMP FROM _forecasts;");
	// Create _payroll_library table
	queryMySql("CREATE TABLE ".$ref."_payroll_library AS SELECT REF, PAYROLL_NUMBER, EMP_KEY FROM _payroll_library;");
	// Create _paytype table
	queryMySql("CREATE TABLE ".$ref."_paytype AS SELECT REF, DESCRIPTION, VALUE, PAYTYPE_GROUP_REF FROM _paytype;");
	// Create _paytype_group table
	queryMySql("CREATE TABLE ".$ref."_paytype_group AS SELECT REF, PAYTYPEGROUP, VALUE FROM _paytype_group");
	// Create _resources table
	queryMySql("CREATE TABLE ".$ref."_resources AS SELECT REF, SALUTATION, FIRSTNAME_ENC, MIDDLENAME_ENC, SURNAME_ENC, NAME_TAG, DOB, ROLE, USERKEY, DEPARTMENT, CONTRACT_TYPE, LASTCHANGE FROM _resources;");
	// Create _roles table
	queryMySql("CREATE TABLE ".$ref."_roles AS SELECT REF, JOB_TITLE, DEPARTMENT, FILLED_REFERENCE, CREATION_DATE, STATUS, BENCHMARK_FTE, BENCHMARK_SALARY, BENCHMARK_PRORATA_SALARY, START_DATE, END_DATE, CONTRACT_TYPE FROM _roles;");
	// Create _settings table
	queryMySql("CREATE TABLE ".$ref."_settings AS SELECT REF, PREFERENCE, VALUE, LASTCHANGE FROM _settings");
	// Create _categorisation table
	queryMySql("CREATE TABLE ".$ref."_categorisation AS SELECT REF, RES_REF, RES_ROL, DATE, OPEX, EXCEPTIONAL, LABOUR_CAPITALISATION FROM _categorisation");
	// Create _outturn table
	queryMySql("CREATE TABLE ".$ref."_outturn AS SELECT REF, RES_ROL, DATE, EMP_KEY, TYPE, VALUE, CREATED FROM _outturn");
}

// ---------------------------
// 📅 CALENDAR/DATE FUNCTIONS
// ---------------------------

function dateToMMM_YY($date){ // Takes a date and turns it into MMM-YY text
	$dateFormat = date("M-y",strtotime($date));
	$dateParts = explode("-",$dateFormat);
	$dateSafe = $dateParts[0] . "-" . $dateParts[1];
	return $dateSafe;
}

function parseMonthYear($input) { // Takes a MMM-YY and turns it into a date
// 📅 Converts a MMM-YY to YYYY-MM-DD
// 📌 Not currently used across the site but definitely something that could be used in future

	// Split input like "Jun-25" into ["Jun", "25"]
	[$monthAbbr, $yearSuffix] = explode('-', $input);

	// Map 3-letter month abbreviations to month numbers (0-based in JS, but 1-based in PHP)
	$monthMap = [
		'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
		'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
		'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12
	];

	if (!isset($monthMap[$monthAbbr])) {
		throw new Exception("Invalid month abbreviation: $monthAbbr");
	}

	$month = $monthMap[$monthAbbr];
	$year = 2000 + intval($yearSuffix);

	if (!is_numeric($year) || $year < 2000 || $year > 2099) {
		throw new Exception("Invalid year suffix: $yearSuffix");
	}

	// Create DateTime object for the 1st of the month
	$dateString = sprintf('%04d-%02d-01', $year, $month);
	$date = DateTime::createFromFormat('Y-m-d', $dateString);

	if (!$date) {
		throw new Exception("Failed to create date from input: $input");
	}

	return $date;
}

// ---------------------------
// TOKEN / CSRF
// ---------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

function generateCsrfToken(): string {
	if (session_status() === PHP_SESSION_NONE) session_start();
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

function getCsrfToken(): string {
	if (session_status() === PHP_SESSION_NONE) session_start();
	return $_SESSION['csrf_token'] ?? '';
}

function validateCsrfToken(): bool { // ✅ THIS WORKS: include it on the fetched script and it will do the work for you
	if (session_status() === PHP_SESSION_NONE) session_start();

	$incoming = '';
	// 1) Try getallheaders()
	if (function_exists('getallheaders')) {
		
		// Checks both common ways a JavaScript fetch() request might send a CSRF token
		$h = getallheaders();
		if (isset($h['X-CSRF-Token']))      $incoming = $h['X-CSRF-Token'];
		elseif (isset($h['x-csrf-token']))  $incoming = $h['x-csrf-token'];
	}
	
	// 2) Fallback to $_SERVER (common on shared hosting): ✅ In case getallheaders() isn't available (as on some shared hosting setups), it falls back
	if ($incoming === '') {
		$incoming = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
	}

	$sessionToken = $_SESSION['csrf_token'] ?? '';
	return is_string($incoming) && is_string($sessionToken) &&
		   $incoming !== '' && $sessionToken !== '' &&
		   hash_equals($sessionToken, $incoming);
}

/** JSON responder helper */
function json_response(array $payload, int $code = 200): void {
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

/** Expose PDO (unchanged) */
function getPDO(): PDO {
	global $pdo;
	if (!($pdo instanceof PDO)) {
		throw new RuntimeException('Database connection not initialised');
	}
	return $pdo;
}

// --- simple HTML mail helper -----------------------------------------------
function sendHtmlMail(string $to, string $subject, string $html, string $text = ''): bool {
	$from = 'no-reply@accelulator.com'; // adjust to a verified sender
	$headers  = "MIME-Version: 1.0\r\n";
	$headers .= "Content-type: text/html; charset=UTF-8\r\n";
	$headers .= "From: Accelulator <{$from}>\r\n";
	$headers .= "Reply-To: {$from}\r\n";

	// Basic plaintext alternative (some MTAs display it)
	if ($text === '') {
		$text = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));
	}

	// If you later move to SMTP/PHPMailer, keep the signature the same
	return @mail($to, $subject, $html, $headers);
}

?>