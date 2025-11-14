<?php
session_start();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loadPhpSpreadsheet.php';// load encryption helpers (for enc_field, name_tag, company_data_key, etc.)
$cryptoPath = __DIR__ . '/../includes/crypto.php';
if (is_file($cryptoPath)) {
	require_once $cryptoPath;
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// Ensure PhpSpreadsheet is available (and capture what was tried)
[$__ppsLoaded, $__ppsTried] = ensurePhpSpreadsheetLoaded();
if (!$__ppsLoaded) {
	// In normal mode keep the old UX:
	if (!isset($_GET['debug'])) {
		http_response_code(500);
		echo 'There was an error processing the file.';
		return;
	}
	// In debug mode: return JSON with detail
	header('Content-Type: application/json; charset=utf-8');
	http_response_code(500);
	echo json_encode([
		'ok'    => false,
		'where' => 'bootstrap',
		'error' => "Could not load PhpSpreadsheet (IOFactory missing)",
		'tried' => $__ppsTried,
	], JSON_PRETTY_PRINT);
	return;
}

// FUNCTION FOR NORMALISATION
$norm = static function($s, int $maxLen = 255) {
	if (!is_string($s)) return '';

	$s = preg_replace('/^[\x00-\x1F\x7F]+/u', '', $s); // only at the start
	$s = trim($s);
	$s = mb_strtolower($s, 'UTF-8');

	return mb_substr($s, 0, $maxLen);
};

$user = checkUser();
$ref  = getUsersCompanyId($user);

// ✅ Allow 0, reject null/empty/non-numeric to avoid bad table names
if ($ref === null || $ref === '' || !ctype_digit((string)$ref)) {
	http_response_code(400);
	echo 'There was an error processing the file.'; // keep UI message stable
	// (Optionally log details server-side)
	return;
}

$table_actuals         = $ref . "_actuals";
$table_resources       = $ref . "_resources";
$table_details         = $ref . "_details";
$table_payroll_library = $ref . "_payroll_library";
$table_paytype         = $ref . "_paytype";

// === PREFETCH / HELPERS ===============================================

// 1) Build a fast map: payroll number -> EMP_KEY (from *_payroll_library)
$empByPayroll = [];
$stmt = $pdo->query("SELECT PAYROLL_NUMBER, EMP_KEY FROM $table_payroll_library");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
	$empByPayroll[(string)$r['PAYROLL_NUMBER']] = (int)$r['EMP_KEY'];
}

// 2) Helper to fetch-or-create the PAYTYPE_GROUP_REF for a given description/value
$getGroupRef = function (?string $desc) use ($pdo, $table_paytype): int {
	$d = trim((string)$desc);
	if ($d === '') return 1; // default group if blank row

	// 1. try by DESCRIPTION (case-insensitive)
	$q = $pdo->prepare("SELECT REF
						FROM $table_paytype
						WHERE LOWER(DESCRIPTION) = LOWER(:d)
						LIMIT 1");
	$q->execute([':d' => $d]);
	$ref = $q->fetchColumn();
	if ($ref !== false) {
		return (int)$ref;
	}

	// 2. try by VALUE (some companies might be using the normalised form)
	$q = $pdo->prepare("SELECT REF
						FROM $table_paytype
						WHERE LOWER(VALUE) = LOWER(:v)
						LIMIT 1");
	$norm = strtolower(preg_replace('/[^a-z0-9]+/', '', $d));
	$q->execute([':v' => $norm]);
	$ref = $q->fetchColumn();
	if ($ref !== false) {
		return (int)$ref;
	}

	// 3. not found – create it in this company's paytype table

	// because the table was cloned with CTAS, it probably doesn't have AUTO_INCREMENT,
	// so we grab the next ref ourselves
	$maxQ = $pdo->query("SELECT COALESCE(MAX(REF), 0) FROM $table_paytype");
	$nextRef = (int)$maxQ->fetchColumn() + 1;

	$ins = $pdo->prepare("
		INSERT INTO $table_paytype (REF, DESCRIPTION, VALUE, PAYTYPE_GROUP_REF)
		VALUES (:ref, :desc, :val, :grp)
	");
	$ins->execute([
		':ref'  => $nextRef,
		':desc' => $d,               // as-is from spreadsheet
		':val'  => $norm,            // lower, no special chars
		':grp'  => 11,                // default group – user can change later
	]);

	return $nextRef;
};

// 3) Small helper to coerce “GBP” to a numeric float (handles 2,600.00, £2,600 etc.)
$toMoney = static function ($v): float {
	if (is_numeric($v)) return (float)$v;
	if (!is_string($v)) return 0.0;
	// strip everything except digits, dot, minus
	$clean = preg_replace('/[^\d\.\-]/', '', $v);
	// if there are multiple dots, keep only the last as decimal separator
	if (substr_count($clean, '.') > 1) {
		$last = strrpos($clean, '.');
		$clean = preg_replace('/\./', '', substr($clean, 0, $last)) . substr($clean, $last);
	}
	return (float)$clean;
};

// Make sure a file was uploaded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['spreadsheet'])) {
	$uploadedFile = $_FILES['spreadsheet']['tmp_name'];

	// ✅ Basic sanity on the upload
	if (!is_uploaded_file($uploadedFile) || !is_readable($uploadedFile)) {
		http_response_code(400);
		echo 'There was an error processing the file.';
		return;
	}

	try {
		// Load the spreadsheet (this can throw Error if class not loaded; we now guaranteed it)
		$spreadsheet = IOFactory::load($uploadedFile);
		$sheet = $spreadsheet->getActiveSheet();
		$rows  = $sheet->toArray();

		if (!$rows || !isset($rows[0])) {
			echo 'There was an error processing the file.';
			return;
		}

		$header = $rows[0]; // first row = header
		$rowCount = 0; $newEmployees = [];

		$pdo->beginTransaction(); // ✅ Start transaction

		for ($i = 1; $i < count($rows); $i++){
			$row = $rows[$i];
		
			// Skip empty rows
			if (empty(array_filter($row))) continue;
		
			// Map header columns to values and make keys case-insensitive
			$data  = array_combine($header, $row);
			$data  = array_change_key_case($data, CASE_UPPER);
		
			// Trim only string values
			foreach ($data as $k => $v) {
				if (is_string($v)) $data[$k] = trim($v);
			}
			
			// get company key once for this row 👇
			$companyRef = (int)$ref;
			$dataKey    = company_data_key($pdo, $companyRef);
			if ($dataKey === '' || strlen($dataKey) !== 32) {
				throw new RuntimeException("Missing/invalid company key for {$companyRef}");
			}
		
			// --- PAYMENT DATE -> MySQL DATETIME ---
			$cell = $data['PAYMENT DATE'] ?? null;
			$mysqlDate = '1980-01-01 00:00:00';
		
			if ($cell instanceof \DateTimeInterface) {
				$mysqlDate = $cell->format('Y-m-d H:i:s');
			} elseif (is_numeric($cell)) {
				$dt = Date::excelToDateTimeObject($cell);
				$mysqlDate = $dt->format('Y-m-d H:i:s');
			} elseif (is_string($cell) && $cell !== '') {
				$parsed = strtotime($cell);
				if ($parsed !== false) $mysqlDate = date('Y-m-d H:i:s', $parsed);
			}
		
			// --- EMP_KEY via payroll library map (normalised & with name fallback) ---
			$rawPN = $data['PAYROLL NUMBER'] ?? '';
			// keep only digits, e.g. "33", "33.0", " 33 " -> "33"
			$pn = preg_replace('/\D+/', '', (string)$rawPN); // empty string if nothing numeric
			
			$empKey = -1;
			if ($pn !== '') {
				// try exact string key, then int->string key
				if (isset($empByPayroll[$pn])) {
					$empKey = (int)$empByPayroll[$pn];
				} else {
					$pnInt = (string) ((int)$pn);
					if (isset($empByPayroll[$pnInt])) {
						$empKey = (int)$empByPayroll[$pnInt];
					}
				}
			}
			
			if ($empKey === -1) {
				// ---- Fallback: try to find existing resource by NAME (encrypted) ----
				$nameStr = (string)($data['NAME'] ?? '');
				$parts   = preg_split('/\s+/', trim($nameStr));
				$firstname  = $parts[0] ?? '';
				$surname    = $parts ? ($parts[count($parts)-1] ?? '') : '';
				$middlename = '';
				if (count($parts) > 2) {
					$middlename = implode(' ', array_slice($parts, 1, -1));
				}
			
				if ($firstname !== '' && $surname !== '') {
					
					// encrypt the incoming names exactly like we store them
					$fnEnc = enc_field($firstname, $dataKey);
					$snEnc = enc_field($surname,   $dataKey);
			
					$q = $pdo->prepare("
						SELECT REF
						FROM $table_resources
						WHERE FIRSTNAME_ENC = :fn_enc
						  AND SURNAME_ENC   = :sn_enc
						LIMIT 1
					");
					$q->execute([
						':fn_enc' => $fnEnc,
						':sn_enc' => $snEnc,
					]);
			
					$foundRef = $q->fetchColumn();
					if ($foundRef !== false) {
						$empKey = (int)$foundRef;
			
						// if this row provided a payroll number, persist the mapping now
						if ($pn !== '') {
							$ins = $pdo->prepare("
								INSERT INTO $table_payroll_library (PAYROLL_NUMBER, EMP_KEY)
								VALUES (:pn, :emp)
								ON DUPLICATE KEY UPDATE EMP_KEY = VALUES(EMP_KEY)
							");
							$ins->execute([':pn' => (int)$pn, ':emp' => $empKey]);
							$empByPayroll[(string)((int)$pn)] = $empKey;
						}
					}
				}
			}
			
			if ($empKey === -1) {
				// Parse name parts if not already parsed
				if (!isset($firstname)) {
					$nameStr = (string)($data['NAME'] ?? '');
					$parts   = preg_split('/\s+/', trim($nameStr));
					$firstname  = $parts[0] ?? 'Empty';
					$surname    = $parts ? ($parts[count($parts)-1] ?? '') : '';
					$middlename = '';
					if (count($parts) > 2) {
						$middlename = implode(' ', array_slice($parts, 1, -1));
					}
				}
			
				$newEmployees[] = trim("$firstname $middlename $surname");
			
				// Annual salary
				$annualSalary = $toMoney($data['GBP'] ?? 0) * 12;
			
				// DOB normalisation
				$dobCell  = $data['DOB'] ?? null;
				$dobMysql = null;
				if ($dobCell instanceof \DateTimeInterface) {
					$dobMysql = $dobCell->format('Y-m-d');
				} elseif (is_numeric($dobCell)) {
					$dt = Date::excelToDateTimeObject($dobCell);
					$dobMysql = $dt->format('Y-m-d');
				} elseif (is_string($dobCell) && $dobCell !== '') {
					$ts = strtotime($dobCell);
					if ($ts !== false) {
						$dobMysql = date('Y-m-d', $ts);
					}
				}
				
				$fnEnc = enc_field($firstname,  $dataKey);
				$mnEnc = enc_field($middlename, $dataKey);
				$snEnc = enc_field($surname,    $dataKey);
				$tag   = name_tag($firstname, $middlename, $surname, $dataKey);
			
				// === Insert resource ================================================
				$stmt = $pdo->prepare("
					INSERT INTO $table_resources (
						SALUTATION, FIRSTNAME_ENC, MIDDLENAME_ENC, SURNAME_ENC, NAME_TAG,
						DOB, DEPARTMENT, CONTRACT_TYPE
					) VALUES (
						:salutation, :fn_enc, :mn_enc, :sn_enc, :tag,
						:dob, :department, :contractType
					)
				");
			
				// bind non-null
				$stmt->bindValue(':salutation',  '');
				$stmt->bindValue(':fn_enc',      $fnEnc);
				$stmt->bindValue(':mn_enc',      $mnEnc);
				$stmt->bindValue(':sn_enc',      $snEnc);
				$stmt->bindValue(':tag',         $tag);
				$stmt->bindValue(':department',  0, PDO::PARAM_INT);
				$stmt->bindValue(':contractType',1, PDO::PARAM_INT);
			
				// 👇 make DOB explicit
				if ($dobMysql === null) {
					$stmt->bindValue(':dob', null, PDO::PARAM_NULL);
				} else {
					$stmt->bindValue(':dob', $dobMysql);
				}
			
				$stmt->execute();
				$empKey = (int)$pdo->lastInsertId();
			
				// details
				$stmt = $pdo->prepare("
					INSERT INTO $table_details (EMP_KEY, START_DATE, END_DATE, ANNUAL_SALARY, FTE)
					VALUES (:empKey, :startDate, '9999-12-31', :annualSalary, '1')
				");
				$stmt->execute([
					':empKey'       => $empKey,
					':startDate'    => $mysqlDate,
					':annualSalary' => $annualSalary,
				]);
			
				// payroll mapping
				if ($pn !== '') {
					$stmt = $pdo->prepare("
						INSERT INTO $table_payroll_library (PAYROLL_NUMBER, EMP_KEY)
						VALUES (:pn, :emp)
					");
					$stmt->execute([':pn' => (int)$pn, ':emp' => $empKey]);
					$empByPayroll[(string)((int)$pn)] = $empKey;
				}
			}
		
			// --- PAY TYPE -> we want the GROUP id (PAYTYPE_GROUP_REF), not the type REF ---
			$typeGroupRef = $getGroupRef($data['TYPE'] ?? '');
		
			// --- GBP value as pure number, ignoring currency/thousands ---
			$amount = $toMoney($data['GBP'] ?? 0);
		
			// --- Insert into actuals ---
			$stmt = $pdo->prepare("
				INSERT INTO $table_actuals
					(DATE, PERIOD, YEAR, EMP_KEY, TYPE, VALUE)
				VALUES
					(:date, :period, :year, :emp_key, :type, :value)
			");
			$stmt->execute([
				':date'    => $mysqlDate,
				':period'  => isset($data['PERIOD']) ? (int)$data['PERIOD'] : null,
				':year'    => isset($data['YEAR'])   ? (int)$data['YEAR']   : null,
				':emp_key' => $empKey,
				':type'    => $typeGroupRef,   // group id (e.g., Base=1, Overtime=2, Employers NI=9)
				':value'   => $amount,         // clean numeric (e.g., 2600.00)
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
		

	} catch (\Throwable $e) {  // catch both Error and Exception
		if ($pdo->inTransaction()) $pdo->rollBack();
	
		$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
	
		if ($debug) {
			header('Content-Type: application/json; charset=utf-8');
			http_response_code(500);
			echo json_encode([
				'ok'   => false,
				'type' => get_class($e),
				'msg'  => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
	
				// quick environment checks
				'phpSpreadsheetLoaded' => class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class),
				'ref'   => $ref,
	
				// tables we built
				'tables' => [
					'actuals'         => $table_actuals ?? null,
					'resources'       => $table_resources ?? null,
					'details'         => $table_details ?? null,
					'payroll_library' => $table_payroll_library ?? null,
					'paytype'         => $table_paytype ?? null,
				],
	
				// upload sanity
				'upload' => [
					'name'     => $_FILES['spreadsheet']['name'] ?? null,
					'type'     => $_FILES['spreadsheet']['type'] ?? null,
					'size'     => $_FILES['spreadsheet']['size'] ?? null,
					'tmp_name' => $_FILES['spreadsheet']['tmp_name'] ?? null,
					'is_uploaded' => isset($_FILES['spreadsheet']['tmp_name']) 
									 ? is_uploaded_file($_FILES['spreadsheet']['tmp_name']) : null,
					'readable' => isset($_FILES['spreadsheet']['tmp_name']) 
								  ? is_readable($_FILES['spreadsheet']['tmp_name']) : null,
				],
	
				// optional: trimmed stack for deeper issues
				'trace' => explode("\n", $e->getTraceAsString()),
			], JSON_PRETTY_PRINT);
			return; // stop here in debug mode
		}
	
		// non-debug behaviour (what your UI expects)
		error_log('excelUpload: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
		http_response_code(500);
		echo 'There was an error processing the file.';
		return;
	}
	
} else {
	echo "No file uploaded.";
}