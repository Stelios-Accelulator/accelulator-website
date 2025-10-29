<?php
/**
 * crypto.php — minimal, defensive helpers for per-company encryption.
 *
 * Requirements:
 *   - httpd.private/env.php must set ACCELULATOR_MASTER_KEY (base64, 32 bytes)
 *   - company_keys.KEY_WRAPPED stores nonce||ciphertext (either secretbox or AEAD)
 *   - Encrypted person fields store nonce||ciphertext (same idea; we decrypt with the data key)
 */

if (!function_exists('sodium_crypto_secretbox_open')) {
	throw new RuntimeException('Libsodium not available');
}

/* -----------------------------
 * Master key (from env.php)
 * ----------------------------- */
if (!function_exists('mo_master_key')) {
	function mo_master_key(): string {
		// Ensure env.php is loaded (it does putenv('ACCELULATOR_MASTER_KEY=...'))
		$env = __DIR__ . '/../httpd.private/env.php';
		if (!getenv('ACCELULATOR_MASTER_KEY') && is_file($env)) {
			@require_once $env;
		}
		$b64 = getenv('ACCELULATOR_MASTER_KEY') ?: '';
		$mk  = base64_decode($b64, true) ?: '';
		return $mk; // must be 32 bytes
	}
}

/* -----------------------------
 * Normalise DB blob to raw bin
 * ----------------------------- */
if (!function_exists('mo_norm_blob')) {
	function mo_norm_blob($v): string {
		if ($v === null || $v === '') return '';
		if (is_string($v) && strncasecmp($v, '0x', 2) === 0) {
			$bin = @hex2bin(substr($v, 2));
			return $bin === false ? '' : $bin;
		}
		if (is_string($v) && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $v)) {
			$bin = base64_decode($v, true);
			if ($bin !== false) return $bin;
		}
		return (string)$v;
	}
}

/* -------------------------------------------------
 * Unwrap the per-company data key from company_keys
 * Tries secretbox first, then AEAD XChaCha20-Poly1305
 * ------------------------------------------------- */
if (!function_exists('company_data_key')) {
	function company_data_key(PDO $pdo, ?int $companyRef = null): string {
		$companyRef = $companyRef ?? ($GLOBALS['ref'] ?? null);
		if (!is_int($companyRef)) return '';

		$stmt = $pdo->prepare("
			SELECT KEY_WRAPPED
			FROM company_keys
			WHERE COMPANY_REF = :r
			LIMIT 1
		");
		$stmt->execute([':r' => $companyRef]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) return '';
		
		// --- DIAGNOSTIC: inspect the wrapped blob ---
		$wrappedRaw = $row['KEY_WRAPPED'] ?? null;
		$wrapped    = mo_norm_blob($wrappedRaw);
		$totLen     = strlen($wrapped);
		$headHex    = bin2hex(substr($wrapped, 0, 16));
		
		if ($wrapped === '' || $totLen < 25) return '';
		
		// Most schemes here use a 24-byte nonce (secretbox / XChaCha20)
		$nonce = substr($wrapped, 0, 24);
		$ct    = substr($wrapped, 24);
		$mk    = mo_master_key();
		
		// Try secretbox unwrap
		$dk = @sodium_crypto_secretbox_open($ct, $nonce, $mk);
		if ($dk !== false && strlen($dk) === 32) return $dk;
		
		// Try AEAD XChaCha20-Poly1305
		if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
			$dk2 = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, '', $nonce, $mk);
			if ($dk2 !== false && strlen($dk2) === 32) return $dk2;
		}
		
		// Heuristic fallback for historical AES-GCM wraps stored as nonce(12)||cipher||tag(16)
		if (function_exists('openssl_decrypt') && $totLen >= (12 + 16 + 1)) {
			$gcmNonce = substr($wrapped, 0, 12);
			$gcmBody  = substr($wrapped, 12);
			$gcmTag   = substr($gcmBody, -16);
			$gcmCt    = substr($gcmBody, 0, -16);
			$dk3 = @openssl_decrypt($gcmCt, 'aes-256-gcm', $mk, OPENSSL_RAW_DATA, $gcmNonce, $gcmTag, '');
			if ($dk3 !== false && strlen($dk3) === 32) return $dk3;
		}
		
		return '';
		
		// END DIAGNOSTIC
		
		$wrapped = mo_norm_blob($row['KEY_WRAPPED'] ?? null);
		if ($wrapped === '' || strlen($wrapped) < 25) return '';

		$nonce = substr($wrapped, 0, 24);
		$ct    = substr($wrapped, 24);

		$mk = mo_master_key();
		if (strlen($mk) !== 32) return '';

		// Try secretbox unwrap
		$dk = @sodium_crypto_secretbox_open($ct, $nonce, $mk);
		if ($dk !== false && strlen($dk) === 32) {
			return $dk;
		}

		// Try AEAD XChaCha20-Poly1305 (ciphertext contains MAC/tag already)
		if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
			$dk2 = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, '', $nonce, $mk);
			if ($dk2 !== false && strlen($dk2) === 32) {
				return $dk2;
			}
		}

		// Unwrap failed
		return '';
	}
}

/* -------------------------------------------------
 * Decrypt a single field (nonce||ciphertext) using
 * the per-company data key. Extra args ($iv,$tag)
 * are accepted/ignored to keep older call sites.
 * ------------------------------------------------- */
if (!function_exists('decrypt_field')) {
	function decrypt_field($cipher, $iv = null, $tag = null, ?int $companyRef = null): string {
		if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) return '';
		/** @var PDO $pdo */
		$pdo = $GLOBALS['pdo'];

		$dk = company_data_key($pdo, $companyRef);
		if ($dk === '' || strlen($dk) !== 32) return '';

		$blob = mo_norm_blob($cipher);
		if ($blob === '' || strlen($blob) < 25) return '';

		$nonce = substr($blob, 0, 24);
		$ct    = substr($blob, 24);

		// Names were encrypted with secretbox — try that first
		$pt = @sodium_crypto_secretbox_open($ct, $nonce, $dk);
		if ($pt !== false) return $pt;

		// If they were produced with AEAD, this will work instead
		if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
			$pt2 = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, '', $nonce, $dk);
			if ($pt2 !== false) return $pt2;
		}

		return '';
	}
}