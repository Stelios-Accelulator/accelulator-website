<?php
// Boot PhpSpreadsheet autoloader (Composer first, then legacy libs)
(function () {
	$paths = [
		__DIR__ . '/../vendor/autoload.php',                 // Composer
		__DIR__ . '/../libs/autoload.php',                   // legacy
		__DIR__ . '/../libs/PhpSpreadsheet/autoloader.php',  // legacy
		__DIR__ . '/../libs/PhpSpreadsheet/Autoloader.php',  // legacy (case)
		__DIR__ . '/../libs/PhpSpreadsheet/src/PhpSpreadsheet/Autoloader.php',
	];
	foreach ($paths as $p) {
		if (is_file($p)) { require_once $p; break; }
	}
})();

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Return the active sheet as a 2D array (compatible with your previous helper).
 *
 * @param string      $uploadedFilePath  path to uploaded file
 * @param null|string $readerType        e.g. 'Xlsx' if you want to force it; otherwise auto-detect
 * @return array
 */
if (!function_exists('loadPhpSpreadsheet')) {
	function loadPhpSpreadsheet(string $uploadedFilePath, ?string $readerType = null): array
	{
		if (!is_readable($uploadedFilePath)) {
			return [];
		}

		// Auto-detect reader or use the one you pass in
		$reader = $readerType
			? IOFactory::createReader($readerType)
			: IOFactory::createReaderForFile($uploadedFilePath);

		// Read values only (faster, matches your usage)
		if (method_exists($reader, 'setReadDataOnly')) {
			$reader->setReadDataOnly(true);
		}

		$spreadsheet = $reader->load($uploadedFilePath);
		return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
	}
}