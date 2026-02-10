<?php // FUNCTIONS

require_once __DIR__ . '/../../httpd.private/env.php';
require_once __DIR__ . '/pdoSetup.php';

// (Re)start the session
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

global $pdo;

function can_view_names($user): bool {
	// however you check Complete Access / company membership today
	// return (int)$user['ACCESS_LEVEL'] >= -1;
	return 1==1;
}

function createTable($name, $query) { // Checks whether a table already exists and, if not, creates it
	
	queryMysql("CREATE TABLE IF NOT EXISTS $name($query)");
	echo "Table '$name' created or already exists.<br>";

}

function queryMysql($query) { // Issues a query to MySql, outputting an error message if it fails
	
	global $pdo;
	return $pdo->query($query);
	
}

// ----------------------------
// ---- ENCRYPTION HELPERS ----
// ----------------------------

// --- Resource-name encryption helpers ---------------------------------------
if (!function_exists('res_has_encrypted_name_cols')) {
	function res_has_encrypted_name_cols(PDO $pdo, string $table): array {
		$q = $pdo->prepare("
			SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl
		");
		$q->execute([':tbl' => $table]);
		$cols = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');

		$hasEnc = in_array('FIRSTNAME_ENC',$cols,true) && in_array('SURNAME_ENC',$cols,true);
		$hasIv  = in_array('FIRSTNAME_IV',$cols,true)  && in_array('SURNAME_IV',$cols,true); // optional for your scheme
		$hasTag = in_array('NAME_TAG',$cols,true) || (in_array('FIRSTNAME_TAG',$cols,true) && in_array('SURNAME_TAG',$cols,true));

		return ['enc'=>$hasEnc,'iv'=>$hasIv,'tag'=>$hasTag];
	}
}

if (!function_exists('res_name_select_sql')) {
	/**
	 * Build the SELECT fragment for names for a resources table.
	 * $alias is the table alias used for the resources table (e.g. 'r').
	 */
	function res_name_select_sql(PDO $pdo, string $table, string $alias='r'): string {
		$f = res_has_encrypted_name_cols($pdo, $table);
		if ($f['enc']) {
			// Encrypted columns present
			$parts = [
				"$alias.FIRSTNAME_ENC AS FIRSTNAME_ENC",
				"$alias.MIDDLENAME_ENC AS MIDDLENAME_ENC",
				"$alias.SURNAME_ENC AS SURNAME_ENC",
			];
			// IVs (optional)
			$parts[] = $f['iv'] ? "$alias.FIRSTNAME_IV AS FIRSTNAME_IV" : "NULL AS FIRSTNAME_IV";
			$parts[] = $f['iv'] ? "$alias.MIDDLENAME_IV AS MIDDLENAME_IV" : "NULL AS MIDDLENAME_IV";
			$parts[] = $f['iv'] ? "$alias.SURNAME_IV AS SURNAME_IV" : "NULL AS SURNAME_IV";
			// Common tag (preferred)
			$parts[] = $f['tag'] ? "$alias.NAME_TAG AS NAME_TAG" : "NULL AS NAME_TAG";
			return implode(",\n                ", $parts);
		}
		// Legacy plain-text columns
		return "$alias.FIRSTNAME AS FIRSTNAME, $alias.MIDDLENAME AS MIDDLENAME, $alias.SURNAME AS SURNAME";
	}
}

if (!function_exists('res_name_from_row')) {
	/**
	 * Turn a fetched row into [first, middle, last] using decrypt_field when available.
	 * $canView controls whether we actually decrypt or return a pseudonym.
	 */
	function res_name_from_row(array $row, bool $canView): array {
		// Detect path by presence of *_ENC
		$encrypted = array_key_exists('FIRSTNAME_ENC', $row);

		if (!$encrypted) {
			// Plain legacy
			return [
				(string)($row['FIRSTNAME'] ?? ''),
				(string)($row['MIDDLENAME'] ?? ''),
				(string)($row['SURNAME'] ?? ''),
			];
		}

		if (!$canView) {
			// Pseudonym (no sensitive data emitted)
			$id = $row['RES_REF'] ?? $row['REF'] ?? '';
			return ["Employee", "", "#".$id];
		}

		// Decrypt safely against any decrypt_field signature (1/2/3 args)
		$dec = function($c,$iv,$tag) {
			if (!function_exists('decrypt_field') || empty($c)) return '';
			try {
				$rf = new ReflectionFunction('decrypt_field');
				$argc = $rf->getNumberOfParameters();
				if ($argc >= 3) return decrypt_field($c, $iv, $tag) ?: '';
				if ($argc == 2)  return decrypt_field($c, $iv)      ?: '';
				return decrypt_field($c) ?: '';
			} catch (Throwable $e) { return ''; }
		};

		$tag = $row['NAME_TAG'] ?? null;
		return [
			$dec($row['FIRSTNAME_ENC']  ?? null, $row['FIRSTNAME_IV']  ?? null, $tag),
			$dec($row['MIDDLENAME_ENC'] ?? null, $row['MIDDLENAME_IV'] ?? null, $tag),
			$dec($row['SURNAME_ENC']    ?? null, $row['SURNAME_IV']    ?? null, $tag),
		];
	}
}

// --- END Resource-name encryption helpers END -------------------------------

// ---------------
// GENERIC HELPERS
// ---------------
function logLoginEvent(?int $userRef, string $email, bool $success, string $message = ''): void
{
	global $pdo;

	// default values
	$companyName = null;
	$ip          = $_SERVER['REMOTE_ADDR']     ?? '';
	$ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';

	// if we know the user, try to get their company name
	if (!empty($userRef)) {
		$stmt = $pdo->prepare("
			SELECT c.COMPANY_NAME
			FROM user_details ud
			JOIN companies c ON ud.LINKED_COMPANY = c.REF
			WHERE ud.USER_ID = :uid
			LIMIT 1
		");
		$stmt->execute([':uid' => $userRef]);
		$companyName = $stmt->fetchColumn() ?: null;
	}

	$stmt = $pdo->prepare("
		INSERT INTO login_log
			(USERREF, EMAIL, COMPANY_NAME, LOGIN_TIME, IP_ADDRESS, USER_AGENT, SUCCESS, MESSAGE)
		VALUES
			(:userref, :email, :company_name, NOW(), :ip, :ua, :success, :message)
	");
	$stmt->execute([
		':userref'      => $userRef,
		':email'        => $email,
		':company_name' => $companyName,
		':ip'           => $ip,
		':ua'           => $ua,
		':success'      => $success ? 1 : 0,
		':message'      => $message,
	]);
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
			logLoginEvent(null, $user, false, 'Empty username or password');
			
			echo <<<_EMPTYFIELDS
				<script>
					alert('$error')
				</script>
			_EMPTYFIELDS;
			
			setcookie('signedIn',0, time()+3600, '/');
			
		} else { // if neither of the fields are empty
			
			// run a query to find out if the user exists, returns rows where this user exists
			$q = queryMysql("SELECT * FROM users WHERE EMAIL = '$user'");
			
			if($q->rowCount()==0){ // if there are no rows returned, then the user doesn't exist
				$error2 = "Invalid login attempt.";
				logLoginEvent(null, $user, false, 'User not found');
				
				echo <<<_ALERT
					<script>
						alert('$error2')
					</script>
				_ALERT;
				
			} else {
				// we have a user row now, so fetch it
				$r        = $q->fetch(PDO::FETCH_ASSOC);
				$r2       = $r['PASSWORD'];
				$userRef  = (int)$r['REF']; // 👈 now we have the id

				if (password_verify($pass, $r2)) {
					$_SESSION['user'] = $user;
					// $_SESSION['pass'] = $pass;
					setcookie('user',$user, time()+3600, '/');
					setcookie('signedIn',1, time()+3600, '/');
					require_once(__DIR__ . '/../scripts/getSettings.php');
					
					// log: success
					logLoginEvent($userRef, $user, true, 'Login OK');
					
					echo <<<_TOGGLENAV
						<script>
							toggleNavLinks();
						</script>
					_TOGGLENAV;
					header("Location: ../index.php");
					exit();
				} else {
					$error3 = "The password was not correct.";
					
					// log: bad password
					logLoginEvent($userRef, $user, false, 'Incorrect password');
					
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
					$("contentView").load("/main/dashboard.php?inject=1")
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

function ensureCompanyKey(PDO $pdo, int $companyRef): void {
	// 1) do we already have a key?
	$check = $pdo->prepare("
		SELECT 1
		FROM company_keys
		WHERE COMPANY_REF = :r
		LIMIT 1
	");
	$check->execute([':r' => $companyRef]);
	if ($check->fetchColumn()) {
		return; // already has a key
	}

	// 2) load the master key (same as your other scripts)
	// functions.php already required env.php, so this should exist
	if (!function_exists('mo_master_key')) {
		require_once __DIR__ . '/crypto.php';
	}

	$mk = mo_master_key();
	if ($mk === '' || strlen($mk) !== 32) {
		throw new RuntimeException('Master key missing or wrong length when creating company key');
	}

	// 3) make a fresh 32-byte data key for THIS company
	$dataKey = random_bytes(32);

	// 4) wrap it using secretbox: nonce || ciphertext
	$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
	$wrapped = $nonce . sodium_crypto_secretbox($dataKey, $nonce, $mk);

	// 5) store it
	$ins = $pdo->prepare("
		INSERT INTO company_keys (COMPANY_REF, KEY_WRAPPED, CREATED_AT)
		VALUES (:r, :kw, NOW())
	");
	$ins->execute([
		':r'  => $companyRef,
		':kw' => $wrapped,
	]);
}


// ---------------------------
// 📋 TABLE CREATION
// ---------------------------

function setupTables($ref){
	// actuals
	queryMySql("CREATE TABLE {$ref}_actuals LIKE _actuals");
	
	// categorisation
	queryMySql("CREATE TABLE {$ref}_categorisation LIKE _categorisation");

	// contract type
	queryMySql("CREATE TABLE {$ref}_contract_type LIKE _contract_type");
	queryMySql("INSERT INTO {$ref}_contract_type SELECT * FROM _contract_type");
	
	// cost_split_override
	queryMySql("CREATE TABLE {$ref}_cost_split_override LIKE _cost_split_override");
	
	// cost_split_rule
	queryMySql("CREATE TABLE {$ref}_cost_split_rule LIKE _cost_split_rule");
	
	// cost_split_used
	queryMySql("CREATE TABLE {$ref}_cost_split_used LIKE _cost_split_used");

	// departments
	queryMySql("CREATE TABLE {$ref}_departments LIKE _departments");

	// details
	queryMySql("CREATE TABLE {$ref}_details LIKE _details");

	// forecasts
	queryMySql("CREATE TABLE {$ref}_forecasts LIKE _forecasts");
	
	// outturn
	queryMySql("CREATE TABLE {$ref}_outturn LIKE _outturn");

	// payroll library
	queryMySql("CREATE TABLE {$ref}_payroll_library LIKE _payroll_library");

	// paytype
	queryMySql("CREATE TABLE {$ref}_paytype LIKE _paytype");

	// paytype group
	queryMySql("CREATE TABLE {$ref}_paytype_group LIKE _paytype_group");
	queryMySql("INSERT INTO {$ref}_paytype_group SELECT * FROM _paytype_group");
	
	// pay rises
	queryMySql("CREATE TABLE {$ref}_pay_rises LIKE _pay_rises");

	// resources
	queryMySql("CREATE TABLE {$ref}_resources LIKE _resources");

	// roles
	queryMySql("CREATE TABLE {$ref}_roles LIKE _roles");

	// settings
	queryMySql("CREATE TABLE {$ref}_settings LIKE _settings");
	
	// week_calendar
	queryMySql("CREATE TABLE {$ref}_week_calendar LIKE _week_calendar");

	// and finally make sure the company has a key
	global $pdo;
	ensureCompanyKey($pdo, (int)$ref);
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

// ---------------------------
// WEEKLY HELPERS
// ---------------------------
function getWeeksForMonth(int $companyRef, int $year, int $month): array
{
	global $pdo;
	$t_week_calendar = $companyRef . '_week_calendar';

	$stmt = $pdo->prepare("
		SELECT WEEK_OF_YEAR
		FROM {$t_week_calendar}
		WHERE CALENDAR_YEAR = :year
			AND CALENDAR_MONTH = :month
		ORDER BY WEEK_OF_YEAR
	");
	$stmt->execute([
		':year'  => $year,
		':month' => $month,
	]);

	return $stmt->fetchAll(PDO::FETCH_COLUMN); // [1,2,3,4] or [10,11,12,13,14]
}

function getMonthlyTotals(int $companyRef, int $year): array
{
	global $pdo;
	$t_actuals = $companyRef . '_actuals';

	$sql = "
		SELECT 
			MONTH(`DATE`) AS month_num,
			SUM(VALUE)    AS total_value
		FROM {$t_actuals}
		WHERE YEAR(`DATE`) = :year
		GROUP BY MONTH(`DATE`)
	";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([':year' => $year]);

	$totals = [];
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$m = (int)$row['month_num'];
		$totals[$m] = (float)$row['total_value'];
	}

	return $totals; // keyed by 1..12
}

function getWeeklyTotalsFromMonthly(int $companyRef, int $year): array {
	$monthlyTotals = getMonthlyTotals($companyRef, $year);

	// weeklyTotals[week_of_year] = total_value
	$weeklyTotals = [];

	for ($month = 1; $month <= 12; $month++) {

		if (!isset($monthlyTotals[$month])) {
			continue; // no data for this month
		}

		$weeksInMonth = getWeeksForMonth($companyRef, $year, $month);
		if (empty($weeksInMonth)) {
			continue;
		}

		$numWeeks   = count($weeksInMonth);
		$perWeekVal = $monthlyTotals[$month] / $numWeeks;

		foreach ($weeksInMonth as $weekNum) {
			if (!isset($weeklyTotals[$weekNum])) {
				$weeklyTotals[$weekNum] = 0.0;
			}
			$weeklyTotals[$weekNum] += $perWeekVal;
		}
	}

	ksort($weeklyTotals);

	return $weeklyTotals; // [1 => 1234.56, 2 => ..., ...]
}

function deriveYearPeriodForMonth(PDO $pdo, string $tableActuals, string $monthStart): array {
	// monthStart = 'YYYY-MM-01'
	$monthEnd = date('Y-m-t', strtotime($monthStart)) . ' 23:59:59';
	$monthStartDt = $monthStart . ' 00:00:00';

	$sql = "
		SELECT YEAR, PERIOD, COUNT(*) AS c
		FROM `$tableActuals`
		WHERE `DATE` >= :ms AND `DATE` <= :me
			AND YEAR IS NOT NULL AND PERIOD IS NOT NULL
		GROUP BY YEAR, PERIOD
		ORDER BY c DESC
		LIMIT 1
	";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([':ms' => $monthStartDt, ':me' => $monthEnd]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	if ($row) {
		return [(int)$row['YEAR'], (int)$row['PERIOD']];
	}

	// fallback: calendar
	$y = (int)substr($monthStart, 0, 4);
	$m = (int)substr($monthStart, 5, 2);
	return [$y, $m];
} // Used in _cost_split_used to derive a YEAR and PERIOD from the date of the payroll

function upsertCostSplitUsedMonth(
	PDO $pdo,
	string $tableRule,
	string $tableUsed,
	string $scope,
	int $scopeRef,
	int $year,
	int $period,
	string $monthStart
): void {
	$scope = strtoupper($scope);
	if (!in_array($scope, ['RESOURCE','ROLE'], true)) return;
	if ($scopeRef <= 0 || $year < 2000 || $period < 1 || $period > 12) return;

	// Find applicable rule at monthStart
	$sqlRule = "
		SELECT REF, OPEX_PCT, CAPEX_PCT, EXCEPT_PCT
		FROM `$tableRule`
		WHERE SCOPE = :scope
			AND SCOPE_REF = :scopeRef
			AND EFFECTIVE_FROM <= :d1
			AND (EFFECTIVE_TO IS NULL OR EFFECTIVE_TO >= :d2)
		ORDER BY EFFECTIVE_FROM DESC
		LIMIT 1
	";
	$stmt = $pdo->prepare($sqlRule);
	$stmt->execute([
		':scope'    => $scope,
		':scopeRef' => $scopeRef,
		':d1'       => $monthStart,
		':d2'       => $monthStart,
	]);
	$r = $stmt->fetch(PDO::FETCH_ASSOC);

	$opex = 100.00; $capex = 0.00; $except = 0.00;
	$source = 'DEFAULT';
	$sourceRuleRef = null;

	if ($r) {
		$opex  = (float)$r['OPEX_PCT'];
		$capex = (float)$r['CAPEX_PCT'];
		$except= (float)$r['EXCEPT_PCT'];
		$source = 'RULE';
		$sourceRuleRef = (int)$r['REF'];
	}

	// Upsert row for this month (unique key still SCOPE,SCOPE_REF,YEAR,PERIOD)
	$sqlUsed = "
		INSERT INTO `$tableUsed`
			(SCOPE, SCOPE_REF, YEAR, PERIOD, MONTH_START,
			 OPEX_PCT_USED, CAPEX_PCT_USED, EXCEPT_PCT_USED,
			 SOURCE, SOURCE_RULE_REF,
			 UPDATED_AT)
		VALUES
			(:scope, :scopeRef, :year, :period, :monthStart,
			 :opex, :capex, :except,
			 :source, :sourceRuleRef,
			 NOW())
		ON DUPLICATE KEY UPDATE
			MONTH_START = VALUES(MONTH_START),

			OPEX_PCT_USED = CASE
				WHEN SOURCE = 'OVERRIDE' OR LOCKED = 1 THEN OPEX_PCT_USED
				ELSE VALUES(OPEX_PCT_USED)
			END,
			CAPEX_PCT_USED = CASE
				WHEN SOURCE = 'OVERRIDE' OR LOCKED = 1 THEN CAPEX_PCT_USED
				ELSE VALUES(CAPEX_PCT_USED)
			END,
			EXCEPT_PCT_USED = CASE
				WHEN SOURCE = 'OVERRIDE' OR LOCKED = 1 THEN EXCEPT_PCT_USED
				ELSE VALUES(EXCEPT_PCT_USED)
			END,
			SOURCE = CASE
				WHEN SOURCE = 'OVERRIDE' OR LOCKED = 1 THEN SOURCE
				ELSE VALUES(SOURCE)
			END,
			SOURCE_RULE_REF = CASE
				WHEN SOURCE = 'OVERRIDE' OR LOCKED = 1 THEN SOURCE_RULE_REF
				ELSE VALUES(SOURCE_RULE_REF)
			END,
			UPDATED_AT = NOW()
	";
	$ins = $pdo->prepare($sqlUsed);
	$ins->execute([
		':scope'         => $scope,
		':scopeRef'      => $scopeRef,
		':year'          => $year,
		':period'        => $period,
		':monthStart'    => $monthStart,
		':opex'          => $opex,
		':capex'         => $capex,
		':except'        => $except,
		':source'        => $source,
		':sourceRuleRef' => $sourceRuleRef,
	]);
} // Respects OVERRIDE/LOCKED and sets MONTH_START

?>