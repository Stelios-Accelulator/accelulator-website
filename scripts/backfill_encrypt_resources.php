<?php
// backfill_encrypt_resources.php
// Run via: https://yourdomain/scripts/backfill_encrypt_resources.php
// Prereqs: columns FIRSTNAME_ENC, MIDDLENAME_ENC, SURNAME_ENC, NAME_TAG exist
//          crypto.php defines company_data_key(), enc_field(), name_tag()

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php'; // gives $pdo
require_once __DIR__ . '/../includes/crypto.php';

try {
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	// 1) Find all *_resources tables for this database
	// NOTE: Don't use "ESCAPE '\'" — some MariaDB builds error on it.
	//       We also restrict to names like "123_resources" to avoid false positives.
	$tablesStmt = $pdo->query("
		SELECT TABLE_NAME
		FROM INFORMATION_SCHEMA.TABLES
		WHERE TABLE_SCHEMA = DATABASE()
		  AND TABLE_NAME LIKE '%\\_resources'
		  AND TABLE_NAME REGEXP '^[0-9]+_resources$'
	");
	$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

	if (!$tables) {
		echo "No *_resources tables found.\n";
		exit;
	}

	$totalUpdated = 0;

	foreach ($tables as $table) {
		if (!preg_match('/^(\d+)_resources$/', $table, $m)) {
			continue; // extra safety
		}
		$companyRef = (int)$m[1];

		// Company key (from crypto.php; uses env.php master key internally)
		$key = company_data_key($pdo, $companyRef);
		if (!$key) {
			echo "WARN: No company key for {$table} (ref {$companyRef}); skipping.\n";
			continue;
		}

		echo "Processing {$table} (company {$companyRef})…\n";

		// 2) Select rows that still need encryption (have plaintext but enc is NULL)
		$sel = $pdo->prepare("
			SELECT REF, FIRSTNAME, MIDDLENAME, SURNAME
			FROM `{$table}`
			WHERE (FIRSTNAME_ENC IS NULL OR MIDDLENAME_ENC IS NULL OR SURNAME_ENC IS NULL)
			  AND (FIRSTNAME IS NOT NULL OR MIDDLENAME IS NOT NULL OR SURNAME IS NOT NULL)
		");

		$upd = $pdo->prepare("
			UPDATE `{$table}`
			SET FIRSTNAME_ENC = :fe,
				MIDDLENAME_ENC = :me,
				SURNAME_ENC    = :se,
				NAME_TAG       = :tag
			WHERE REF = :id
			LIMIT 1
		");

		$pdo->beginTransaction();

		$sel->execute();
		$count = 0;

		while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
			$f = (string)($row['FIRSTNAME']   ?? '');
			$m = (string)($row['MIDDLENAME']  ?? '');
			$s = (string)($row['SURNAME']     ?? '');

			$fe  = enc_field($f, $key);
			$me  = enc_field($m, $key);
			$se  = enc_field($s, $key);
			$tag = name_tag($f, $m, $s, $key);

			$upd->execute([
				':fe'  => $fe,
				':me'  => $me,
				':se'  => $se,
				':tag' => $tag,
				':id'  => (int)$row['REF'],
			]);
			$count++;
		}

		$pdo->commit();
		$totalUpdated += $count;

		echo "  -> updated {$count} row(s)\n";
	}

	echo "Done. Total rows updated: {$totalUpdated}\n";

} catch (Throwable $e) {
	http_response_code(500);
	echo "ERROR: " . $e->getMessage() . "\n";
	echo "In " . $e->getFile() . ":" . $e->getLine() . "\n";
}