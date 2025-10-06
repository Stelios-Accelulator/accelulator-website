<?
spl_autoload_register(function ($class) {
	$prefixes = [
		'PhpOffice\\PhpSpreadsheet\\' => __DIR__ . '/src/PhpSpreadsheet/',
		'Psr\\SimpleCache\\' => __DIR__ . '/../Psr/SimpleCache/',
		'Composer\\Pcre\\' => __DIR__ . '/../Composer/Pcre/', // ← add this line
	];

	foreach ($prefixes as $prefix => $base_dir) {
		if (strpos($class, $prefix) === 0) {
			$relative_class = substr($class, strlen($prefix));
			$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

			if (file_exists($file)) {
				require $file;
			}
		}
	}
});
?>