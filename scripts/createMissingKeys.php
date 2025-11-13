<?php
// /scripts/createMissingKeys.php

require_once __DIR__ . '/../includes/functions.php'; // this gives us $pdo + ensureCompanyKey()

try {
	// create for company 5
	ensureCompanyKey($pdo, 5);

	// create for company 8
	ensureCompanyKey($pdo, 8);

	echo "Keys created (or already existed) for companies 5 and 8.";
} catch (Throwable $e) {
	echo "Error: " . $e->getMessage();
}