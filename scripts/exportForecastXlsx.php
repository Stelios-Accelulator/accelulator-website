<?php
declare(strict_types=1); // makes PHP stricter about type coercion in function calls

if (session_status() === PHP_SESSION_NONE) session_start(); // starts a session if one isn't already running (needed for auth/CSRF)

/* --- ERROR SETTINGS ---
Reports all errors internally, but does not display them to the browser (display_errors=0). Still logs errors via error_log(...) later.
*/
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/functions.php'; // Pulls in my shared functions.php

/* ---------------- Debug mode ----------------
   Visit: /scripts/exportForecastXlsx.php?...&debug=1
   Prints diagnostics and exits (no XLSX output).
------------------------------------------------ */
if (isset($_GET['diag'])) {
  header('Content-Type: text/plain; charset=utf-8');

  echo "== exportForecastXlsx debug ==\n";
  echo "PHP: " . PHP_VERSION . "\n";
  echo "ZipArchive: " . (class_exists('ZipArchive') ? 'YES' : 'NO') . "\n";
  echo "mbstring: " . (extension_loaded('mbstring') ? 'YES' : 'NO') . "\n";

  $helper = __DIR__ . '/../includes/loadPhpSpreadsheet.php';
  echo "Helper path: $helper (" . (file_exists($helper) ? 'exists' : 'MISSING') . ")\n";

  try {
	$loaded = false;
	
	if (file_exists($helper)) {
	  require_once $helper;
	  $loaded = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
	}
	
	if (!$loaded) {
	  foreach ([
		__DIR__ . '/../libs/autoload.php',              // composer-style in /libs
		__DIR__ . '/../vendor/autoload.php',            // standard composer vendor
		__DIR__ . '/../libs/PhpSpreadsheet/Autoloader.php',
		__DIR__ . '/../libs/PhpSpreadsheet/autoloader.php',
		__DIR__ . '/../libs/PhpSpreadsheet/src/PhpSpreadsheet/Autoloader.php',
	  ] as $try) {
		if (file_exists($try)) {
		  require_once $try;
		  if (class_exists(\PhpOffice\PhpSpreadsheet\Autoloader::class)) {
			\PhpOffice\PhpSpreadsheet\Autoloader::register();
		  }
		  if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
			echo "Loaded via: $try\n";
			$loaded = true;
			break;
		  }
		}
	  }
	}
	
	echo "Spreadsheet class: " . ($loaded ? 'OK' : 'MISSING') . "\n";
  } catch (Throwable $e) {
	echo "Autoload EXCEPTION: " . $e->getMessage() . "\n";
	exit;
  }

  exit; // stop in debug mode
}

/* ---------------- Normal mode: Auth + CSRF ---------------- 
- Requires the user to be logged in
- Enforces CSRF token validation for normal requests
- *Exception:* if ?throw=1 is passed, it skips CSRF. Should remove this
*/
$user = checkUser();
if (!$user) { http_response_code(401); exit('Not authenticated'); }
$skipCsrfForThrow = isset($_GET['throw']) || isset($_GET['debug_throw']);
if (!$skipCsrfForThrow) {
  if (!function_exists('validateCsrfToken') || !validateCsrfToken()) {
	http_response_code(403); exit('Forbidden');
  }
}

/* ---------------- PhpSpreadsheet autoload (helper, with fallbacks) ---------------- */
$loaderOk = false;

$helper = __DIR__ . '/../includes/loadPhpSpreadsheet.php';
if (file_exists($helper)) {
  require_once $helper;
  $loaderOk = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
}

if (!$loaderOk) {
  foreach ([
	__DIR__ . '/../libs/autoload.php',
	__DIR__ . '/../vendor/autoload.php',
	__DIR__ . '/../libs/PhpSpreadsheet/Autoloader.php',
	__DIR__ . '/../libs/PhpSpreadsheet/autoloader.php',
	__DIR__ . '/../libs/PhpSpreadsheet/src/PhpSpreadsheet/Autoloader.php',
  ] as $try) {
	if (file_exists($try)) {
	  require_once $try;
	  if (class_exists(\PhpOffice\PhpSpreadsheet\Autoloader::class)) {
		\PhpOffice\PhpSpreadsheet\Autoloader::register();
	  }
	  if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) { $loaderOk = true; break; }
	}
  }
}

if (!$loaderOk) { http_response_code(500); exit('Spreadsheet library unavailable.'); }
if (!class_exists('ZipArchive')) { http_response_code(500); exit('The PHP Zip extension is required to write .xlsx files.'); }

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Settings;

// --- Force PhpSpreadsheet to use the native PHP ZipArchive backend ---
if (!class_exists('ZipArchive')) {
	http_response_code(500);
	exit('The PHP Zip extension (ZipArchive) is required to write .xlsx files.');
}

// Some installs or helpers set ZipStream; override it explicitly to ZipArchive
if (class_exists(\PhpOffice\PhpSpreadsheet\Settings::class)
	&& method_exists(\PhpOffice\PhpSpreadsheet\Settings::class, 'setZipClass')) {
	\PhpOffice\PhpSpreadsheet\Settings::setZipClass('ZipArchive');  // <- the important line
}

/* ---------------- Input ---------------- 
Expects 3 query params:
- mix (Actual/forecast selector; also used as ACTUAL_FORECAST)
- name (forecast name)
- version (integer)

Rejects if any of these are missing.
*/

$mix     = isset($_GET['mix'])     ? trim((string)$_GET['mix'])     : '';
$name    = isset($_GET['name'])    ? trim((string)$_GET['name'])    : '';
$version = isset($_GET['version']) ? (int)$_GET['version']          : 0;

if ($mix === '' || $name === '' || $version <= 0) {
  http_response_code(400); exit('Missing or invalid parameters.');
}

/* ---------------- Company tables ---------------- 
Determines the tables required for the download
*/
$ref = getUsersCompanyId($user);
if ($ref === null) { http_response_code(400); exit('Company not found'); }

$tableF = $ref . '_forecasts';
$tableR = $ref . '_resources';
$tableL = $ref . '_roles';
$tableD = $ref . '_departments';

/* ---------------- Build workbook ---------------- */
try { // Forces PDO to throw exceptions on SQL errors (so we can catch them).
  // If you want SQL errors to throw:
  if ($pdo instanceof PDO) { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }

  $sql = "
	/* Find most recent active role per filled resource (FILLED_REFERENCE) */
	WITH recent_roles AS (
	  SELECT rl.*
	  FROM {$tableL} rl
	  JOIN (
		SELECT FILLED_REFERENCE, MAX(START_DATE) AS START_DATE
		FROM {$tableL}
		WHERE STATUS = 4
		GROUP BY FILLED_REFERENCE
	  ) pick
		ON pick.FILLED_REFERENCE = rl.FILLED_REFERENCE
	   AND pick.START_DATE       = rl.START_DATE
	  WHERE rl.STATUS = 4
	)
	SELECT
	  f.PAY_ELEMENT,
	  f.MONTH,
	  f.VALUE,
	  f.IS_ACTUAL,
	  f.ROLE_REFERENCE,
	  f.TYPE,
	
	  COALESCE(l_by_ref.REF, rr.REF, rr_by_ref.REF) AS ROLE_REF,
	  COALESCE(l_by_ref.JOB_TITLE, rr.JOB_TITLE, rr_by_ref.JOB_TITLE, 'Unknown role') AS ROLE_TITLE,
	  d.DEPARTMENT AS DEPARTMENT_NAME,
	
	  (
		SELECT MIN(DATESTAMP)
		FROM {$tableF}
		WHERE ACTUAL_FORECAST = :mix2
		  AND FORECAST_NAME   = :name2
		  AND FORECAST_VERSION= :version2
	  ) AS CREATED_AT
  
	FROM {$tableF} f
  
	/* Direct match: if ROLE_REFERENCE is a role REF, grab title */
	LEFT JOIN {$tableL} l_by_ref
	  ON l_by_ref.REF = f.ROLE_REFERENCE
	 AND l_by_ref.STATUS = 4
	 
	 /* Most recent active role for a resource: if ROLE_REFERENCE is a FILLED_REFERENCE */
	 LEFT JOIN recent_roles rr
	   ON rr.FILLED_REFERENCE = f.ROLE_REFERENCE
	 
	 /* Some schemas store role REF in ROLE_REFERENCE even for resource rows; cover that too */
	 LEFT JOIN {$tableL} rr_by_ref
	   ON rr_by_ref.REF = f.ROLE_REFERENCE
	  AND rr_by_ref.STATUS = 4
	 
	 /* Departments */
	 LEFT JOIN {$tableD} d
	   ON d.REF = COALESCE(l_by_ref.DEPARTMENT, rr.DEPARTMENT, rr_by_ref.DEPARTMENT)
	
	WHERE f.ACTUAL_FORECAST  = :mix
	  AND f.FORECAST_NAME    = :name
	  AND f.FORECAST_VERSION = :version
  
	ORDER BY
	  COALESCE(STR_TO_DATE(f.MONTH, '%e %b-%y'), STR_TO_DATE(f.MONTH, '%b-%y')) ASC,
	  f.DATESTAMP ASC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
	  ':mix'=>$mix, 
	  ':name'=>$name, 
	  ':version'=>$version,
	  ':mix2'=>$mix, 
	  ':name2'=>$name, 
	  ':version2'=>$version
  ]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) { http_response_code(404); exit('No forecast rows found.'); }

  // Group rows into tabs by PAY_ELEMENT
  // ---- tab normalisation + ordering ----
  $tabOrder = [
	'Total Costs',
	'Base',
	'Overtime',
	'On Call',
	'Bonus',
	'Other',
	'Welfare',
	'Pension',
	'Statutory Pay',
	'Employers NI',
	'Commission',
	'Employee Costs',
  ];
  
  // map raw PAY_ELEMENT -> sheet name (adjust keys to match your DB values)
  $tabMap = [
	'total_costs'	=> 'Total Costs',
	'total costs'	=> 'Total Costs',
	'totalCosts'	=> 'Total Costs',
	'totalcosts'	=> 'Total Costs',
	'total'			=> 'Total Costs',
  
	'base'			=> 'Base',
	'basic'			=> 'Base',
	'basic_salary'	=> 'Base',
  
	'overtime'		=> 'Overtime',
	'on_call'		=> 'On Call',
	'oncall'		=> 'On Call',
  
	'bonus'			=> 'Bonus',
	'other'			=> 'Other',
	'type'			=> 'Other',
	'welfare'		=> 'Welfare',
	'pension'		=> 'Pension',
  
	'statutorypay'	=> 'Statutory Pay',
	'statutory_pay'	=> 'Statutory Pay',
  
	'employers_ni'	=> 'Employers NI',
	'employers ni'	=> 'Employers NI',
  
	'commission'	=> 'Commission',
	'employee_costs'=> 'Employee Costs',
	'employeecosts'	=> 'Employee Costs',
  ];
  
  // Build tabs (and optionally drop rogue pay elements like "type")
  $tabs = [];
  $createdAt = $rows[0]['CREATED_AT'];
  
  foreach ($rows as $r) {
	$raw = strtolower(trim((string)$r['PAY_ELEMENT']));
	$raw = str_replace(['-', '_'], [' ', ' '], $raw);
	$raw = preg_replace('/\s+/', ' ', $raw);
  
	// If you want to *drop* unexpected PAY_ELEMENTs (e.g. "type"), keep this:
	if (!isset($tabMap[$raw])) {
	  // either drop:
	  // continue;
  
	  // or bucket into Other:
	  $tab = 'Other';
	} else {
	  $tab = $tabMap[$raw];
	}
  
	$tabs[$tab][] = $r;
  }
  
  // Re-order tabs exactly as requested (and ignore any empties)
  $orderedTabs = [];
  foreach ($tabOrder as $tabName) {
	if (!empty($tabs[$tabName])) {
	  $orderedTabs[$tabName] = $tabs[$tabName];
	}
  }
  
  $ss = new Spreadsheet();
  $ss->getProperties()
	 ->setCreator('Accelulator')
	 ->setTitle("$mix $name $version Forecast")
	 ->setSubject('Forecast export');
  
  $i = 0;
  
  // If anything exists outside your order (shouldn’t, if you drop/Other them), append it
  foreach ($tabs as $tabName => $tabRows) {
	if (!isset($orderedTabs[$tabName])) {
	  $orderedTabs[$tabName] = $tabRows;
	}
  }
  
  foreach ($orderedTabs as $tabName => $tabRows) {
	$ws = ($i === 0) ? $ss->getActiveSheet() : $ss->createSheet();
	$ws->setTitle(substr($tabName, 0, 31));

	$ws->setCellValue('A1', "$mix $name $version");
	$ws->setCellValue('A2', 'Forecast');
	$ws->setCellValue('A3', $tabName);
	$ws->setCellValue('D1', 'Created:');
	$ws->setCellValue('E1', $createdAt);

	$ws->setCellValue('A5', 'Ref');
	$ws->setCellValue('B5', 'Role');
	$ws->setCellValue('C5', 'Department');
	$ws->setCellValue('D5', 'Type');
	$ws->setCellValue('E5', 'Month');
	$ws->setCellValue('F5', 'Value');

	$row = 6;
	foreach ($tabRows as $r) {
	  $ws->setCellValueExplicit("A{$row}", (string)($r['ROLE_REF'] ?? ''), DataType::TYPE_STRING);
	  $ws->setCellValueExplicit("B{$row}", (string)($r['ROLE_TITLE'] ?? ''), DataType::TYPE_STRING);
	  $ws->setCellValueExplicit("C{$row}", (string)($r['DEPARTMENT_NAME'] ?? ''), DataType::TYPE_STRING);
	  
	  $ws->setCellValueExplicit(
		"D{$row}",
		((int)$r['IS_ACTUAL'] === 1) ? 'Actual' : 'Forecast',
		DataType::TYPE_STRING
	  );
	  
	  // Month now in column E
	  $monthStr = trim((string)$r['MONTH']);
	  $monthDate = \DateTime::createFromFormat('M-y', $monthStr);
	  
	  if ($monthDate instanceof \DateTime) {
		$excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($monthDate);
		$ws->setCellValue("E{$row}", $excelDate);
		$ws->getStyle("E{$row}")->getNumberFormat()->setFormatCode('dd/mm/yy');
	  } else {
		$ws->setCellValueExplicit("E{$row}", $monthStr, DataType::TYPE_STRING);
	  }
	  
	  // Value now in column F
	  $ws->setCellValue("F{$row}", (float)$r['VALUE']);
	  $row++;
	}

	$ws->getStyle("A5:F5")->getFont()->setBold(true);
	
	$lastRow = $row - 1;
	
	$ws->getStyle("F6:F{$lastRow}")->getNumberFormat()
	   ->setFormatCode('_(* #,##0.00_);_(* (#,##0.00);_(* "-"??_);_(@_)');
	
	$ws->getStyle("A1:E1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
	$ws->getStyle("A5:F5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
	$ws->getStyle("F6:F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
	
	foreach (range('A', 'F') as $col) $ws->getColumnDimension($col)->setAutoSize(true);
	$ws->freezePane('A6');
	$i++;
  }

  $createdStr = date('Y-m-d H_i_s', strtotime($createdAt ?: 'now'));
  $filename = preg_replace('/[^\w\s\-\+]/', '', "$mix $name $version") . " Forecast $createdStr.xlsx";
  
  @ini_set('zlib.output_compression', 'Off');
  while (ob_get_level() > 0) { @ob_end_clean(); }
  
  $writer = new Xlsx($ss);
  $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
  $writer->save($tmp);
  
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Transfer-Encoding: binary');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Pragma: public');
  header('Content-Length: ' . filesize($tmp));
  
  $fh = fopen($tmp, 'rb');
  fpassthru($fh);
  fclose($fh);
  @unlink($tmp);
  exit;

} catch (Throwable $e) {
	// If you pass &debug=throw=1 we’ll show the real reason in the response
	if (isset($_GET['throw']) || isset($_GET['debug_throw'])) {
	  header('Content-Type: text/plain; charset=utf-8');
	  echo "EXCEPTION: " . $e->getMessage() . "\n";
	  if (isset($_GET['trace'])) {
		echo "\nTrace:\n" . $e->getTraceAsString();
	  }
	  exit;
	}
	error_log('exportForecastXlsx failed: ' . $e->getMessage());
	http_response_code(500);
	exit('Export failed while building file.');
  }