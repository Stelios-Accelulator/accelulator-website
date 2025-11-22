<?php
session_start();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/loadPhpSpreadsheet.php';

$cryptoPath = __DIR__ . '/../includes/crypto.php';
if (is_file($cryptoPath)) {
	require_once $cryptoPath;
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

$debug = (($_GET['debug'] ?? '') === '1' || ($_POST['debug'] ?? '') === '1');

// Ensure PhpSpreadsheet is available
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
	echo '<pre>' . htmlspecialchars(print_r($payload, true)) . '</pre>';
	echo '<script>console.error(' . json_encode($payload) . ');</script>';
	return;
}

// === USER + TABLE CONTEXT ============================================

$user = checkUser();
$ref  = getUsersCompanyId($user);

if ($ref === null || $ref === '' || !ctype_digit((string)$ref)) {
	http_response_code(400);
	echo 'There was an error processing the file.';
	return;
}

global $pdo;

$table_actuals         = $ref . "_actuals";
$table_resources       = $ref . "_resources";
$table_details         = $ref . "_details";
$table_payroll_library = $ref . "_payroll_library";
$table_paytype         = $ref . "_paytype";
$table_paytype_group   = $ref . "_paytype_group";
$mappingTable          = "payroll_upload_mappings"; // global

// Load paytype groups (for future if you want a default category select)
$paytypeGroups = [];
try {
	$stmt = $pdo->query("SELECT REF, PAYTYPEGROUP FROM $table_paytype_group ORDER BY REF");
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$paytypeGroups[] = [
			'REF'         => (int)$row['REF'],
			'DESCRIPTION' => (string)$row['PAYTYPEGROUP'],
		];
	}
} catch (\Throwable $e) {
	error_log('verticalExcelUpload: failed to fetch paytype groups: '.$e->getMessage());
	$paytypeGroups = [];
}

// === PREFETCH / HELPERS ==============================================

// payroll number -> EMP_KEY
$empByPayroll = [];
try {
	$stmt = $pdo->query("SELECT PAYROLL_NUMBER, EMP_KEY FROM $table_payroll_library");
	while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$empByPayroll[(string)$r['PAYROLL_NUMBER']] = (int)$r['EMP_KEY'];
	}
} catch (\Throwable $e) {
	error_log('verticalExcelUpload (prefetch payroll_library): ' . $e->getMessage());
}

// PAYTYPE lookup/create (same as advanced)
$getGroupRef = function (?string $desc, ?int $groupRef = null) use ($pdo, $table_paytype): int {
	$d = trim((string)$desc);
	if ($d === '') return 1;

	$q = $pdo->prepare("SELECT REF FROM $table_paytype WHERE LOWER(DESCRIPTION) = LOWER(:d) LIMIT 1");
	$q->execute([':d' => $d]);
	$ref = $q->fetchColumn();
	if ($ref !== false) return (int)$ref;

	$q = $pdo->prepare("SELECT REF FROM $table_paytype WHERE LOWER(VALUE) = LOWER(:v) LIMIT 1");
	$norm = strtolower(preg_replace('/[^a-z0-9]+/', '', $d));
	$q->execute([':v' => $norm]);
	$ref = $q->fetchColumn();
	if ($ref !== false) return (int)$ref;

	$maxQ    = $pdo->query("SELECT COALESCE(MAX(REF), 0) FROM $table_paytype");
	$nextRef = (int)$maxQ->fetchColumn() + 1;

	$ins = $pdo->prepare("
		INSERT INTO $table_paytype (REF, DESCRIPTION, VALUE, PAYTYPE_GROUP_REF)
		VALUES (:ref, :desc, :val, :grp)
	");
	$ins->execute([
		':ref'  => $nextRef,
		':desc' => $d,
		':val'  => $norm,
		':grp'  => $groupRef ?: 11,
	]);

	return $nextRef;
};

$toMoney = static function ($v): float {
	if (is_numeric($v)) return (float)$v;
	if (!is_string($v)) return 0.0;
	$clean = preg_replace('/[^\d\.\-]/', '', $v);
	if (substr_count($clean, '.') > 1) {
		$last  = strrpos($clean, '.');
		$clean = preg_replace('/\./', '', substr($clean, 0, $last)) . substr($clean, $last);
	}
	return (float)$clean;
};

// mapping persistence helpers (same as in excelAdvancedUpload.php)
function vertical_loadSavedMapping(PDO $pdo, string $mappingTable, int $companyRef, array $currentHeader): ?array {
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
		if (!$row) return null;

		$data = json_decode($row['MAPPING_JSON'] ?? '', true);
		if (!is_array($data)) return null;

		$data['headerSignatureMatches'] = ($row['HEADER_SIGNATURE'] === $sig);
		return $data;
	} catch (\Throwable $e) {
		error_log('verticalExcelUpload: failed to load mapping: '.$e->getMessage());
		return null;
	}
}

function vertical_saveMapping(
	PDO $pdo,
	string $mappingTable,
	int $companyRef,
	array $header,
	array $map,
	string $nameMode
): void {
	$payload = [
		'map'            => $map,
		'valuesByHeader' => [], // none for vertical layout
		'nameMode'       => $nameMode,
		'header'         => array_values($header),
	];

	$json = json_encode($payload);
	if ($json === false) return;

	$sig = hash('sha256', json_encode(array_values($header)));

	try {
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
		error_log('verticalExcelUpload: failed to save mapping: '.$e->getMessage());
	}
}

// === RENDER MAPPING MENU (vertical layout) ============================

function vertical_renderMappingForm(
	array $header,
	string $uploadId,
	?string $errorMessage = null,
	?array $savedMapping  = null
): void {
	global $debug;

	$savedMapping = $savedMapping ?? [];
	$prevMap      = $savedMapping['map']      ?? [];
	$prevNameMode = $savedMapping['nameMode'] ?? 'single';
	?>
	<script>
	// (Same menu shell you already use – trimmed here, but you can keep your exact styling)
	function resetMenu(){
		let menuExists = document.getElementById('menuContainer');
		if (menuExists != null){
			destroy('menuContainer');
		}
	}
	
	function createNewMenu(){
		let contentView = document.getElementById('contentView');
	
		let mappingMenu = document.createElement('menuContainer');
		mappingMenu.id = 'menuContainer';
	
		let menuHeader = document.createElement('div');
		menuHeader.classList.add('menuHeader');
	
		let menuHeaderText = document.createElement('strong');
		menuHeaderText.textContent = 'Map your payroll columns (Type + Amount layout)';
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
		introTextRowP.textContent =
			'This upload expects one row per pay element, with a "Type" column (e.g. Basic Pay, Overtime)\nand a single numeric "Amount" or "£" column.';
		introTextRowP.classList.add('small');
		introTextRow.appendChild(introTextRowP);
		mappingMenu.appendChild(introTextRow);
	
		<?php if ($errorMessage): ?>
			let errorRow = document.createElement('div');
			errorRow.classList.add('menuRow', 'error');
			errorRow.textContent = '<?= htmlspecialchars($errorMessage) ?>';
			mappingMenu.appendChild(errorRow);
		<?php endif; ?>
	
		let uploadForm = document.createElement('form');
		uploadForm.id = 'vertUploadForm';
		uploadForm.method = 'post';
		uploadForm.action = '/scripts/verticalExcelUpload.php';
	
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
	
		// Payment date
		let paymentDateRow = document.createElement('div');
		paymentDateRow.classList.add('menuRow');
		let paymentDateLabel = document.createElement('label');
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
		nameTextRowP.textContent = 'You can either map a single "Full name" column or map separate\n First / Middle / Surname columns. At a minimum, you must provide either\n a Full name, or both First name and Surname.';
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
	
		// Pay element mapping (vertical-specific)
		let typeRow = document.createElement('div');
		typeRow.classList.add('menuRow');
		let typeLabel = document.createElement('label');
		typeLabel.textContent = 'Column containing pay element type (e.g. "TYPE")';
		typeRow.appendChild(typeLabel);
	
		let typeSelect = document.createElement('select');
		typeSelect.name = 'map[PAYTYPE_LABEL_COL]';
		typeSelect.required = true;
	
		let typeOption = document.createElement('option');
		typeOption.value = '';
		typeOption.textContent = '-- Choose a column --';
		typeSelect.appendChild(typeOption);
	
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) opt.selected = true;
				selectEl.appendChild(opt);
			})(typeSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['PAYTYPE_LABEL_COL']) && $prevMap['PAYTYPE_LABEL_COL'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		typeRow.appendChild(typeSelect);
		formFieldset.appendChild(typeRow);
	
		let valueRow = document.createElement('div');
		valueRow.classList.add('menuRow');
		let valueLabel = document.createElement('label');
		valueLabel.textContent = 'Column containing pay amount (e.g. "GBP" / "Amount")';
		valueRow.appendChild(valueLabel);
	
		let valueSelect = document.createElement('select');
		valueSelect.name = 'map[VALUE_COL]';
		valueSelect.required = true;
	
		let valueOption = document.createElement('option');
		valueOption.value = '';
		valueOption.textContent = '-- Choose a column --';
		valueSelect.appendChild(valueOption);
	
		<?php foreach ($header as $col): ?>
			(function(selectEl, value, text, isSelected) {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = text;
				if (isSelected) opt.selected = true;
				selectEl.appendChild(opt);
			})(valueSelect,
			<?= json_encode($col) ?>,
			<?= json_encode($col) ?>,
			<?= isset($prevMap['VALUE_COL']) && $prevMap['VALUE_COL'] === $col ? 'true' : 'false' ?>
			);
		<?php endforeach; ?>
		valueRow.appendChild(valueSelect);
		formFieldset.appendChild(valueRow);
	
		let actionsDiv = document.createElement('div');
		actionsDiv.classList.add('actions');
	
		let importButton = document.createElement('button');
		importButton.type = 'submit';
		importButton.textContent = 'Import';
		importButton.classList.add('btn', 'btn-primary');
		actionsDiv.appendChild(importButton);
	
		uploadForm.appendChild(formFieldset);
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
		
		const form = document.getElementById('vertUploadForm');
		if (!form) return;

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			const formData = new FormData(form);

			fetch(form.action, {
				method: 'POST',
				body: formData
			})
			.then(resp => resp.text())
			.then(html => {
				const container = form.parentNode;
				container.innerHTML = html;
			})
			.catch(err => {
				console.error('Vertical upload failed', err);
				alert('There was an error processing the file.');
			});
		});
	})();
	</script>
	<?php
}

// === MAIN CONTROLLER =================================================

try {
	// STEP 2: process mapping
	if (isset($_POST['step']) && $_POST['step'] === 'process') {
		if ($debug) {
			echo '<pre>DEBUG (vertical): entered process step' . PHP_EOL . '</pre>';
		}

		$uploadId = $_POST['upload_id'] ?? '';
		if ($uploadId === '' || !isset($_SESSION['excel_advanced_uploads'][$uploadId])) {
			throw new RuntimeException('Upload session has expired or is invalid.');
		}

		$filePath = $_SESSION['excel_advanced_uploads'][$uploadId];
		if (!is_file($filePath) || !is_readable($filePath)) {
			throw new RuntimeException('Uploaded file could not be read.');
		}

		$spreadsheet = IOFactory::load($filePath);
		$sheet       = $spreadsheet->getActiveSheet();
		$rows        = $sheet->toArray();

		if (!$rows || !isset($rows[0])) {
			throw new RuntimeException('Spreadsheet appears to be empty.');
		}

		$header = $rows[0];

		$map           = $_POST['map'] ?? [];
		$paymentDateCol = $map['PAYMENT_DATE']      ?? '';
		$payrollNoCol   = $map['PAYROLL_NUMBER']    ?? '';
		$fullNameCol    = $map['NAME']             ?? '';
		$firstCol       = $map['FIRSTNAME']        ?? '';
		$middleCol      = $map['MIDDLENAME']       ?? '';
		$surnameCol     = $map['SURNAME']          ?? '';
		$periodCol      = $map['PERIOD']           ?? '';
		$yearCol        = $map['YEAR']             ?? '';
		$dobCol         = $map['DOB']              ?? '';
		$typeCol        = $map['PAYTYPE_LABEL_COL'] ?? '';
		$valueCol       = $map['VALUE_COL']         ?? '';

		$hasFullName  = ($fullNameCol !== '');
		$hasSplitName = ($firstCol !== '' && $surnameCol !== '');

		if ($paymentDateCol === '' || $payrollNoCol === '' || (!$hasFullName && !$hasSplitName) || $typeCol === '' || $valueCol === '') {
			vertical_renderMappingForm(
				$header,
				$uploadId,
				'Please map Payment date, Payroll number, name, pay type column and amount column.',
				null
			);
			return;
		}

		// header -> index
		$headerIndex = [];
		foreach ($header as $idx => $colName) {
			$headerIndex[$colName] = $idx;
		}

		$companyRef = (int)$ref;
		$dataKey    = company_data_key($pdo, $companyRef);
		if ($dataKey === '' || strlen($dataKey) !== 32) {
			throw new RuntimeException("Missing/invalid company key for {$companyRef}");
		}

		$rowCount = 0;
		$newEmployees = [];
		$employeesTouched = [];

		$pdo->beginTransaction();

		for ($i = 1; $i < count($rows); $i++) {
			$row = $rows[$i];

			if (empty(array_filter($row, static fn($v) => $v !== null && $v !== ''))) {
				continue;
			}

			$rowByIdx = $row;

			$getCell = static function(string $colName, array $headerIndex, array $rowByIdx) {
				if (!isset($headerIndex[$colName])) return null;
				$idx = $headerIndex[$colName];
				return $rowByIdx[$idx] ?? null;
			};

			// payment date
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

			// payroll number -> EMP_KEY
			global $empByPayroll;
			$rawPN = (string)($getCell($payrollNoCol, $headerIndex, $rowByIdx) ?? '');
			$pn    = preg_replace('/\D+/', '', $rawPN);

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

			// names
			$firstname  = '';
			$middlename = '';
			$surname    = '';

			if ($fullNameCol !== '') {
				$nameStr = (string)($getCell($fullNameCol, $headerIndex, $rowByIdx) ?? '');
				$parts   = preg_split('/\s+/', trim($nameStr)) ?: [];

				$firstname = $parts[0] ?? '';
				$surname   = $parts ? ($parts[count($parts) - 1] ?? '') : '';

				if (count($parts) > 2) {
					$middlename = implode(' ', array_slice($parts, 1, -1));
				}
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

			// paytype label + amount for this row
			$paytypeLabel = '';
			$typeCell = $getCell($typeCol, $headerIndex, $rowByIdx);
			if ($typeCell !== null && $typeCell !== '') {
				$paytypeLabel = trim((string)$typeCell);
			}

			$amount = 0.0;
			$valCell = $getCell($valueCol, $headerIndex, $rowByIdx);
			if ($valCell !== null && $valCell !== '') {
				$amount = $toMoney($valCell);
			}

			if ($paytypeLabel === '' || $amount == 0.0) {
				continue; // nothing to insert
			}

			// try match existing by encrypted name if no EMP_KEY
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

			// create new employee if still not found
			if ($empKey === -1) {
				if ($firstname === '') {
					$firstname = 'Empty';
				}

				// DOB
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

				// basic/base pay -> annual salary
				$annualSalary = 0.0;
				$lowerLabel   = mb_strtolower($paytypeLabel, 'UTF-8');
				if ($amount > 0 && (strpos($lowerLabel, 'basic') !== false || strpos($lowerLabel, 'base') !== false)) {
					$annualSalary = $amount * 12.0;
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

			// period/year
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

			// one actuals row per vertical row
			$typeGroupRef = $getGroupRef($paytypeLabel, null);

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

		$pdo->commit();

		$nameMode = $_POST['nameMode'] ?? 'single';
		vertical_saveMapping(
			$pdo,
			$mappingTable,
			(int)$ref,
			$header,
			$map,
			$nameMode
		);

		unset($_SESSION['excel_advanced_uploads'][$uploadId]);
		@unlink($filePath);

		echo "
		<div class='menuHeader'>
			<strong>Successful Import</strong>
			<button onclick=\"destroyMenu('menuContainer');\">X</button>
		</div>
		";

		$plural = ($rowCount === 1) ? '' : 's';
		echo "Successfully imported $rowCount pay line$plural.";

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

	// STEP 1: file just uploaded – show mapping form
	if (isset($_FILES['spreadsheet'])) {
		$uploadedFile = $_FILES['spreadsheet']['tmp_name'];

		if (!is_uploaded_file($uploadedFile) || !is_readable($uploadedFile)) {
			http_response_code(400);
			echo 'There was an error processing the file.';
			return;
		}

		$tmpDir   = __DIR__ . '/../tmp';
		$uploadId = bin2hex(random_bytes(16));
		$target   = $tmpDir . DIRECTORY_SEPARATOR . 'accelulator_vert_' . $uploadId . '.xlsx';

		if (!move_uploaded_file($uploadedFile, $target)) {
			throw new RuntimeException('Failed to store uploaded file.');
		}

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

		$savedMapping = vertical_loadSavedMapping($pdo, $mappingTable, (int)$ref, $header);

		if (!isset($_SESSION['excel_advanced_uploads'])) {
			$_SESSION['excel_advanced_uploads'] = [];
		}
		$_SESSION['excel_advanced_uploads'][$uploadId] = $target;

		vertical_renderMappingForm(
			$header,
			$uploadId,
			null,
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

	error_log('verticalExcelUpload: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
	http_response_code(500);
	echo 'There was an error processing the file.';
	return;
}