<?php
session_start();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loadPhpSpreadsheet.php';

// load encryption helpers (for enc_field, name_tag, company_data_key, etc.)
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

// === BASIC USER + TABLE CONTEXT ======================================

$user = checkUser();
$ref  = getUsersCompanyId($user);

// ✅ Allow 0, reject null/empty/non-numeric to avoid bad table names
if ($ref === null || $ref === '' || !ctype_digit((string)$ref)) {
	http_response_code(400);
	echo 'There was an error processing the file.';
	return;
}

$table_actuals         = $ref . "_actuals";
$table_resources       = $ref . "_resources";
$table_details         = $ref . "_details";
$table_payroll_library = $ref . "_payroll_library";
$table_paytype         = $ref . "_paytype";

// === PREFETCH / HELPERS ===============================================

global $pdo;

// 1) Build a fast map: payroll number -> EMP_KEY (from *_payroll_library)
$empByPayroll = [];
try {
	$stmt = $pdo->query("SELECT PAYROLL_NUMBER, EMP_KEY FROM $table_payroll_library");
	while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$empByPayroll[(string)$r['PAYROLL_NUMBER']] = (int)$r['EMP_KEY'];
	}
} catch (\Throwable $e) {
	// If this fails, better to stop early
	error_log('excelAdvancedUpload (prefetch payroll_library): ' . $e->getMessage());
}

// 2) Helper to fetch-or-create the PAYTYPE_GROUP_REF for a given description/value
$getGroupRef = function (?string $desc) use ($pdo, $table_paytype): int {
	$d = trim((string)$desc);
	if ($d === '') return 1; // default group if blank

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

	// 2. try by VALUE (normalised form)
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
	$maxQ = $pdo->query("SELECT COALESCE(MAX(REF), 0) FROM $table_paytype");
	$nextRef = (int)$maxQ->fetchColumn() + 1;

	$ins = $pdo->prepare("
		INSERT INTO $table_paytype (REF, DESCRIPTION, VALUE, PAYTYPE_GROUP_REF)
		VALUES (:ref, :desc, :val, :grp)
	");
	$ins->execute([
		':ref'  => $nextRef,
		':desc' => $d,        // as-is from spreadsheet
		':val'  => $norm,     // lower, no special chars
		':grp'  => 1,         // default group – user can change later
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

// 4) Helper to render the mapping form
function excelAdvanced_renderMappingForm(array $header, string $uploadId, ?string $errorMessage = null): void
{
	// Very lightweight inline styling so it’s usable out of the box
	?>
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="utf-8">
		<title>Map your payroll columns</title>
		<style>
			body {
				font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				padding: 1.5rem;
				background: #f5f7fa;
				color: #222;
			}
			h1 {
				font-size: 1.4rem;
				margin-bottom: 0.5rem;
			}
			.small {
				font-size: 0.9rem;
				color: #555;
				margin-bottom: 1.2rem;
			}
			.error {
				background: #ffe5e5;
				color: #b00020;
				padding: 0.75rem 1rem;
				border-radius: 4px;
				margin-bottom: 1rem;
			}
			form {
				background: #fff;
				padding: 1rem 1.25rem;
				border-radius: 6px;
				box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
			}
			fieldset {
				border: none;
				margin: 0 0 1.5rem;
				padding: 0;
			}
			legend {
				font-weight: 600;
				margin-bottom: 0.5rem;
			}
			label {
				display: block;
				margin-bottom: 0.4rem;
				font-size: 0.9rem;
			}
			select, input[type="text"] {
				width: 100%;
				max-width: 320px;
				padding: 0.35rem 0.4rem;
				margin-bottom: 0.7rem;
				border-radius: 4px;
				border: 1px solid #cbd5e1;
				font-size: 0.9rem;
			}
			table {
				border-collapse: collapse;
				width: 100%;
				margin-top: 0.5rem;
				font-size: 0.9rem;
			}
			th, td {
				padding: 0.35rem 0.4rem;
				border-bottom: 1px solid #e2e8f0;
			}
			th {
				text-align: left;
				background: #f8fafc;
			}
			.actions {
				margin-top: 1.2rem;
			}
			.btn {
				display: inline-block;
				padding: 0.45rem 0.9rem;
				border-radius: 4px;
				border: none;
				cursor: pointer;
				font-size: 0.9rem;
			}
			.btn-primary {
				background: #2563eb;
				color: #fff;
			}
		</style>
	</head>
	<body>
		<h1>Map your payroll columns</h1>
		<p class="small">
			Tell Accelulator which columns in your spreadsheet contain the key fields
			(date, payroll number, name, etc.) and which columns are pay values.
		</p>

		<?php if ($errorMessage): ?>
			<div class="error"><?= htmlspecialchars($errorMessage) ?></div>
		<?php endif; ?>

		<form method="post" action="">
			<input type="hidden" name="step" value="process">
			<input type="hidden" name="upload_id" value="<?= htmlspecialchars($uploadId) ?>">

			<fieldset>
				<legend>Core columns</legend>

				<label>
					Payment date (required)
					<select name="map[PAYMENT_DATE]" required>
						<option value="">-- Choose a column --</option>
						<?php foreach ($header as $col): ?>
							<option value="<?= htmlspecialchars($col) ?>">
								<?= htmlspecialchars($col) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					Payroll number (required)
					<select name="map[PAYROLL_NUMBER]" required>
						<option value="">-- Choose a column --</option>
						<?php foreach ($header as $col): ?>
							<option value="<?= htmlspecialchars($col) ?>">
								<?= htmlspecialchars($col) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					Employee name (required)
					<select name="map[NAME]" required>
						<option value="">-- Choose a column --</option>
						<?php foreach ($header as $col): ?>
							<option value="<?= htmlspecialchars($col) ?>">
								<?= htmlspecialchars($col) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					Period (optional)
					<select name="map[PERIOD]">
						<option value="">-- Not present --</option>
						<?php foreach ($header as $col): ?>
							<option value="<?= htmlspecialchars($col) ?>">
								<?= htmlspecialchars($col) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					Year (optional)
					<select name="map[YEAR]">
						<option value="">-- Not present --</option>
						<?php foreach ($header as $col): ?>
							<option value="<?= htmlspecialchars($col) ?>">
								<?= htmlspecialchars($col) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					Date of birth (optional)
					<select name="map[DOB]">
						<option value="">-- Not present --</option>
						<?php foreach ($header as $col): ?>
							<option value="<?= htmlspecialchars($col) ?>">
								<?= htmlspecialchars($col) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			</fieldset>

			<fieldset>
				<legend>Value columns (pay elements)</legend>
				<p class="small">
					Tick each column that contains a numeric pay value.
					The label will be used to create or match a pay type (Base, Overtime, Employers NI, etc).
				</p>

				<table>
					<thead>
						<tr>
							<th>Column header</th>
							<th>Use as value?</th>
							<th>Pay type label</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($header as $idx => $col): ?>
						<tr>
							<td><?= htmlspecialchars($col) ?></td>
							<td>
								<input type="checkbox"
									   name="values[<?= (int)$idx ?>][enabled]"
									   value="1">
							</td>
							<td>
								<input type="text"
									   name="values[<?= (int)$idx ?>][label]"
									   value="<?= htmlspecialchars($col) ?>">
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</fieldset>

			<div class="actions">
				<button type="submit" class="btn btn-primary">
					Import with this mapping
				</button>
			</div>
		</form>
	</body>
	</html>
	<?php
}

// === MAIN CONTROLLER =================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	echo "No file uploaded.";
	return;
}

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

try {

	// STEP 2: process mapped import
	if (isset($_POST['step']) && $_POST['step'] === 'process') {

		$uploadId = $_POST['upload_id'] ?? '';
		if ($uploadId === '' || !isset($_SESSION['excel_advanced_uploads'][$uploadId])) {
			throw new RuntimeException('Upload session has expired or is invalid.');
		}

		$filePath = $_SESSION['excel_advanced_uploads'][$uploadId];
		if (!is_file($filePath) || !is_readable($filePath)) {
			throw new RuntimeException('Uploaded file could not be read.');
		}

		// Load spreadsheet again
		$spreadsheet = IOFactory::load($filePath);
		$sheet       = $spreadsheet->getActiveSheet();
		$rows        = $sheet->toArray();

		if (!$rows || !isset($rows[0])) {
			throw new RuntimeException('Spreadsheet appears to be empty.');
		}

		$header = $rows[0];

		// Read mapping config
		$map    = $_POST['map']    ?? [];
		$values = $_POST['values'] ?? [];

		$paymentDateCol = $map['PAYMENT_DATE']   ?? '';
		$payrollNoCol   = $map['PAYROLL_NUMBER'] ?? '';
		$nameCol        = $map['NAME']           ?? '';

		if ($paymentDateCol === '' || $payrollNoCol === '' || $nameCol === '') {
			excelAdvanced_renderMappingForm(
				$header,
				$uploadId,
				'Please map at least Payment date, Payroll number and Employee name.'
			);
			return;
		}

		$periodCol = $map['PERIOD'] ?? '';
		$yearCol   = $map['YEAR']   ?? '';
		$dobCol    = $map['DOB']    ?? '';

		// Pre-calc lookup from header name -> index
		$headerIndex = [];
		foreach ($header as $idx => $colName) {
			$headerIndex[$colName] = $idx;
		}

		// Company encryption key (once, not per row)
		$companyRef = (int)$ref;
		$dataKey    = company_data_key($pdo, $companyRef);
		if ($dataKey === '' || strlen($dataKey) !== 32) {
			throw new RuntimeException("Missing/invalid company key for {$companyRef}");
		}

		$rowCount      = 0;   // count of actuals rows inserted
		$newEmployees  = [];

		$pdo->beginTransaction();

		// Process each data row
		for ($i = 1; $i < count($rows); $i++) {
			$row = $rows[$i];

			// Skip completely empty rows
			if (empty(array_filter($row, static fn($v) => $v !== null && $v !== ''))) {
				continue;
			}

			// Map index -> value for convenience
			$rowByIdx = $row;

			// Helper to get a cell by header name safely
			$getCell = static function(string $colName, array $headerIndex, array $rowByIdx) {
				if (!isset($headerIndex[$colName])) return null;
				$idx = $headerIndex[$colName];
				return $rowByIdx[$idx] ?? null;
			};

			// --- PAYMENT DATE -> MySQL DATETIME ---
			$cell      = $getCell($paymentDateCol, $headerIndex, $rowByIdx);
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

			// --- PAYROLL NUMBER -> EMP_KEY via payroll library map (with fallback) ---
			global $empByPayroll;
			$rawPN = (string)($getCell($payrollNoCol, $headerIndex, $rowByIdx) ?? '');
			$pn    = preg_replace('/\D+/', '', $rawPN); // digits only

			$empKey = -1;
			if ($pn !== '') {
				if (isset($empByPayroll[$pn])) {
					$empKey = (int)$empByPayroll[$pn];
				} else {
					$pnInt = (string)((int)$pn);
					if (isset($empByPayroll[$pnInt])) {
						$empKey = (int)$empByPayroll[$pnInt];
					}
				}
			}

			// --- Fallback: match by encrypted name ---
			$nameStr = (string)($getCell($nameCol, $headerIndex, $rowByIdx) ?? '');
			$parts   = preg_split('/\s+/', trim($nameStr));

			$firstname  = $parts[0] ?? '';
			$surname    = $parts ? ($parts[count($parts)-1] ?? '') : '';
			$middlename = '';
			if (count($parts) > 2) {
				$middlename = implode(' ', array_slice($parts, 1, -1));
			}

			if ($empKey === -1 && $firstname !== '' && $surname !== '') {
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

					// if this row provided a payroll number, persist the mapping
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

			// --- Still not found? Create a new resource + details row ---
			if ($empKey === -1) {
				if ($firstname === '') {
					$firstname = 'Empty';
				}

				// DOB normalisation
				$dobMysql = null;
				if ($dobCol !== '') {
					$dobCell = $getCell($dobCol, $headerIndex, $rowByIdx);
					if ($dobCell instanceof \DateTimeInterface) {
						$dobMysql = $dobCell->format('Y-m-d');
					} elseif (is_numeric($dobCell)) {
						$dt      = Date::excelToDateTimeObject($dobCell);
						$dobMysql = $dt->format('Y-m-d');
					} elseif (is_string($dobCell) && $dobCell !== '') {
						$ts = strtotime($dobCell);
						if ($ts !== false) {
							$dobMysql = date('Y-m-d', $ts);
						}
					}
				}

				$fnEnc = enc_field($firstname,  $dataKey);
				$mnEnc = enc_field($middlename, $dataKey);
				$snEnc = enc_field($surname,    $dataKey);
				$tag   = name_tag($firstname, $middlename, $surname, $dataKey);

				$newEmployees[] = trim("$firstname $middlename $surname");

				// Create resource
				$stmtRes = $pdo->prepare("
					INSERT INTO $table_resources (
						SALUTATION, FIRSTNAME_ENC, MIDDLENAME_ENC, SURNAME_ENC, NAME_TAG,
						DOB, DEPARTMENT, CONTRACT_TYPE
					) VALUES (
						:salutation, :fn_enc, :mn_enc, :sn_enc, :tag,
						:dob, :department, :contractType
					)
				");

				$stmtRes->bindValue(':salutation',  '');
				$stmtRes->bindValue(':fn_enc',      $fnEnc);
				$stmtRes->bindValue(':mn_enc',      $mnEnc);
				$stmtRes->bindValue(':sn_enc',      $snEnc);
				$stmtRes->bindValue(':tag',         $tag);
				$stmtRes->bindValue(':department',  0, PDO::PARAM_INT);
				$stmtRes->bindValue(':contractType',1, PDO::PARAM_INT);

				if ($dobMysql === null) {
					$stmtRes->bindValue(':dob', null, PDO::PARAM_NULL);
				} else {
					$stmtRes->bindValue(':dob', $dobMysql);
				}

				$stmtRes->execute();
				$empKey = (int)$pdo->lastInsertId();

				// Annual salary – here we don’t know which column is salary,
				// so default to 0; you can refine this later if needed.
				$annualSalary = 0.0;

				$stmtDet = $pdo->prepare("
					INSERT INTO $table_details (EMP_KEY, START_DATE, END_DATE, ANNUAL_SALARY, FTE)
					VALUES (:empKey, :startDate, '9999-12-31', :annualSalary, '1')
				");
				$stmtDet->execute([
					':empKey'       => $empKey,
					':startDate'    => $mysqlDate,
					':annualSalary' => $annualSalary,
				]);

				// payroll mapping
				if ($pn !== '') {
					$stmtPL = $pdo->prepare("
						INSERT INTO $table_payroll_library (PAYROLL_NUMBER, EMP_KEY)
						VALUES (:pn, :emp)
					");
					$stmtPL->execute([':pn' => (int)$pn, ':emp' => $empKey]);
					$empByPayroll[(string)((int)$pn)] = $empKey;
				}
			}

			// Period / year
			$periodVal = null;
			$yearVal   = null;

			if ($periodCol !== '' && isset($headerIndex[$periodCol])) {
				$rawPeriod = $getCell($periodCol, $headerIndex, $rowByIdx);
				if ($rawPeriod !== null && $rawPeriod !== '') {
					$periodVal = (int)$rawPeriod;
				}
			}
			if ($yearCol !== '' && isset($headerIndex[$yearCol])) {
				$rawYear = $getCell($yearCol, $headerIndex, $rowByIdx);
				if ($rawYear !== null && $rawYear !== '') {
					$yearVal = (int)$rawYear;
				}
			}

			// --- VALUE COLUMNS: one actuals row per selected column ---
			foreach ($values as $idx => $cfg) {
				if (empty($cfg['enabled'])) {
					continue;
				}

				$idx = (int)$idx;
				if (!isset($header[$idx])) {
					continue;
				}

				$colName      = $header[$idx];
				$paytypeLabel = trim($cfg['label'] ?? $colName);
				if ($paytypeLabel === '') {
					$paytypeLabel = $colName;
				}

				$typeGroupRef = $GLOBALS['getGroupRef']($paytypeLabel); // not directly callable inside closure

				// Access the cell by index
				$rawVal = $rowByIdx[$idx] ?? 0;
				$amount = $GLOBALS['toMoney']($rawVal);
				if ($amount == 0.0) {
					continue; // skip empty/zero entries
				}

				$stmtAct = $pdo->prepare("
					INSERT INTO $table_actuals
						(DATE, PERIOD, YEAR, EMP_KEY, TYPE, VALUE)
					VALUES
						(:date, :period, :year, :emp_key, :type, :value)
				");
				$stmtAct->execute([
					':date'    => $mysqlDate,
					':period'  => $periodVal,
					':year'    => $yearVal,
					':emp_key' => $empKey,
					':type'    => $typeGroupRef,
					':value'   => $amount,
				]);

				$rowCount++;
			}
		}

		$pdo->commit();

		// Clear temp file + session entry
		unset($_SESSION['excel_advanced_uploads'][$uploadId]);
		@unlink($filePath);

		$plural = ($rowCount === 1) ? '' : 's';
		echo "Imported $rowCount row$plural into the database.";

		if (count($newEmployees) > 0) {
			echo "<br><br>New employees:<br>";
			foreach ($newEmployees as $name) {
				echo htmlspecialchars($name) . "<br>";
			}
		}

		return;
	}

	// STEP 1: user has just uploaded a file and we need to show the mapping form
	if (isset($_FILES['spreadsheet'])) {

		$uploadedFile = $_FILES['spreadsheet']['tmp_name'];

		if (!is_uploaded_file($uploadedFile) || !is_readable($uploadedFile)) {
			http_response_code(400);
			echo 'There was an error processing the file.';
			return;
		}

		// Move to a temp file so we can reuse it after mapping
		$tmpDir   = sys_get_temp_dir();
		$uploadId = bin2hex(random_bytes(16));
		$target   = $tmpDir . DIRECTORY_SEPARATOR . 'accelulator_adv_' . $uploadId . '.xlsx';

		if (!move_uploaded_file($uploadedFile, $target)) {
			throw new RuntimeException('Failed to store uploaded file.');
		}

		// Load header row
		$spreadsheet = IOFactory::load($target);
		$sheet       = $spreadsheet->getActiveSheet();
		$rows        = $sheet->toArray();

		if (!$rows || !isset($rows[0])) {
			throw new RuntimeException('Spreadsheet appears to be empty.');
		}

		$header = $rows[0];

		if (!is_array($header) || count($header) === 0) {
			throw new RuntimeException('Could not read column headers from the spreadsheet.');
		}

		// Store file path in session keyed by uploadId
		if (!isset($_SESSION['excel_advanced_uploads'])) {
			$_SESSION['excel_advanced_uploads'] = [];
		}
		$_SESSION['excel_advanced_uploads'][$uploadId] = $target;

		// Render mapping form and stop
		excelAdvanced_renderMappingForm($header, $uploadId, null);
		return;
	}

	echo "No file uploaded.";
	return;

} catch (\Throwable $e) {

	if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
		$pdo->rollBack();
	}

	if ($debug) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(500);

		echo json_encode([
			'ok'   => false,
			'type' => get_class($e),
			'msg'  => $e->getMessage(),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'phpSpreadsheetLoaded' => class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class),
			'ref'  => $ref,
		], JSON_PRETTY_PRINT);
		return;
	}

	error_log('excelAdvancedUpload: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
	http_response_code(500);
	echo 'There was an error processing the file.';
	return;
}