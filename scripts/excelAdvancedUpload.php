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

// Simple flag to enable verbose error output
$debug = (($_GET['debug'] ?? '') === '1' || ($_POST['debug'] ?? '') === '1');

// Ensure PhpSpreadsheet is available (and capture what was tried)
[$__ppsLoaded, $__ppsTried] = ensurePhpSpreadsheetLoaded();
if (!$__ppsLoaded) {
	if (!$debug) {
		http_response_code(500);
		echo 'There was an error processing the file.';
		return;
	}

	http_response_code(500);
	$payload = [
		'ok'    => false,
		'where' => 'bootstrap',
		'error' => "Could not load PhpSpreadsheet (IOFactory missing)",
		'tried' => $__ppsTried,
	];

	// Visible in the injected HTML
	echo '<pre>' . htmlspecialchars(print_r($payload, true)) . '</pre>';
	// And in the browser console
	echo '<script>console.error(' . json_encode($payload) . ');</script>';
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

$table_actuals			= $ref . "_actuals";
$table_resources		= $ref . "_resources";
$table_details			= $ref . "_details";
$table_payroll_library	= $ref . "_payroll_library";
$table_paytype			= $ref . "_paytype";
$table_paytype_group	= $ref . "_paytype_group";

$paytypeGroups			= [];
try {
	$stmt = $pdo->query("SELECT REF, PAYTYPEGROUP FROM $table_paytype_group ORDER BY REF");
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$paytypeGroups[] = [
			'REF'         => (int)$row['REF'],
			'DESCRIPTION' => (string)$row['PAYTYPEGROUP'],
		];
	}
} catch (\Throwable $e) {
	error_log('excelAdvancedUpload: failed to fetch paytype groups: '.$e->getMessage());
	// fallback – you can make this smarter later
	$paytypeGroups = [];
}

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
$getGroupRef = function (?string $desc, ?int $groupRef = null) use ($pdo, $table_paytype): int {
	$d = trim((string)$desc);
	if ($d === '') return 1; // fallback if label is blank

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
	$maxQ    = $pdo->query("SELECT COALESCE(MAX(REF), 0) FROM $table_paytype");
	$nextRef = (int)$maxQ->fetchColumn() + 1;

	$ins = $pdo->prepare("
		INSERT INTO $table_paytype (REF, DESCRIPTION, VALUE, PAYTYPE_GROUP_REF)
		VALUES (:ref, :desc, :val, :grp)
	");
	$ins->execute([
		':ref'  => $nextRef,
		':desc' => $d,          // as-is from spreadsheet
		':val'  => $norm,       // lower, no special chars
		':grp'  => $groupRef ?: 11,   // use user choice, else default 11
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
function excelAdvanced_renderMappingForm(
	array $header,
	string $uploadId,
	?string $errorMessage = null,
	array $paytypeGroups = []
): void
{
	global $debug;
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
			.nameModeRow {
				margin: 0.5rem 0 0.75rem;
				font-size: 0.9rem;
			}
			.nameModeRow span {
				display: block;
				margin-bottom: 0.25rem;
				color: #444;
			}
			.nameModeRow label {
				display: inline-flex;
				align-items: center;
				margin-right: 1rem;
				font-size: 0.85rem;
				cursor: pointer;
			}
			.nameModeRow input[type="radio"] {
				margin-right: 0.25rem;
			}
			.hidden {
				display: none;
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

		<form id="advUploadForm" method="post" action="/scripts/excelAdvancedUpload.php">
			<input type="hidden" name="step" value="process">
			<input type="hidden" name="upload_id" value="<?= htmlspecialchars($uploadId) ?>">
			<input type="hidden" name="debug" value="<?= $debug ? '1' : '0' ?>">

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

				<p class="small">
					You can either map a single “Full name” column, or map separate
					First / Middle / Surname columns. At minimum, you must provide either
					a Full name, or both First name and Surname.
				</p>
				
				<div class="nameModeRow">
					<span>How is the name stored in your file?</span>
					<label>
						<input type="radio" name="nameMode" value="single" checked>
						Single full-name column
					</label>
					<label>
						<input type="radio" name="nameMode" value="split">
						Separate first / middle / surname columns
					</label>
				</div>
				
				<div id="fullNameRow">
					<label>
						Full name (optional)
						<select name="map[NAME]">
							<option value="">-- Not present / use split name --</option>
							<?php foreach ($header as $col): ?>
								<option value="<?= htmlspecialchars($col) ?>">
									<?= htmlspecialchars($col) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				
				<div id="splitNameRows" class="hidden">
					<label class="splitNameRow">
						First name (optional)
						<select name="map[FIRSTNAME]">
							<option value="">-- Not present --</option>
							<?php foreach ($header as $col): ?>
								<option value="<?= htmlspecialchars($col) ?>">
									<?= htmlspecialchars($col) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				
					<label class="splitNameRow">
						Middle name(s) (optional)
						<select name="map[MIDDLENAME]">
							<option value="">-- Not present --</option>
							<?php foreach ($header as $col): ?>
								<option value="<?= htmlspecialchars($col) ?>">
									<?= htmlspecialchars($col) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				
					<label class="splitNameRow">
						Surname (optional)
						<select name="map[SURNAME]">
							<option value="">-- Not present --</option>
							<?php foreach ($header as $col): ?>
								<option value="<?= htmlspecialchars($col) ?>">
									<?= htmlspecialchars($col) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>

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
							<th>Category</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($header as $idx => $col): ?>
						<tr data-col-name="<?= htmlspecialchars($col) ?>">
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
							<td>
								<select name="values[<?= (int)$idx ?>][group]">
									<?php foreach ($paytypeGroups as $grp): ?>
										<option value="<?= (int)$grp['REF'] ?>"
											<?= ((int)$grp['REF'] === 11 ? ' selected' : '') ?>>
											<?= htmlspecialchars($grp['DESCRIPTION']) ?>
										</option>
									<?php endforeach; ?>
								</select>
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
		
		<script>
			(function () {
				const fullRow      = document.getElementById('fullNameRow');
				const splitRowsBox = document.getElementById('splitNameRows');
				const modeRadios   = document.querySelectorAll('input[name="nameMode"]');
		
				// --- Name mode toggle (single vs split) ---
				function updateNameMode() {
					const selected = document.querySelector('input[name="nameMode"]:checked');
					const mode = selected ? selected.value : 'single';
		
					if (mode === 'single') {
						if (fullRow)      fullRow.classList.remove('hidden');
						if (splitRowsBox) splitRowsBox.classList.add('hidden');
					} else {
						if (fullRow)      fullRow.classList.add('hidden');
						if (splitRowsBox) splitRowsBox.classList.remove('hidden');
					}
				}
		
				if (modeRadios.length > 0) {
					modeRadios.forEach(function (r) {
						r.addEventListener('change', updateNameMode);
					});
				}
		
				// --- Hide value rows whose columns are used in core mappings ---
				function updateValueColumnVisibility() {
					const used = new Set();
		
					// Any core mapping select
					document.querySelectorAll('select[name^="map["]').forEach(function (sel) {
						const val = sel.value;
						if (val && val.trim() !== '') {
							used.add(val.trim());
						}
					});
		
					// Hide/show rows in the value table based on data-col-name
					document.querySelectorAll('table tbody tr[data-col-name]').forEach(function (tr) {
						const colName = (tr.getAttribute('data-col-name') || '').trim();
						if (colName && used.has(colName)) {
							tr.classList.add('hidden');
						} else {
							tr.classList.remove('hidden');
						}
					});
				}
		
				document.querySelectorAll('select[name^="map["]').forEach(function (sel) {
					sel.addEventListener('change', updateValueColumnVisibility);
				});
		
				// --- Intercept form submit and replace form with results ---
				const form = document.getElementById('advUploadForm');
				if (form) {
					form.addEventListener('submit', function (ev) {
						ev.preventDefault();
		
						const formData = new FormData(form);
		
						fetch(form.action, {
							method: 'POST',
							body: formData
						})
						.then(function (resp) {
							return resp.text();
						})
						.then(function (html) {
							// Replace the form (and this script) with the server response
							const container = form.parentNode;
							container.innerHTML = html;
						})
						.catch(function (err) {
							console.error('Advanced upload failed', err);
							alert('There was an error processing the file.');
						});
					});
				}
		
				// Initial state
				updateNameMode();
				updateValueColumnVisibility();
			})();
		</script>
	</body>
	</html>
	<?php
}

// === MAIN CONTROLLER =================================================

try {

	// STEP 2: process mapped import
	if (isset($_POST['step']) && $_POST['step'] === 'process') {
	
		if ($debug) {
			echo '<pre>DEBUG: entered process step' . PHP_EOL;
			echo '$_POST keys: ' . implode(', ', array_keys($_POST)) . PHP_EOL;
			echo 'step = ' . ($_POST['step'] ?? '(missing)') . PHP_EOL;
			echo '</pre>';
		}
		
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
		
		$fullNameCol = $map['NAME']       ?? '';
		$firstCol    = $map['FIRSTNAME']  ?? '';
		$middleCol   = $map['MIDDLENAME'] ?? '';
		$surnameCol  = $map['SURNAME']    ?? '';
		
		$hasFullName  = ($fullNameCol !== '');
		$hasSplitName = ($firstCol !== '' && $surnameCol !== '');
		
		// Decide which mapped value column should drive ANNUAL_SALARY.
		// We look for an enabled column whose label *or header* clearly looks like "Base / Basic pay".
		$salaryColumnIndex = null;
		
		foreach ($values as $idx => $cfg) {
			if (empty($cfg['enabled'])) {
				continue;
			}
		
			$idx = (int)$idx;
		
			// Label from the form (user-editable)
			$label = '';
			if (isset($cfg['label']) && is_string($cfg['label'])) {
				$label = trim($cfg['label']);
			}
		
			// Original header name for this column
			$colName = '';
			if (isset($header[$idx])) {
				$colName = trim((string)$header[$idx]);
			}
		
			// Prefer the explicit label, fall back to header
			$candidates = [];
			if ($label !== '') {
				$candidates[] = $label;
			}
			if ($colName !== '' && $colName !== $label) {
				$candidates[] = $colName;
			}
		
			if ($debug) {
				echo '<pre>DEBUG: salary candidate idx ' . $idx . PHP_EOL
				   . '  label    = ' . var_export($label, true) . PHP_EOL
				   . '  header   = ' . var_export($colName, true) . PHP_EOL;
			}
		
			foreach ($candidates as $cand) {
				// Case-insensitive search on the raw string (no regex)
				$lower = mb_strtolower($cand, 'UTF-8');
		
				if ($debug) {
					echo '  candidate = ' . var_export($cand, true)
					   . ' => lower=' . var_export($lower, true) . PHP_EOL;
				}
		
				if (strpos($lower, 'basic') !== false || strpos($lower, 'base') !== false) {
					$salaryColumnIndex = $idx;
					if ($debug) {
						echo '  -> chosen as salary column' . PHP_EOL;
					}
					break 2; // break out of both foreach loops
				}
			}
		
			if ($debug) {
				echo '</pre>';
			}
		}
		
		if ($debug) {
			echo '<pre>DEBUG: salaryColumnIndex (chosen base column) = '
			   . var_export($salaryColumnIndex, true) . '</pre>';
		}
		
		if ($debug) {
			echo '<pre>DEBUG: mapping received' . PHP_EOL;
			echo 'paymentDateCol = ' . var_export($paymentDateCol, true) . PHP_EOL;
			echo 'payrollNoCol   = ' . var_export($payrollNoCol, true) . PHP_EOL;
			echo 'fullNameCol    = ' . var_export($fullNameCol, true) . PHP_EOL;
			echo 'firstCol       = ' . var_export($firstCol, true) . PHP_EOL;
			echo 'middleCol      = ' . var_export($middleCol, true) . PHP_EOL;
			echo 'surnameCol     = ' . var_export($surnameCol, true) . PHP_EOL;
			echo 'hasFullName    = ' . ($hasFullName ? 'true' : 'false') . PHP_EOL;
			echo 'hasSplitName   = ' . ($hasSplitName ? 'true' : 'false') . PHP_EOL;
			echo 'values (enabled flags):' . PHP_EOL;
			foreach ($values as $idx => $cfg) {
				echo '  idx ' . $idx . ': enabled=' . (!empty($cfg['enabled']) ? '1' : '0')
				   . ', label=' . var_export($cfg['label'] ?? '', true) . PHP_EOL;
			}
			echo '</pre>';
		}
		
		if ($paymentDateCol === '' || $payrollNoCol === '' || (!$hasFullName && !$hasSplitName)) {
			excelAdvanced_renderMappingForm(
				$header,
				$uploadId,
				'Please map Payment date, Payroll number and either a Full name column or both First name and Surname.',
				$paytypeGroups
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
		
		$debugRowsSeen        = 0;
		$debugNonEmptyRows    = 0;
		$debugRowsWithEmpKey  = 0;
		$employeesTouched = [];   // EMP_KEY => true

		$pdo->beginTransaction();

		// Process each data row
		for ($i = 1; $i < count($rows); $i++) {
			$debugRowsSeen++;
			$row = $rows[$i];

			// Skip completely empty rows
			if (empty(array_filter($row, static fn($v) => $v !== null && $v !== ''))) {
				if ($debug && $debugRowsSeen <= 5) {
					echo '<pre>DEBUG: row ' . $i . ' skipped (empty)</pre>';
				}
				continue;
			}
			
			$debugNonEmptyRows++;

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

			// --- Derive firstname / middlename / surname from mapping ---
			$firstname  = '';
			$middlename = '';
			$surname    = '';
			
			// Case 1: user mapped a Full name column
			if ($fullNameCol !== '') {
				$nameStr = (string)($getCell($fullNameCol, $headerIndex, $rowByIdx) ?? '');
				$parts   = preg_split('/\s+/', trim($nameStr)) ?: [];
			
				$firstname = $parts[0] ?? '';
				$surname   = $parts ? ($parts[count($parts) - 1] ?? '') : '';
			
				if (count($parts) > 2) {
					$middlename = implode(' ', array_slice($parts, 1, -1));
				}
			
			// Case 2: user mapped First / Middle / Surname separately
			} else {
				if ($firstCol !== '') {
					$firstname = trim((string)($getCell($firstCol, $headerIndex, $rowByIdx) ?? ''));
				}
				if ($surnameCol !== '') {
					$surname = trim((string)($getCell($surnameCol, $headerIndex, $rowByIdx) ?? ''));
				}
				if ($middleCol !== '') {
					$middlename = trim((string)($getCell($middleCol, $headerIndex, $rowByIdx) ?? ''));
				}
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
			
			if ($empKey > 0) {
				$debugRowsWithEmpKey++;
				if ($debug && $debugRowsWithEmpKey <= 5) {
					echo '<pre>DEBUG: row ' . $i . ' has EMP_KEY=' . $empKey . ', mysqlDate=' . $mysqlDate . '</pre>';
				}
			} elseif ($debug && $debugRowsWithEmpKey <= 5) {
				echo '<pre>DEBUG: row ' . $i . ' has NO EMP_KEY (firstname='
					. htmlspecialchars($firstname) . ', surname=' . htmlspecialchars($surname) . ')</pre>';
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
				
				// Annual salary: if we have a mapped "Basic Pay" column, treat it as monthly and x12
				$annualSalary = 0.0;
				if ($salaryColumnIndex !== null && isset($rowByIdx[$salaryColumnIndex])) {
					$monthlyBase  = $toMoney($rowByIdx[$salaryColumnIndex]);
					if ($debug) {
						echo '<pre>DEBUG: salary calc for EMP_KEY ' . $empKey
						   . ' using column index ' . $salaryColumnIndex
						   . ' raw=' . var_export($rowByIdx[$salaryColumnIndex], true)
						   . ' monthlyBase=' . $monthlyBase
						   . '</pre>';
					}
					if ($monthlyBase > 0) {
						$annualSalary = $monthlyBase * 12.0;
					}
				}
				
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
			
			if ($empKey > 0) {
				$employeesTouched[$empKey] = true;
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
				
				// group selected in the mapping form (optional)
				$chosenGroup = null;
				if (isset($cfg['group']) && $cfg['group'] !== '' && ctype_digit((string)$cfg['group'])) {
					$chosenGroup = (int)$cfg['group'];
				}
				
				$typeGroupRef = $getGroupRef($paytypeLabel, $chosenGroup);

				// Access the cell by index
				$rawVal = $rowByIdx[$idx] ?? 0;
				$amount = $toMoney($rawVal);
				if ($amount == 0.0) {
					continue; // skip empty/zero entries
				}
				
				if ($debug && $rowCount < 10) {
					echo '<pre>DEBUG: inserting actual row ' . $rowCount . PHP_EOL
					   . '  EMP_KEY=' . $empKey . PHP_EOL
					   . '  TYPE=' . $typeGroupRef . ' (label=' . $paytypeLabel . ')' . PHP_EOL
					   . '  DATE=' . $mysqlDate . PHP_EOL
					   . '  VALUE=' . $amount . PHP_EOL
					   . '</pre>';
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
			if ($debug) {
				echo '<pre>DEBUG: salaryColumnIndex = ' . var_export($salaryColumnIndex, true) . '</pre>';
			}
		}
		
		if ($debug) {
			echo '<pre>DEBUG SUMMARY:' . PHP_EOL;
			echo '  rows seen:           ' . $debugRowsSeen . PHP_EOL;
			echo '  non-empty rows:      ' . $debugNonEmptyRows . PHP_EOL;
			echo '  rows with EMP_KEY:   ' . $debugRowsWithEmpKey . PHP_EOL;
			echo '  actuals inserted:    ' . $rowCount . PHP_EOL;
			echo '</pre>';
		}
		
		$pdo->commit();

		// Clear temp file + session entry
		unset($_SESSION['excel_advanced_uploads'][$uploadId]);
		@unlink($filePath);

		$plural = ($rowCount === 1) ? '' : 's';
		echo "Successfully Imported $rowCount pay line$plural.";
		
		if (count($newEmployees) > 0) {
			$empPlural = (count($newEmployees) === 1) ? '' : 's';
			echo "<br><br>Created " . count($newEmployees) . " new employee$empPlural:<br>";
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
		$tmpDir   = __DIR__ . '/../tmp';
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
		excelAdvanced_renderMappingForm($header, $uploadId, null, $paytypeGroups);
		return;
	}

	echo "No file uploaded.";
	return;

} catch (\Throwable $e) {
	
	if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
		$pdo->rollBack();
	}
	
	if ($debug) {
		http_response_code(500);
	
		$payload = [
			'ok'   => false,
			'type' => get_class($e),
			'msg'  => $e->getMessage(),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'phpSpreadsheetLoaded' => class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class),
			'ref'  => $ref,
		];
	
		echo '<pre>' . htmlspecialchars(print_r($payload, true)) . '</pre>';
		echo '<script>console.error(' . json_encode($payload) . ');</script>';
		return;
	}

	error_log('excelAdvancedUpload: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
	http_response_code(500);
	echo 'There was an error processing the file.';
	return;
}