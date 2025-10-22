<?
session_start();
require_once('../includes/functions.php');

$user = checkUser();
if ($user === '') exit("User not authenticated.");

$ref = getUsersCompanyId($user);
$table_forecast = $ref . '_forecasts';

// 1️⃣ Determine forecast context
if (isset($_COOKIE['forecastName'], $_COOKIE['forecastPrefix'], $_COOKIE['forecastVersion'])) {
	$forecastPrefix = $_COOKIE['forecastPrefix'];
	$forecastName = $_COOKIE['forecastName'];
	$forecastVersion = $_COOKIE['forecastVersion'];
} else {
	$stmt = $pdo->prepare("
	SELECT 
		ACTUAL_FORECAST,
		FORECAST_NAME,
		FORECAST_VERSION
	FROM $table_forecast 
	ORDER BY DATESTAMP DESC
	LIMIT 1
	");
	
	$stmt->execute();
	$latest = $stmt->fetch(PDO::FETCH_ASSOC);
	
	if (!$latest) exit(json_encode(['error' => 'No forecast data available.']));
	
	extract($latest); // creates $ACTUAL_FORECAST, $FORECAST_NAME, $FORECAST_VERSION
	$forecastPrefix = $ACTUAL_FORECAST;
	$forecastName = $FORECAST_NAME;
	$forecastVersion = $FORECAST_VERSION;
	
}

// 2️⃣ Get matching forecast rows
$stmt = $pdo->prepare("
	SELECT 
		TYPE,
		ROLE_REFERENCE,
		MONTH,
		PAY_ELEMENT,
		VALUE
	FROM $table_forecast 
	WHERE 
		ACTUAL_FORECAST = ?
	AND
		FORECAST_NAME = ? 
	AND 
		FORECAST_VERSION = ?
");

$stmt->execute([$forecastPrefix, $forecastName, $forecastVersion]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3️⃣ Build associative structure
$forecastRows = [];
foreach ($rows as $row) {
	$t = $row['TYPE'];
	$r = $row['ROLE_REFERENCE'];
	$m = $row['MONTH'];
	$p = $row['PAY_ELEMENT'];
	$v = $row['VALUE'];
	$forecastRows[$t][$r][$m][$p] = $v;
}

// 4️⃣ Output JSON only (frontend JS can handle it)
header('Content-Type: application/json');
echo json_encode($forecastRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>