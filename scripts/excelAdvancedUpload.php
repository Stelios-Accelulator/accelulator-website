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
$mappingTable			= "payroll_upload_mappings"; // 🤖 global table, no $ref_ prefix

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

/**
 * Load saved mapping for this company (if any).
 * We also check the header signature so we can detect when the layout changed.
 */
function excelAdvanced_loadSavedMapping(PDO $pdo, string $mappingTable, int $companyRef, array $currentHeader): ?array
{
	$sig = hash('sha256', json_encode(array_values($currentHeader)));

	try {
		$stmt = $pdo->prepare("
			SELECT MAPPING_JSON, HEADER_SIGNATURE
			FROM {$mappingTable}
			WHERE COMPANY_REF = :c
			LIMIT 1
		");
		$stmt->execute([':c' => $companyRef]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return null;
		}

		$data = json_decode($row['MAPPING_JSON'] ?? '', true);
		if (!is_array($data)) {
			return null;
		}

		$data['headerSignatureMatches'] = ($row['HEADER_SIGNATURE'] === $sig);
		return $data;
	} catch (\Throwable $e) {
		error_log('excelAdvancedUpload: failed to load mapping: '.$e->getMessage());
		return null;
	}
}

/**
 * Save the current mapping for reuse on future uploads.
 * Stored per-company in payroll_upload_mappings.
 */
function excelAdvanced_saveMapping(
	PDO $pdo,
	string $mappingTable,
	int $companyRef,
	array $header,
	array $map,
	array $values,
	string $nameMode
): void {
	// Build a header-name keyed structure for value columns
	$valuesByHeader = [];
	foreach ($values as $idx => $cfg) {
		$idx = (int)$idx;
		if (!isset($header[$idx])) continue;
		$colName = (string)$header[$idx];

		$valuesByHeader[$colName] = [
			'enabled' => !empty($cfg['enabled']) ? 1 : 0,
			'label'   => isset($cfg['label']) ? (string)$cfg['label'] : '',
			'group'   => (isset($cfg['group']) && ctype_digit((string)$cfg['group']))
				? (int)$cfg['group']
				: null,
		];
	}

	$payload = [
		'map'            => $map,
		'valuesByHeader' => $valuesByHeader,
		'nameMode'       => $nameMode,
		'header'         => array_values($header),
	];

	$json = json_encode($payload);
	if ($json === false) {
		return;
	}

	$sig = hash('sha256', json_encode(array_values($header)));

	try {
		// Upsert per company
		$stmt = $pdo->prepare("
			INSERT INTO {$mappingTable} (COMPANY_REF, HEADER_SIGNATURE, MAPPING_JSON, CREATED_AT, UPDATED_AT)
			VALUES (:c, :sig, :json, NOW(), NOW())
			ON DUPLICATE KEY UPDATE
				HEADER_SIGNATURE = VALUES(HEADER_SIGNATURE),
				MAPPING_JSON     = VALUES(MAPPING_JSON),
				UPDATED_AT       = VALUES(UPDATED_AT)
		");
		$stmt->execute([
			':c'    => $companyRef,
			':sig'  => $sig,
			':json' => $json,
		]);
	} catch (\Throwable $e) {
		error_log('excelAdvancedUpload: failed to save mapping: '.$e->getMessage());
	}
}

// 4) Helper to render the mapping form
function excelAdvanced_renderMappingForm(
	array $header,
	string $uploadId,
	?string $errorMessage = null,
	array $paytypeGroups = [],
	?array $savedMapping = null
): void {
	global $debug;

	// Normalise so the null-coalescing below is safe
	$savedMapping = $savedMapping ?? [];

	$prevMap      = $savedMapping['map']            ?? [];
	$prevValues   = $savedMapping['valuesByHeader'] ?? [];
	$prevNameMode = $savedMapping['nameMode']       ?? 'single';
	// Very lightweight inline styling so it’s usable out of the box
	?>
	<script>
	function resetMenu(){
		let menuExists = document.getElementById('menuContainer');
		if (menuExists != null){
			destroy('menuContainer');
		}
	}
	
	resetMenu();
	
	function createNewMenu(){
		let contentView = document.getElementById('contentView');
		
		let mappingMenu = document.createElement('menuContainer');
		mappingMenu.id = 'menuContainer';
		
		let menuHeader = document.createElement('div');
		menuHeader.classList.add('menuHeader');
		
		let menuHeaderText = document.createElement('strong');
		menuHeaderText.textContent = 'Map your payroll columns';
		menuHeader.appendChild(menuHeaderText);
		
		let menuHeaderButton = document.createElement('button');
		menuHeaderButton.textContent = 'X';
		menuHeaderButton.addEventListener("click", () => {
			destroyMenu('menuContainer');
		});
		menuHeader.appendChild(menuHeaderButton);
		mappingMenu.appendChild(menuHeader);
		
		let introTextRow = document.createElement('div');
		introTextRow.classList.add('menuRow');
		let introTextRowP = document.createElement('p');
		introTextRowP.textContent = 'Tell us which columns in your spreadsheet contain the key fields (date, payroll number, name, etc.)\nand which columns are pay values.';
		introTextRowP.classList.add('small');
		introTextRow.appendChild(introTextRowP);
		mappingMenu.appendChild(introTextRow); 
		
		<?php if ($errorMessage): ?>
			let errorRow = document.createElement('div');
			errorRow.classList.add('menuRow');
			errorRow.textContent = '<?= htmlspecialchars($errorMessage) ?>';
			mappingMenu.appendChild(errorRow);
		<?php endif; ?>
		
		let uploadForm = document.createElement('form');
		uploadForm.id = 'advUploadForm';
		uploadForm.method = 'post';
		uploadForm.action = '/scripts/excelAdvancedUpload.php';
		
		let stepInput = document.createElement('input');
		stepInput.type = 'hidden';
		stepInput.name = 'step';
		stepInput.value = 'process';
		uploadForm.appendChild(stepInput);
		
		let uploadInput = document.createElement('input');
		uploadInput.type = 'hidden';
		uploadInput.name = 'upload_id';
		uploadInput.value = '<?= htmlspecialchars($uploadId) ?>';
		uploadForm.appendChild(uploadInput);
		
		let debugInput = document.createElement('input');
		debugInput.type = 'hidden';
		debugInput.name = 'debug';
		debugInput.value = '<?= $debug ? '1' : '0' ?>';
		uploadForm.appendChild(debugInput);
		
		let formFieldset = document.createElement('fieldset');
		
		let legendRow = document.createElement('div');
		legendRow.classList.add('menuRow');
		
		let legendRowLegend = document.createElement('legend');
		legendRowLegend.textContent = 'Core columns';
		legendRow.appendChild(legendRowLegend);
		formFieldset.appendChild(legendRow);
		
		let paymentDateRow = document.createElement('div');
		paymentDateRow.classList.add('menuRow');
		
		let paymentDateLabel = document.createElement('label');
		paymentDateLabel.for = 'map[PAYMENT_DATE]';
		paymentDateLabel.textContent = 'Payment date (required)';
		paymentDateRow.appendChild(paymentDateLabel);
		
		let paymentDateSelect = document.createElement('select');
		paymentDateSelect.name = 'map[PAYMENT_DATE]';
		paymentDateSelect.required = true;
		
		let paymentDateOption = document.createElement('option');
		paymentDateOption.value = '';
		paymentDateOption.textContent = '-- Choose a column --';
		paymentDateSelect.appendChild(paymentDateOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(paymentDateSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['PAYMENT_DATE']) && $prevMap['PAYMENT_DATE'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		paymentDateRow.appendChild(paymentDateSelect);
		formFieldset.appendChild(paymentDateRow);
		
		let payrollNumberRow = document.createElement('div');
		payrollNumberRow.classList.add('menuRow');
		
		let payrollNumberLabel = document.createElement('label');
		payrollNumberLabel.for = 'map[PAYROLL_NUMBER]';
		payrollNumberLabel.textContent = 'Payroll number (required)';
		payrollNumberRow.appendChild(payrollNumberLabel);
		
		let payrollNumberSelect = document.createElement('select');
		payrollNumberSelect.name = 'map[PAYROLL_NUMBER]';
		payrollNumberSelect.required = true;
		
		let payrollNumberOption = document.createElement('option');
		payrollNumberOption.value = '';
		payrollNumberOption.textContent = '-- Choose a column --';
		payrollNumberSelect.appendChild(payrollNumberOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(payrollNumberSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['PAYROLL_NUMBER']) && $prevMap['PAYROLL_NUMBER'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		payrollNumberRow.appendChild(payrollNumberSelect);
		formFieldset.appendChild(payrollNumberRow);
		
		let nameTextRow = document.createElement('div');
		nameTextRow.classList.add('menuRow');
		let nameTextRowP = document.createElement('p');
		nameTextRowP.textContent = 'You can either map a single "Full name" column or map separate First / Middle / Surname columns.\nAt a minimum, you must provide either a Full name, or both First name and Surname.';
		nameTextRowP.classList.add('small');
		nameTextRow.appendChild(nameTextRowP);
		formFieldset.appendChild(nameTextRow);
		
		let nameModeRow = document.createElement('div');
		nameModeRow.classList.add('nameModeRow');
		nameModeRow.classList.add('menuRow');
		formFieldset.appendChild(nameModeRow);
		
		let storedSpan = document.createElement('span');
		storedSpan.textContent = 'How is the name stored in your file?';
		nameModeRow.appendChild(storedSpan);
		
		let singleNameRow = document.createElement('div');
		singleNameRow.classList.add('menuRow');
		formFieldset.appendChild(singleNameRow);
		
		let singleNameLabel = document.createElement('label');
		singleNameLabel.textContent = 'Single full-name column';
		singleNameLabel.for ='nameMode';
		singleNameRow.appendChild(singleNameLabel);
		
		let singleNameRadio = document.createElement('input');
		singleNameRadio.type = 'radio';
		singleNameRadio.name = 'nameMode';
		singleNameRadio.value = 'single';
		singleNameRadio.checked = '<?= ($prevNameMode === "split" ? false : true) ?>';
		singleNameRow.appendChild(singleNameRadio);
		
		let splitNameRow = document.createElement('div');
		splitNameRow.classList.add('menuRow');
		formFieldset.appendChild(splitNameRow);
		
		let splitNameLabel = document.createElement('label');
		splitNameLabel.textContent = 'Separate first / middle / surname columns';
		splitNameLabel.for = 'nameMode';
		splitNameRow.appendChild(splitNameLabel);
		
		let splitNameRadio = document.createElement('input');
		splitNameRadio.type = 'radio';
		splitNameRadio.name = 'nameMode';
		splitNameRadio.value = 'split';
		splitNameRadio.checked = '<?= ($prevNameMode === "split" ? true : false) ?>';
		splitNameRow.appendChild(splitNameRadio);
		
		
		
		let fullNameRow = document.createElement('div');
		fullNameRow.classList.add('menuRow');
		fullNameRow.id = 'fullNameRow';
		
		let fullNameLabel = document.createElement('label');
		fullNameLabel.for = 'map[NAME]';
		fullNameLabel.textContent = 'Full name (optional)';
		fullNameRow.appendChild(fullNameLabel);
		
		let fullNameSelect = document.createElement('select');
		fullNameSelect.name = 'map[NAME]';
		
		let fullNameOption = document.createElement('option');
		fullNameOption.value = '';
		fullNameOption.textContent = '-- Choose a column --';
		fullNameSelect.appendChild(fullNameOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(fullNameSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['NAME']) && $prevMap['NAME'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		fullNameRow.appendChild(fullNameSelect);
		formFieldset.appendChild(fullNameRow);
		
		let splitNameRows = document.createElement('div');
		splitNameRows.id = 'splitNameRows';
		splitNameRows.classList.add('hidden');
		formFieldset.appendChild(splitNameRows);
		
		let firstNameRow = document.createElement('div');
		firstNameRow.classList.add('menuRow');
		
		let firstNameLabel = document.createElement('label');
		firstNameLabel.classList.add('splitNameRow');
		firstNameLabel.textContent = 'First name (optional)';
		firstNameLabel.for = 'map[FIRSTNAME]'
		firstNameRow.appendChild(firstNameLabel);
		
		let firstNameSelect = document.createElement('select');
		firstNameSelect.name = 'map[FIRSTNAME]';
		
		let firstNameOption = document.createElement('option');
		firstNameOption.value = '';
		firstNameOption.textContent = '-- Not present --';
		firstNameSelect.appendChild(firstNameOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(firstNameSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['FIRSTNAME']) && $prevMap['FIRSTNAME'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		firstNameRow.appendChild(firstNameSelect);
		splitNameRows.appendChild(firstNameRow);
		
		let middleNameRow = document.createElement('div');
		middleNameRow.classList.add('menuRow');
		
		let middleNameLabel = document.createElement('label');
		middleNameLabel.classList.add('splitNameRow');
		middleNameLabel.textContent = 'Middle name(s) (optional)';
		middleNameLabel.for = 'map[MIDDLENAME]'
		middleNameRow.appendChild(middleNameLabel);
		
		let middleNameSelect = document.createElement('select');
		middleNameSelect.name = 'map[MIDDLENAME]';
		
		let middleNameOption = document.createElement('option');
		middleNameOption.value = '';
		middleNameOption.textContent = '-- Not present --';
		middleNameSelect.appendChild(middleNameOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(middleNameSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['MIDDLENAME']) && $prevMap['MIDDLENAME'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		middleNameRow.appendChild(middleNameSelect);
		splitNameRows.appendChild(middleNameRow);
		
		let surnameRow = document.createElement('div');
		surnameRow.classList.add('menuRow');
		
		let surnameLabel = document.createElement('label');
		surnameLabel.classList.add('splitNameRow');
		surnameLabel.textContent = 'Surname (optional)';
		surnameLabel.for = 'map[SURNAME]'
		surnameRow.appendChild(surnameLabel);
		
		let surnameSelect = document.createElement('select');
		surnameSelect.name = 'map[SURNAME]';
		
		let surnameOption = document.createElement('option');
		surnameOption.value = '';
		surnameOption.textContent = '-- Not present --';
		surnameSelect.appendChild(surnameOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(surnameSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['SURNAME']) && $prevMap['SURNAME'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		surnameRow.appendChild(surnameSelect);
		splitNameRows.appendChild(surnameRow);
		
		let periodRow = document.createElement('div');
		periodRow.classList.add('menuRow');
		
		let periodLabel = document.createElement('label');
		periodLabel.for = 'map[PERIOD]';
		periodLabel.textContent = 'Period number (optional)';
		periodRow.appendChild(periodLabel);
		
		let periodSelect = document.createElement('select');
		periodSelect.name = 'map[PERIOD]';
		
		let periodOption = document.createElement('option');
		periodOption.value = '';
		periodOption.textContent = '-- Not present --';
		periodSelect.appendChild(periodOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(periodSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['PERIOD']) && $prevMap['PERIOD'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		periodRow.appendChild(periodSelect);
		formFieldset.appendChild(periodRow);
		
		let yearRow = document.createElement('div');
		yearRow.classList.add('menuRow');
		
		let yearLabel = document.createElement('label');
		yearLabel.for = 'map[YEAR]';
		yearLabel.textContent = 'Year (optional)';
		yearRow.appendChild(yearLabel);
		
		let yearSelect = document.createElement('select');
		yearSelect.name = 'map[YEAR]';
		
		let yearOption = document.createElement('option');
		yearOption.value = '';
		yearOption.textContent = '-- Not present --';
		yearSelect.appendChild(yearOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(yearSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['YEAR']) && $prevMap['YEAR'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		yearRow.appendChild(yearSelect);
		formFieldset.appendChild(yearRow);
		
		let dobRow = document.createElement('div');
		dobRow.classList.add('menuRow');
		
		let dobLabel = document.createElement('label');
		dobLabel.for = 'map[DOB]';
		dobLabel.textContent = 'Date of birth (optional)';
		dobRow.appendChild(dobLabel);
		
		let dobSelect = document.createElement('select');
		dobSelect.name = 'map[DOB]';
		
		let dobOption = document.createElement('option');
		dobOption.value = '';
		dobOption.textContent = '-- Not present --';
		dobSelect.appendChild(dobOption);
		
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) {
					opt.selected = true;
				}
				selectEl.appendChild(opt);
			})(dobSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['DOB']) && $prevMap['DOB'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		dobRow.appendChild(dobSelect);
		formFieldset.appendChild(dobRow);
		
		let valueFieldset = document.createElement('fieldset');
		
		let valueHeaderRow = document.createElement('div');
		valueHeaderRow.classList.add('menuRow');
		valueFieldset.appendChild(valueHeaderRow);
		
		let valueHeaderLegend = document.createElement('legend');
		valueHeaderLegend.textContent = 'Value columns (pay elements)';
		valueHeaderRow.appendChild(valueHeaderLegend);
		
		
		let valueTextRow = document.createElement('div');
		valueTextRow.classList.add('menuRow');
		let valueTextRowP = document.createElement('p');
		valueTextRowP.textContent = 'Tick each column that contains a numeric pay value. The label will be used to create or match\na pay type (Base, Overtime, Employers NI etc).';
		valueTextRowP.classList.add('small');
		valueTextRow.appendChild(valueTextRowP);
		valueFieldset.appendChild(valueTextRow);
		
		let valuesTable = document.createElement('table');
		valuesTable.innerHTML = `
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
				<?php
					$prev       = $prevValues[$col] ?? null;
					$prevEnabled = !empty($prev['enabled']);
					$prevLabel   = isset($prev['label']) ? (string)$prev['label'] : $col;
					$prevGroup   = isset($prev['group']) ? (int)$prev['group'] : null;
				?>
				<tr data-col-name="<?= htmlspecialchars($col) ?>">
					<td><?= htmlspecialchars($col) ?></td>
					<td>
						<input type="checkbox"
							name="values[<?= (int)$idx ?>][enabled]"
							value="1"
							<?= $prevEnabled ? 'checked' : '' ?>>
					</td>
					<td>
						<input type="text"
							name="values[<?= (int)$idx ?>][label]"
							value="<?= htmlspecialchars($prevLabel) ?>">
					</td>
					<td>
						<select name="values[<?= (int)$idx ?>][group]">
							<?php foreach ($paytypeGroups as $grp): ?>
								<?php
									$ref = (int)$grp['REF'];
									$isSelected = ($prevGroup !== null)
										? ($ref === $prevGroup)
										: ($ref === 11); // your original default
								?>
								<option value="<?= $ref ?>" <?= $isSelected ? ' selected' : '' ?>>
									<?= htmlspecialchars($grp['DESCRIPTION']) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		`;
		valueFieldset.appendChild(valuesTable);
		
		let actionsDiv = document.createElement('div');
		actionsDiv.classList.add('actions');
		
		let importButton = document.createElement('button');
		importButton.type = 'submit';
		importButton.textContent = 'Import';
		importButton.classList.add('btn');
		importButton.classList.add('btn-primary');
		actionsDiv.appendChild(importButton);
		
		let resetButton = document.createElement('button');
		resetButton.type = 'button';
		resetButton.id = 'resetMappingBtn';
		resetButton.style = 'margin-left:0.5rem;';
		resetButton.textContent = 'Reset Mapping';
		resetButton.classList.add('btn');
		<?php if ($savedMapping): ?>
			actionsDiv.appendChild(resetButton);
		<?php endif; ?>
		
		
		uploadForm.appendChild(formFieldset);
		uploadForm.appendChild(valueFieldset);
		uploadForm.appendChild(actionsDiv);
		mappingMenu.appendChild(uploadForm);
		contentView.appendChild(mappingMenu);
		makeDraggable(mappingMenu);
	}
	
	createNewMenu();
	</script>
	
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
				white-space: pre-line;
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
				
				// --- Reset mapping button: clear all selections/labels to defaults ---
				const resetBtn = document.getElementById('resetMappingBtn');
				if (resetBtn) {
					resetBtn.addEventListener('click', function () {
						// clear core mapping selects
						document.querySelectorAll('select[name^="map["]').forEach(function (sel) {
							sel.value = '';
						});
				
						// uncheck all value checkboxes
						document.querySelectorAll('input[type="checkbox"][name^="values["]').forEach(function (cb) {
							cb.checked = false;
						});
				
						// reset labels back to their column header & default group
						document.querySelectorAll('table tbody tr[data-col-name]').forEach(function (tr) {
							const colName = (tr.getAttribute('data-col-name') || '').trim();
							const labelInput = tr.querySelector('input[name*="[label]"]');
							if (labelInput && colName) {
								labelInput.value = colName;
							}
							const groupSel = tr.querySelector('select[name*="[group]"]');
							if (groupSel) {
								groupSel.selectedIndex = 0;
							}
						});
				
						// reset name mode to single
						const nmSingle = document.querySelector('input[name="nameMode"][value="single"]');
						if (nmSingle) {
							nmSingle.checked = true;
						}
				
						updateNameMode();
						updateValueColumnVisibility();
					});
				}
		
				// Initial state
				updateNameMode();
				updateValueColumnVisibility();
			})();
			
			
			
		</script>
	
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
				$paytypeGroups,
				null
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
		
		// Persist mapping for this company in payroll_upload_mappings
		$nameMode = $_POST['nameMode'] ?? 'single';
		excelAdvanced_saveMapping(
			$pdo,
			$mappingTable,
			(int)$ref,
			$header,
			$map,
			$values,
			$nameMode
		);
		
		// Clear temp file + session entry
		unset($_SESSION['excel_advanced_uploads'][$uploadId]);
		@unlink($filePath);
		
		echo "
		<div class='menuHeader'>
			<strong>Successful Import</strong>
			<button onclick=\"destroyMenu('menuContainer');\">
			<svg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='1.5' stroke='currentColor' class='size-6 toolbarIcon' height='1.5em' width='1.5em'>
			  <path stroke-linecap='round' stroke-linejoin='round' d='M6 18 18 6M6 6l12 12' />
			</svg>
			</button>
		</div>
		";
		
		$plural = ($rowCount === 1) ? '' : 's';
		echo "Successfully Imported $rowCount pay line$plural.";
		
		if (count($newEmployees) > 0) {
			$empPlural = (count($newEmployees) === 1) ? '' : 's';
			echo "<br><br>Created " . count($newEmployees) . " new employee$empPlural:<br>";
			foreach ($newEmployees as $name) {
				echo htmlspecialchars($name) . "<br>";
			}
		}
		
		echo "<br><br>Your mapping has been saved for future uploads.";
		
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
		
		// Try to load a previously saved mapping for this company
		$savedMapping = excelAdvanced_loadSavedMapping($pdo, $mappingTable, (int)$ref, $header);

		
		// Store file path in session keyed by uploadId
		if (!isset($_SESSION['excel_advanced_uploads'])) {
			$_SESSION['excel_advanced_uploads'] = [];
		}
		$_SESSION['excel_advanced_uploads'][$uploadId] = $target;

		// Render mapping form and stop
		excelAdvanced_renderMappingForm(
			$header,
			$uploadId,
			null,
			$paytypeGroups,
			$savedMapping
		);
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