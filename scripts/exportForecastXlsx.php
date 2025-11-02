<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/functions.php';

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

/* ---------------- Normal mode: Auth + CSRF ---------------- */
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

/* ---------------- Input ---------------- */
$mix     = isset($_GET['mix'])     ? trim((string)$_GET['mix'])     : '';
$name    = isset($_GET['name'])    ? trim((string)$_GET['name'])    : '';
$version = isset($_GET['version']) ? (int)$_GET['version']          : 0;

if ($mix === '' || $name === '' || $version <= 0) {
  http_response_code(400); exit('Missing or invalid parameters.');
}

/* ---------------- Company tables ---------------- */
$ref = getUsersCompanyId($user);
if ($ref === null) { http_response_code(400); exit('Company not found'); }

$tableF = $ref . '_forecasts';
$tableR = $ref . '_resources';
$tableL = $ref . '_roles';

/* ---------------- Build workbook ---------------- */
try {
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
		WHERE STATUS = 1
		GROUP BY FILLED_REFERENCE
	  ) pick
		ON pick.FILLED_REFERENCE = rl.FILLED_REFERENCE
	   AND pick.START_DATE       = rl.START_DATE
	  WHERE rl.STATUS = 1
	)
	SELECT
	  f.PAY_ELEMENT,
	  f.MONTH,
	  f.VALUE,
	  f.IS_ACTUAL,
	  f.ROLE_REFERENCE,
	  f.TYPE,
  
	  /* Title resolution:
		 1) role rows -> title from the role itself
		 2) resource rows -> title from the most recent active role for that resource
		 fallback -> 'Employee' */
	  COALESCE(l_role.JOB_TITLE, rr.JOB_TITLE, 'Employee') AS DISPLAY_NAME,
  
	  /* Created-at timestamp for this (mix, name, version) */
	  (
		SELECT MIN(DATESTAMP)
		FROM {$tableF}
		WHERE ACTUAL_FORECAST = :mix2
		  AND FORECAST_NAME   = :name2
		  AND FORECAST_VERSION= :version2
	  ) AS CREATED_AT
  
	FROM {$tableF} f
  
	/* If this row represents a role, take its title directly */
	LEFT JOIN {$tableL} l_role
	  ON (f.TYPE LIKE 'role%%' AND l_role.REF = f.ROLE_REFERENCE)
  
	/* If this row represents a resource, pull the recent active role title via FILLED_REFERENCE */
	LEFT JOIN recent_roles rr
	  ON (f.TYPE LIKE 'resource%%' AND rr.FILLED_REFERENCE = f.ROLE_REFERENCE)
  
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
  $tabs = [];
  $createdAt = $rows[0]['CREATED_AT'];
  foreach ($rows as $r) {
	$tab = ucfirst(str_replace(['_', '-'], [' ', ' '], (string)$r['PAY_ELEMENT']));
	if (strcasecmp($tab, 'On call') === 0)    $tab = 'OnCall';
	if (strcasecmp($tab, 'Employers ni') === 0) $tab = 'Employers NI';
	$tabs[$tab][] = $r;
  }

  $ss = new Spreadsheet();
  $ss->getProperties()
	 ->setCreator('Accelulator')
	 ->setTitle("$mix $name $version Forecast")
	 ->setSubject('Forecast export');

  $i = 0;
  foreach ($tabs as $tabName => $tabRows) {
	$ws = ($i === 0) ? $ss->getActiveSheet() : $ss->createSheet();
	$ws->setTitle(substr($tabName, 0, 31));

	$ws->setCellValue('A1', "$mix $name $version");
	$ws->setCellValue('A2', 'Forecast');
	$ws->setCellValue('A3', $tabName);
	$ws->setCellValue('D1', 'Created:');
	$ws->setCellValue('E1', $createdAt);

	$ws->setCellValue('A5', 'Name');
	$ws->setCellValue('B5', 'Type');
	$ws->setCellValue('C5', 'Month');
	$ws->setCellValue('D5', 'Value');

	$row = 6;
	foreach ($tabRows as $r) {
	  $ws->setCellValueExplicit("A{$row}", $r['DISPLAY_NAME'] ?: '', DataType::TYPE_STRING);
	  $ws->setCellValueExplicit("B{$row}", ((int)$r['IS_ACTUAL'] === 1) ? 'Actual' : 'Forecast', DataType::TYPE_STRING);
	  // Convert "Jan-26" → "01/01/26" for Excel date recognition
	  $monthStr = trim((string)$r['MONTH']);
	  $monthDate = \DateTime::createFromFormat('M-y', $monthStr);
	  if ($monthDate instanceof \DateTime) {
		  $formattedMonth = $monthDate->format('d/m/y'); // 01/01/26
		  $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($monthDate);
		  $ws->setCellValue("C{$row}", $excelDate);
		  $ws->getStyle("C{$row}")
			 ->getNumberFormat()
			 ->setFormatCode('dd/mm/yy');
	  } else {
		  // Fallback: keep original if parse fails
		  $ws->setCellValueExplicit("C{$row}", $monthStr, DataType::TYPE_STRING);
	  }
	  $ws->setCellValue("D{$row}", (float)$r['VALUE']);
	  $row++;
	}

	$ws->getStyle("A5:D5")->getFont()->setBold(true);
	$ws->getStyle("D6:D{$row}")->getNumberFormat()
	   ->setFormatCode('_(* #,##0.00_);_(* (#,##0.00);_(* "-"??_);_(@_)');
	$ws->getStyle("A1:E1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
	$ws->getStyle("A5:D5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
	$ws->getStyle("D6:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

	foreach (range('A', 'E') as $col) $ws->getColumnDimension($col)->setAutoSize(true);
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