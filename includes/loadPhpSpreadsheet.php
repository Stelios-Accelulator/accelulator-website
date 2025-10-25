<?php
declare(strict_types=1);

/**
 * Try to load PhpSpreadsheet regardless of where it lives in this project.
 * Returns [bool $loaded, array $triedPaths] for easy debugging.
 */
function ensurePhpSpreadsheetLoaded(): array
{
	// Already available?
	if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
		return [true, []];
	}

	// Candidate autoloaders on this host
	$candidates = [
		// Most likely in THIS project (your tree shows vendor under /scripts)
		__DIR__ . '/../scripts/vendor/autoload.php',

		// In case you later move vendor to the project root
		__DIR__ . '/../vendor/autoload.php',

		// The legacy library copy you previously used
		__DIR__ . '/../libs/PhpSpreadsheet/autoloader.php',
	];

	$tried = [];
	foreach ($candidates as $path) {
		$tried[] = $path;
		if (is_file($path)) {
			require_once $path;
			if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
				return [true, $tried];
			}
		}
	}

	return [false, $tried];
}

/**
 * Convenience: read active sheet rows from a file path.
 * (Use this if you want to keep your old call site.)
 */
function loadPhpSpreadsheetRows(string $uploadedFilePath): array
{
	[$ok] = ensurePhpSpreadsheetLoaded();
	if (!$ok) {
		throw new \RuntimeException('PhpSpreadsheet not available');
	}

	return \PhpOffice\PhpSpreadsheet\IOFactory::load($uploadedFilePath)
		->getActiveSheet()
		->toArray();
}