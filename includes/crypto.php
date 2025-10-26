<?php
// includes/crypto.php
declare(strict_types=1);

if (!function_exists('sodium_crypto_secretbox')) {
	// If libsodium is not available, we will fallback to OpenSSL later if needed.
}

function crypto_master_key(): string {
	$k = getenv('ACCELULATOR_MASTER_KEY');
	if (!$k || strlen(base64_decode($k, true)) !== 32) {
		throw new RuntimeException('ACCELULATOR_MASTER_KEY missing or not 32-byte base64.');
	}
	return base64_decode($k);
}

// Derive a KDF subkey (for wrapping keys or tags) using libsodium generichash
function crypto_kdf(string $key, string $ctx, int $len = 32): string {
	return sodium_crypto_generichash($ctx, $key, $len);
}

/**
 * Return raw 32-byte company data key (unwrapped).
 * - Creates & stores one if missing.
 */
function company_data_key(PDO $pdo, int $companyRef): string {
	// Try fetch existing
	$stmt = $pdo->prepare("SELECT KEY_WRAPPED FROM company_keys WHERE COMPANY_REF = ?");
	$stmt->execute([$companyRef]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	$mk = crypto_master_key();
	$wrapKey = crypto_kdf($mk, 'ACCELULATOR_WRAP_KEY'); // derive a wrapping key

	if ($row) {
		$wrapped = $row['KEY_WRAPPED'];
		$nonce = substr($wrapped, 0, 24);
		$ct    = substr($wrapped, 24);
		$key = sodium_crypto_secretbox_open($ct, $nonce, $wrapKey);
		if ($key === false || strlen($key) !== 32) {
			throw new RuntimeException('Failed to unwrap company key.');
		}
		return $key;
	}

	// Create a new data key
	$key = random_bytes(32);
	$nonce = random_bytes(24);
	$ct = sodium_crypto_secretbox($key, $nonce, $wrapKey);
	$wrapped = $nonce . $ct;

	$ins = $pdo->prepare("INSERT INTO company_keys (COMPANY_REF, KEY_WRAPPED) VALUES (?, ?)");
	$ins->execute([$companyRef, $wrapped]);
	return $key;
}

/** Encrypt a field -> binary: nonce||ciphertext */
function enc_field(string $plaintext, string $dataKey): string {
	$nonce = random_bytes(24);
	$ct = sodium_crypto_secretbox($plaintext, $nonce, $dataKey);
	return $nonce . $ct;
}

/** Decrypt a field */
function dec_field(?string $blob, string $dataKey): ?string {
	if ($blob === null) return null;
	$nonce = substr($blob, 0, 24);
	$ct    = substr($blob, 24);
	$pt = sodium_crypto_secretbox_open($ct, $nonce, $dataKey);
	return ($pt === false) ? null : $pt;
}

/** Build deterministic equality tag for name matching inside a company */
function name_tag(string $first, string $middle, string $last, string $dataKey): string {
	$norm = trim(mb_strtolower(preg_replace('/\s+/', ' ', "{$first}|{$middle}|{$last}")));
	// Derive a tag key from the data key to reduce key reuse
	$tagKey = crypto_kdf($dataKey, 'ACCELULATOR_TAG_KEY');
	return hash_hmac('sha256', $norm, $tagKey, true); // 32 bytes
}