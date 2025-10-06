<?
session_start();
require_once('../includes/functions.php');

$user = checkUser();

if ($user != '') {
	$ref = getUsersCompanyId($user);
	
	$table_forecast = $ref . '_forecasts';

	// Get from cookies or fall back to latest forecast
	if (isset($_COOKIE['forecastName'], $_COOKIE['forecastPrefix'], $_COOKIE['forecastVersion'])) {
		$forecastPrefix = $_COOKIE['forecastPrefix'];
		$forecastName = $_COOKIE['forecastName'];
		$forecastVersion = $_COOKIE['forecastVersion'];
	} else {
		$qLatest = queryMysql("
			SELECT ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION
			FROM $table_forecast
			ORDER BY DATESTAMP DESC
			LIMIT 1
		");
		
		$latest = $qLatest->fetch();
		
		if ($latest) {
			$forecastPrefix = $latest['ACTUAL_FORECAST'];
			$forecastName = $latest['FORECAST_NAME'];
			$forecastVersion = $latest['FORECAST_VERSION'];
		} else {
			exit("No forecast data available.");
		}
	}
	
	// Get matching forecast rows
	$q = queryMysql("
		SELECT * FROM $table_forecast
		WHERE ACTUAL_FORECAST = '$forecastPrefix'
		AND FORECAST_NAME = '$forecastName'
		AND FORECAST_VERSION = '$forecastVersion'
	");

	$r = $q->fetchAll();

	// Build PHP array to convert to JS
	$forecastRows = [];
		
	foreach ($r as $row) {
		$type = $row['TYPE'];
		$role = $row['ROLE_REFERENCE'];
		$month = $row['MONTH'];
		$payElement = $row['PAY_ELEMENT'];
		$value = $row['VALUE'];

		if (!isset($forecastRows[$type])) $forecastRows[$type] = [];
		if (!isset($forecastRows[$type][$role])) $forecastRows[$type][$role] = [];
		if (!isset($forecastRows[$type][$role][$month])) $forecastRows[$type][$role][$month] = [];

		$forecastRows[$type][$role][$month][$payElement] = $value;
	}

	// Echo everything once as JSON
	echo "<script>\n";
	echo "var forecastRows = " . json_encode($forecastRows, JSON_PRETTY_PRINT) . ";\n";
	echo "</script>";
	
	echo "<script>\n";
	echo "allocateForecast(); createTable(); createSummaryTable();";
	echo "</script>";
}
?>