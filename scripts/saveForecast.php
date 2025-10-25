<?php // Script to save the forecast as currently stands
session_start();
require_once('../includes/functions.php');
validateCsrfToken(); // <--- protect this script
header('Content-Type: application/json');

try {
	
	$user = checkUser();
	
	if ($user != '') {
		$ref = getUsersCompanyId($user);
		$table_forecasts = $ref . "_forecasts";
	
		$rawData = file_get_contents("php://input");
		$data = json_decode($rawData, true);
		file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/debug_forecast.json', print_r($data,true));
	
		if (!$data) {
			echo json_encode(["status" => "error", "message" => "Invalid or empty JSON"]);
			exit;
		}
	
		$resources = $data['resources'] ?? [];
		$roles = $data['roles'] ?? [];
	
		$actual_forecast = $_COOKIE['actual_forecast'] ?? '00+12';
		$forecast_name = $_COOKIE['forecast_name'] ?? 'Baseline';
		$number_of_actual_months = (int)($_COOKIE['a'] ?? 0);
		$number_of_forecast_months = (int)($_COOKIE['f'] ?? 12);
		$cMonth = new DateTime('first day of this month');
		$cMonth->modify('-'.$number_of_actual_months.' months');
		
		destroyCookie('actual_forecast');
		destroyCookie('forecast_name');
		destroyCookie('a');
		destroyCookie('f');
	
		// PDO connection (assuming you have getDB() or similar function in functions.php)
		$db = $pdo;
	
		// 🔍 Get next forecast version
		$stmt = $db->prepare("SELECT MAX(FORECAST_VERSION) as max_version FROM $table_forecasts WHERE ACTUAL_FORECAST = ? AND FORECAST_NAME = ?");
		$stmt->execute([$actual_forecast, $forecast_name]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$forecast_version = ($row['max_version'] ?? 0) + 1;
	
		// 🧾 Build array of month keys (e.g. 'Jun-25') for actual + forecast
		$months = [];
		$monthCursor = clone $cMonth;
		for ($i = 0; $i < $number_of_actual_months + $number_of_forecast_months; $i++) {
			$months[] = $monthCursor->format('M-y');
			$monthCursor->modify('+1 month');
		}
		
		// Helper to normalize incoming keys to your DB's PAY_ELEMENT values
		function mapPayElement(string $k): ?string {
			$k = trim($k);
			if ($k === '') return null;
		
			// Canonicalize: lower + remove spaces + keep underscore for patterns we know
			$canon = strtolower(str_replace([' ', '-'], ['', ''], $k));
		
			// Common aliases -> your DB values
			$map = [
				'base'           => 'base',
				'basic'          => 'base',
		
				'employersni'    => 'employersNI',
				'employer_ni'    => 'employersNI',
				'employers_ni'   => 'employersNI',
		
				'oncall'         => 'onCall',
				'on_call'        => 'onCall',
		
				'overtime'       => 'overtime',
		
				'bonus'          => 'bonus',
				'commission'     => 'commission',
		
				'other'          => 'other',
				'welfare'        => 'welfare',
				'pension'        => 'pension',
		
				'employeecosts'  => 'employeeCosts',
				'employee_costs' => 'employeeCosts',
		
				'totals'         => null,      // ignore any computed totals if present
				'totalcosts'     => null,
			];
		
			return $map[$canon] ?? $k; // default to original if not in map
		}
		
		// 🟩 Insert each resource’s monthly values (ALL elements present)
		foreach ($resources as $resource) {
			$ref_id = $resource['ref'] ?? null;
			$type   = 'resource';
		
			foreach ($months as $index => $monthKey) {
				$isActual = $index < $number_of_actual_months;
		
				// Pick the month's bucket from actuals or outturn
				$bucket = $isActual
					? ($resource['actuals'][$monthKey] ?? [])
					: ($resource['outturn'][$monthKey] ?? []);
		
				if (!is_array($bucket)) continue;
		
				foreach ($bucket as $rawElement => $rawVal) {
					$element = mapPayElement((string)$rawElement);
					if ($element === null) continue; // skip totals/unknowns if you want
		
					$value = round((float)$rawVal, 2);
		
					$stmt = $db->prepare("
						INSERT INTO $table_forecasts 
							(ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION, ROLE_REFERENCE, TYPE, PAY_ELEMENT, IS_ACTUAL, MONTH, VALUE)
						VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
					");
					$stmt->execute([
						$actual_forecast,
						$forecast_name,
						$forecast_version,
						$ref_id,
						$type,
						$element,
						$isActual ? 1 : 0,
						$monthKey,
						$value
					]);
				}
			}
		}
	
		// 🟨 Insert each role’s monthly values (ALL elements present)
		foreach ($roles as $role) {
			$ref_id = $role['ref'] ?? null;
			$type   = 'role';
		
			// only include the role if it is an unfilled role
			if (($role['filledReference'] ?? 1) != 0) continue;
		
			foreach ($months as $index => $monthKey) {
				$isActual = $index < $number_of_actual_months;
		
				$bucket = $isActual
					? ($role['actuals'][$monthKey] ?? [])
					: ($role['outturn'][$monthKey] ?? []);
		
				if (!is_array($bucket)) continue;
		
				foreach ($bucket as $rawElement => $rawVal) {
					$element = mapPayElement((string)$rawElement);
					if ($element === null) continue;
		
					$value = round((float)$rawVal, 2);
		
					$stmt = $db->prepare("
						INSERT INTO $table_forecasts 
							(ACTUAL_FORECAST, FORECAST_NAME, FORECAST_VERSION, ROLE_REFERENCE, TYPE, PAY_ELEMENT, IS_ACTUAL, MONTH, VALUE)
						VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
					");
					$stmt->execute([
						$actual_forecast,
						$forecast_name,
						$forecast_version,
						$ref_id,
						$type,
						$element,
						$isActual ? 1 : 0,
						$monthKey,
						$value
					]);
				}
			}
		}
	
		// ✅ Respond back to JS
		echo json_encode([
			"status" => "ok",
			"forecast_name" => $forecast_name,
			"version" => $forecast_version,
			"resources_saved" => count($resources),
			"roles_saved" => count($roles)
		]);
	
	} else {
		echo json_encode([
			"status" => "unauthorized",
			"message" => "You're not logged in so shouldn't have been able to reach this page. Please visit https://accelulator.com and try again."
		]);
	}
} catch (Throwable $e) {
	echo json_encode([
		"status" => "error",
		"message" => "Server error",
		"details" => $e->getMessage()
	]);
}
?>