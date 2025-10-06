<?php
session_start();
require_once('../includes/functions.php');
// Load autoloader
require_once __DIR__ . '/../libs/PhpSpreadsheet/autoloader.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// FUNCTION FOR NORMALISATION
$norm = static function($s, int $maxLen = 255){
	if (!is_string($s)) return '';
	$s = trim($s);
	// remove non-printable control chars except tab/newline
	$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
	// hard length cap per schema
	return mb_substr($s, 0, $maxLen);
};


$user = checkUser();
$ref = getUsersCompanyId($user);
$table_actuals = $ref . "_actuals";
$table_resources = $ref . "_resources";
$table_details = $ref . "_details";
$table_payroll_library = $ref . "_payroll_library";
$table_paytype = $ref . "_paytype";

$libraryData = [];
$stmt = $pdo->prepare("SELECT * FROM $table_payroll_library");
$stmt->execute();
$libraryData = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
$paytypeData = [];
$stmt = $pdo->prepare("SELECT * FROM $table_paytype");
$stmt->execute();
$paytypeData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Make sure a file was uploaded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['spreadsheet'])) {
	$uploadedFile = $_FILES['spreadsheet']['tmp_name'];

	try {
		// Load the spreadsheet
		$spreadsheet = IOFactory::load($uploadedFile);
		$sheet = $spreadsheet->getActiveSheet();
		$rows = $sheet->toArray();
		
		$header = $rows[0]; // first row = header
		$rowCount = 0;		$newEmployees = [];

		$pdo->beginTransaction(); // ✅ Start transaction

		for ($i = 1; $i < count($rows); $i++){
			$row = $rows[$i];
			
			// Skip empty rows
			if (empty(array_filter($row))) continue;
			
			// Map header columns to values
			$data = array_combine($header, $row);
			$data = array_map('trim', $data);
			
			// Convert Excel date to SQL date
			$rawDate = $norm($data['PAYMENT DATE'] ?? null);
			$mysqlDate = '1980-01-01 00:00:00';
			
			if ($rawDate instanceof \DateTime) {
				$mysqlDate = $rawDate->format('Y-m-d H:i:s');
			} elseif (is_numeric($rawDate)) {
				$dt = Date::excelToDateTimeObject($rawDate);
				$mysqlDate = $dt->format('Y-m-d H:i:s');
			} elseif (!empty($rawDate)) {
				$parsed = strtotime($rawDate);
				if ($parsed) {
					$mysqlDate = date('Y-m-d H:i:s', $parsed);
				}
			}
			
			$userPayType = $norm($data['TYPE'] ?? 'Unidentified',60);
			$payTypeRef = -1;
			
			foreach ($paytypeData as $pt){
				if(strtolower($pt['DESCRIPTION']) == strtolower($userPayType)){
					$payTypeRef = $pt['REF'];
				}
			}
			
			if($payTypeRef == -1){
				$userValue = strtolower($norm($data['TYPE']));
				$groupRef = 11;
				
				$stmt = $pdo->prepare("INSERT INTO $table_paytype (DESCRIPTION,VALUE,PAYTYPE_GROUP_REF) VALUES (:description,:value,:group)");
				$stmt->execute([
					':description'	=> $userPayType,
					':value'		=> $userValue,
					':group'		=> $groupRef,
				]);
				
				$payTypeRef = $pdo->lastInsertId();
			
			}
			
			$payrollNumber = $data['PAYROLL NUMBER'];
			$empKey = -1;
			
			foreach ($libraryData as $entry) {
				if ($entry['PAYROLL_NUMBER'] == $payrollNumber) {
					$empKey = $entry['EMP_KEY'];
					break;
				}
			}
			
			if ($empKey == -1) {
				$names = explode(' ', $data['NAME']);
				$namesLength = count($names);
				if ($namesLength >= 2) {
					$firstname = array_shift($names);
					$surname = array_pop($names);
					$middlename = implode(' ', $names);
				} else {
					$firstname = $data['NAME'] ?? 'Empty';
					$middlename = '';
					$surname = '';
				}
				
				$fullName = trim("$firstname $middlename $surname");
				$newEmployees[] = $fullName;
				
				$annualSalary = $data['GBP'] * 12;
			
				$stmt = $pdo->prepare("INSERT INTO $table_resources (SALUTATION,FIRSTNAME,MIDDLENAME,SURNAME,DOB,ROLE,USERKEY,DEPARTMENT,CONTRACT_TYPE) VALUES (:salutation,:firstname,:middlename,:surname,:dob,:role,:userkey,:department,:contractType)");
				$stmt->execute([
					':salutation'	=> '',
					':firstname'	=> $firstname,
					':middlename'	=> $middlename,
					':surname'		=> $surname,
					':dob'			=> '',
					':role'			=> '',
					':userkey'		=> '',
					':department'	=> 0,
					':contractType'	=> 1,
				]);
			
				$empKey = $pdo->lastInsertId();
			
				$stmt = $pdo->prepare("INSERT INTO $table_details (EMP_KEY,START_DATE,END_DATE,ANNUAL_SALARY,FTE) VALUES (:empKey,:startDate,:endDate,:annualSalary,:fte)");
				$stmt->execute([
					':empKey'		=> $empKey,
					':startDate'	=> $mysqlDate,
					':endDate'		=> '9999-12-31',
					':annualSalary'	=> $annualSalary,
					':fte'			=> '1',
				]);
				
				$stmt = $pdo->prepare("INSERT INTO $table_payroll_library (PAYROLL_NUMBER,EMP_KEY) VALUES (:payrollNumber,:empKey)");
				$stmt->execute([
					':payrollNumber'	=>	$payrollNumber,
					':empKey'			=>	$empKey,
				]);
				
				// Update payroll library to prevent duplicates
				$libraryData[] = [
					'PAYROLL_NUMBER' => $payrollNumber,
					'EMP_KEY' => $empKey,
				];
				
				// Update paytype to prevent duplicates
				$paytypeData[] = [
					'DESCRIPTION'		=> $userPayType,
					'VALUE'				=> $userValue,
					'PAYTYPE_GROUP_REF'	=> $groupRef,
				];
			}
			
			// Insert actuals
			$stmt = $pdo->prepare("INSERT INTO $table_actuals (DATE,PERIOD,YEAR,EMP_KEY,TYPE,VALUE) VALUES (:date, :period, :year, :emp_key, :type, :value)");
			$stmt->execute([
				':date'		=> $mysqlDate,
				':period'	=> $data['PERIOD'] ?? '',
				':year'		=> $data['YEAR'] ?? '',
				':emp_key'	=> $empKey,
				':type'		=> $payTypeRef ?? '',
				':value'	=> $data['GBP'] ?? '',
			]);
			
			$rowCount++;
		}

		$pdo->commit(); // ✅ Commit if everything succeeded

		$plural = ($rowCount > 1) ? 's' : '';
		echo "Imported $rowCount row$plural into the database.";
		
		if (count($newEmployees) > 0) {
			echo "<br><br>New employees:<br>";
			foreach ($newEmployees as $name) {
				echo htmlspecialchars($name) . "<br>";
			}
		}
		
	} catch (Exception $e) {
		$pdo->rollBack(); // ❌ Roll back on any error
		echo "Error importing spreadsheet: " . htmlspecialchars($e->getMessage());
	}
} else {
	echo "No file uploaded.";
}