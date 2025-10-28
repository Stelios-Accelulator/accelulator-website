<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crypto.php';

$companyRef = 2;
$tableResources = sprintf('`%d_resources`', (int)$companyRef);

// 1) fresh data key for this company, wrap with master key
$dk = random_bytes(32);
$mk = mo_master_key();
if (strlen($mk) !== 32) { die("Master key missing/invalid\n"); }

$nonce   = random_bytes(24);
$wrapped = $nonce . sodium_crypto_secretbox($dk, $nonce, $mk);

// upsert company key
$up = $pdo->prepare("
  INSERT INTO company_keys (COMPANY_REF, KEY_WRAPPED, CREATED_AT)
  VALUES (:r, :k, NOW())
  ON DUPLICATE KEY UPDATE KEY_WRAPPED = VALUES(KEY_WRAPPED), CREATED_AT = VALUES(CREATED_AT)
");
$up->execute([':r' => $companyRef, ':k' => $wrapped]);

echo "Wrapped new data key for company {$companyRef}\n";

// 2) re-encrypt names from legacy plaintext columns in THIS company's table
$sel = $pdo->query("
  SELECT REF, FIRSTNAME, MIDDLENAME, SURNAME
  FROM {$tableResources}
");

$upd = $pdo->prepare("
  UPDATE {$tableResources}
  SET FIRSTNAME_ENC = :f,
	  MIDDLENAME_ENC = :m,
	  SURNAME_ENC    = :s,
	  NAME_TAG       = :tag
  WHERE REF = :ref
");

// helper
function enc_field(?string $plain, string $dk): string {
  if ($plain === null) $plain = '';
  $n = random_bytes(24);
  $c = sodium_crypto_secretbox($plain, $n, $dk);
  return $n . $c; // nonce||ciphertext
}

$tag = random_bytes(32);

$pdo->beginTransaction();
$count = 0;
while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
  $upd->execute([
	':f'   => enc_field($row['FIRSTNAME'],  $dk),
	':m'   => enc_field($row['MIDDLENAME'], $dk),
	':s'   => enc_field($row['SURNAME'],    $dk),
	':tag' => $tag,
	':ref' => $row['REF'],
  ]);
  $count += $upd->rowCount(); // will be 0 if row already matches
}
$pdo->commit();

echo "Re-encrypted {$count} rows in {$tableResources}.\n";

// (optional) sanity check
$total = (int)$pdo->query("SELECT COUNT(*) FROM {$tableResources}")->fetchColumn();
echo "Table has {$total} rows total.\n";