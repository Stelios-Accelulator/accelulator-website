<?php
// getContractorNames.php
// --- ensure session + resolve company ref early ---

/* ---------- super-robust bootstrap & fatal diagnostics ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
register_shutdown_function(function() use ($DEBUG) {
	$e = error_get_last();
	if ($e && ($e['type'] & (E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR))) {
		http_response_code(500);
		$msg = "[monthlyOutturn fatal] {$e['message']} in {$e['file']}:{$e['line']}";
		// echo to browser in debug, and always write server error_log
		if ($DEBUG) echo '<pre style="white-space:pre-wrap;color:#b00;background:#fee;border:1px solid #f99;padding:8px;">'
						. htmlspecialchars($msg) . '</pre>';
		error_log($msg);
	}
});

/* ---------- includes with guards ---------- */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
$user = checkUser();
$ref = getUsersCompanyId($user);
$GLOBALS['ref'] = $ref; // <-- let crypto.php find the right company key

/* crypto is optional at bootstrap time — if it’s missing, we stub decrypt_field() so the page still loads */
$cryptoPath = __DIR__ . '/../includes/crypto.php';
if (is_file($cryptoPath)) {
	require_once $cryptoPath;
}

/* ---------- make sure we actually have a PDO ---------- */
if (!isset($pdo) || !($pdo instanceof PDO)) {
	// Try a couple of common helpers your codebase uses. If neither exists, this will stay null and our later try/catch will report it.
	if (function_exists('getPdo')) {
		$pdo = getPdo();
	} elseif (function_exists('connectToDb')) {
		$pdo = connectToDb();
	}
}

// ---------- small helpers ----------
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

function dbg($msg){
	global $DEBUG;
	if ($DEBUG) {
		echo '<pre style="margin:6px 0;color:#b00;background:#fee;border:1px solid #f99;padding:6px;">[DEBUG] '
			 . htmlspecialchars($msg) . '</pre>';
	}
}

// ⬇️ ADD THIS GUARD (or just delete this whole block if you prefer)
if (!function_exists('can_view_names')) {
	function can_view_names(array $userValue): bool {
		// fallback behaviour; your version in functions.php will take precedence
		return true;
	}
}

// Check that the user exists so that we don't have unauthorised entry
if ($user === ''){exit;}

// Now try and get ahold of the data
try {
	if (function_exists('company_data_key')){try{$dk = company_data_key($pdo, $ref);} catch (Throwable $e){}} // populate $dk with the correct key
	
	/* --- SET THE TABLES --- */
	$tResources	=	"{$ref}_resources";
	$tDetails	=	"{$ref}_details";
	
	dbg("Tables resolved for ref=$ref");
	
	/* --- DETECT ENCRYPTED NAME COLUMNS --- */
	$colStmt = $pdo->prepare("
	  SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
	  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl
	");
	$colStmt->execute([':tbl' => $tResources]);
	$cols = array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME', 'COLUMN_NAME');
	
	$hasEnc = isset($cols['FIRSTNAME_ENC']) && isset($cols['SURNAME_ENC']);
	$hasIv  = isset($cols['FIRSTNAME_IV'])  && isset($cols['SURNAME_IV']);
	$hasTagPerCol =
		(isset($cols['FIRSTNAME_TAG']) || isset($cols['NAME_TAG'])) &&
		(isset($cols['SURNAME_TAG'])   || isset($cols['NAME_TAG']));
	dbg('Encrypted columns present? ' . ($hasEnc ? 'YES' : 'NO') .
		' (IV cols: ' . ($hasIv ? 'YES' : 'NO') .
		', tag cols: ' . ($hasTagPerCol ? 'YES' : 'NO') . ')');
	
	/* --- flexible decrypt wrapper (robust + quiet) --- */
	if (!function_exists('safe_decrypt')) {
	
		/*
		 * Normalises DB cells into raw/base64 binary.
		 * Accepts hex ("0x..."), base64, or binary.
		 */
		function norm_enc($v): array {
			if ($v === null || $v === '') return ['', ''];
			if (is_string($v) && strncasecmp($v, '0x', 2) === 0) {
				$bin = hex2bin(substr($v, 2)) ?: '';
				return [$bin, base64_encode($bin)];
			}
			if (is_string($v) && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $v)) {
				$bin = base64_decode($v, true);
				if ($bin !== false) return [$bin, $v];
			}
			$bin = (string)$v;
			return [$bin, base64_encode($bin)];
		}
	
		/*
		 * Attempts to decrypt using the currently-loaded decrypt_field().
		 * Tries all common argument combinations automatically.
		 * Returns '' if all attempts fail.
		 */
		function safe_decrypt($cipher, $iv = null, $tag = null): string {
			if (!function_exists('decrypt_field')) return '';
	
			// Normalise to both raw + base64
			[$cRaw, $cB64] = norm_enc($cipher);
			[$iRaw, $iB64] = norm_enc($iv);
			[$tRaw, $tB64] = norm_enc($tag);
	
			$companyRef = $GLOBALS['ref'] ?? null;
			$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
	
			try {
				$rf = new ReflectionFunction('decrypt_field');
				$argc = $rf->getNumberOfParameters();
	
				// --- Fast 4-arg path (most likely success case) ---
				if ($argc >= 4) {
					$out = @decrypt_field($cipher, $iv, $tag, $companyRef);
					if (is_string($out) && $out !== '') {
						if ($DEBUG) echo "<script>console.log('[safe_decrypt] fast 4-arg ok');</script>";
						return $out;
					}
				}
	
				// --- Fallback permutations ---
				$attempts = [
					[4, fn() => decrypt_field($cRaw,  $iRaw ?: null, $tRaw ?: null, $companyRef)],
					[4, fn() => decrypt_field($cB64, $iB64 ?: null, $tB64 ?: null, $companyRef)],
					[3, fn() => decrypt_field($cRaw,  $iRaw ?: null, $tRaw ?: null)],
					[3, fn() => decrypt_field($cB64, $iB64 ?: null, $tB64 ?: null)],
					[2, fn() => decrypt_field($cRaw,  $iRaw ?: null)],
					[2, fn() => decrypt_field($cB64, $iB64 ?: null)],
					[1, fn() => decrypt_field($cRaw)],
					[1, fn() => decrypt_field($cB64)],
				];
	
				foreach ($attempts as [$need, $call]) {
					if ($argc >= $need) {
						$out = @($call) ?? '';
						if (is_string($out) && $out !== '') {
							if ($DEBUG) echo "<script>console.log('[safe_decrypt] fallback $need-arg ok');</script>";
							return $out;
						}
					}
				}
	
			} catch (Throwable $e) {
				if ($DEBUG) {
					echo "<script>console.warn('[safe_decrypt] exception', " . json_encode($e->getMessage()) . ");</script>";
				}
			}
	
			// If we get here, all attempts failed
			return '';
		}
	}
	
	/* ---------- build SELECT list for names (encrypt-only safe) ---------- */
	if ($hasEnc) {
		// always fetch encrypted fields + IV/tag if present
		$encSelect = "
			r.FIRSTNAME_ENC, r.MIDDLENAME_ENC, r.SURNAME_ENC,
			" . (isset($cols['FIRSTNAME_IV'])  ? "r.FIRSTNAME_IV,"  : "NULL AS FIRSTNAME_IV,") . "
			" . (isset($cols['MIDDLENAME_IV']) ? "r.MIDDLENAME_IV," : "NULL AS MIDDLENAME_IV,") . "
			" . (isset($cols['SURNAME_IV'])    ? "r.SURNAME_IV,"    : "NULL AS SURNAME_IV,") . "
			" . (isset($cols['NAME_TAG'])      ? "r.NAME_TAG"       : "NULL AS NAME_TAG") . "
		";
	
		// plaintext columns now may not exist; check before referencing them
		$hasPlainCols =
			isset($cols['FIRSTNAME']) ||
			isset($cols['MIDDLENAME']) ||
			isset($cols['SURNAME']);
	
		// If the plain columns are physically present and the user can view names,
		// we include them as LEGACY_*; otherwise we alias NULLs so SQL is valid.
		if ($hasPlainCols && can_view_names($_SESSION ?? [])) {
			$plainOrNullSelect = "
				, r.FIRSTNAME AS LEGACY_FIRSTNAME
				, r.MIDDLENAME AS LEGACY_MIDDLENAME
				, r.SURNAME    AS LEGACY_SURNAME
			";
		} else {
			$plainOrNullSelect = "
				, NULL AS LEGACY_FIRSTNAME
				, NULL AS LEGACY_MIDDLENAME
				, NULL AS LEGACY_SURNAME
			";
		}
	
		$nameSelect = $encSelect . $plainOrNullSelect;
	
	} else {
		// (Only used if encrypted columns don’t exist at all.)
		$nameSelect = "r.FIRSTNAME, r.MIDDLENAME, r.SURNAME";
	}
	
	/* --- DEBUG helpers (safe in production; remove later) --- */
	if (!function_exists('mo_console')) {
		function mo_console($label, $value = null) {
			$l = json_encode((string)$label);
			$v = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
		}
	}
	
	/* 1) show which can_view_names() we’re actually calling, and its result */
	try {
		$rf = new ReflectionFunction('can_view_names');
		$canSrc = ($rf->getFileName() ?: 'unknown') . ':' . $rf->getStartLine();
	} catch (Throwable $e) { $canSrc = 'reflection-failed'; }
	$canView = can_view_names($_SESSION ?? []);
	mo_console('can_view_names source', $canSrc);
	mo_console('can_view_names($_SESSION) =>', $canView);
	
	/* 2) log which name-select we’re using and the full SQL (collapsed in console) */
	// mo_console('hasEnc / depSel', ['hasEnc' => $hasEnc, 'depSel' => $depSel]);
	
	$sql = "
		SELECT
			r.REF AS RES_REF,
			$nameSelect
		FROM $tResources r
		WHERE r.CONTRACT_TYPE = 3
	";
	mo_console('SQL (resources)', $sql);
	
	/* 3) run & count */
	$resStmt = $pdo->query($sql);
	$resRows = $resStmt->fetchAll(PDO::FETCH_ASSOC);
	
	if (!empty($resRows)) {
		$probe = $resRows[0]['FIRSTNAME_ENC'] ?? null;
		$probePt = '';
		if (function_exists('decrypt_field')) {
			$rf = new ReflectionFunction('decrypt_field');
			$argc = $rf->getNumberOfParameters();
			$companyRef = $GLOBALS['ref'] ?? null;
			if ($argc >= 4) $probePt = @decrypt_field($probe, null, null, $companyRef) ?: '';
			elseif ($argc >= 1) $probePt = @decrypt_field($probe) ?: '';
		}
	}
	
	dbg('resources rows: ' . count($resRows));
	mo_console('resources row count', count($resRows));
	
	/* 4) peek at the first few rows’ encrypted fields (lengths + head bytes) */
	for ($i = 0; $i < min(3, count($resRows)); $i++) {
		$r = $resRows[$i];
		$peek = [
			'RES_REF'          => (int)$r['RES_REF'],
			'FIRSTNAME_ENC_len'=> isset($r['FIRSTNAME_ENC']) ? strlen((string)$r['FIRSTNAME_ENC']) : null,
			'MIDDLENAME_ENC_len'=>isset($r['MIDDLENAME_ENC'])? strlen((string)$r['MIDDLENAME_ENC']) : null,
			'SURNAME_ENC_len'  => isset($r['SURNAME_ENC']) ? strlen((string)$r['SURNAME_ENC']) : null,
			'IV_first_len'     => isset($r['FIRSTNAME_IV']) ? strlen((string)$r['FIRSTNAME_IV']) : null,
			'TAG_len'          => isset($r['NAME_TAG']) ? strlen((string)$r['NAME_TAG']) : null,
			'ENC_head_hex'     => isset($r['FIRSTNAME_ENC']) ? substr(bin2hex((string)$r['FIRSTNAME_ENC']),0,16) : null,
		];
		mo_console('peek resource enc fields', $peek);
	}
	
	/* 5) normal render, with per-row logging of decryption + JS push */
	$jsonID = [];
	$jsonName = [];
	$x = 0;
	foreach ($resRows as $row) {
		$id           = (int)$row['RES_REF'];
	
		if ($hasEnc) {
			if ($canView) {
				$tag = $row['NAME_TAG'] ?? null;
		
				$firstname  = safe_decrypt($row['FIRSTNAME_ENC']  ?? null, $row['FIRSTNAME_IV']  ?? null, $tag);
				$middlename = safe_decrypt($row['MIDDLENAME_ENC'] ?? null, $row['MIDDLENAME_IV'] ?? null, $tag);
				$surname    = safe_decrypt($row['SURNAME_ENC']    ?? null, $row['SURNAME_IV']    ?? null, $tag);
		
				// ⬇️ fallback to legacy if decrypt failed or returned empty strings
				if ($firstname === '' && isset($row['LEGACY_FIRSTNAME'])) {
					$firstname  = (string)($row['LEGACY_FIRSTNAME']  ?? '');
					$middlename = (string)($row['LEGACY_MIDDLENAME'] ?? '');
					$surname    = (string)($row['LEGACY_SURNAME']    ?? '');
					mo_console('decrypted empty → using legacy', ['RES_REF'=>$id,'first'=>$firstname,'last'=>$surname]);
				} else {
					mo_console('decrypted names', ['RES_REF'=>$id,'first'=>$firstname,'middle'=>$middlename,'last'=>$surname]);
				}
			} else {
				$firstname = 'Employee'; $middlename = ''; $surname = '#'.$id;
				mo_console('masked names (no view)', ['RES_REF'=>$id,'first'=>$firstname,'last'=>$surname]);
			}
			
		} else {
			$firstname  = (string)($row['FIRSTNAME']  ?? '');
			$middlename = (string)($row['MIDDLENAME'] ?? '');
			$surname    = (string)($row['SURNAME']    ?? '');
			mo_console('legacy names used', ['RES_REF'=>$id,'first'=>$firstname,'last'=>$surname]);
		}
		
		$jsonID[$x] = $id;
		
		if($middlename != '' || $surname != ''){
			$jsonName[$x] = $firstname . " ";
		} else {
			$jsonName[$x] = $firstname;
		}
		
		if ($middlename == '' && $surname != ''){
			$jsonName[$x] .= $surname;
		} else if($middlename != '' && $surname !=''){
			$jsonName[$x] .= $middlename . " " . $surname;
		} else if ($middlename != '' && $surname == ''){
			$jsonName[$x] .= $middlename;
		}
		
		$x++;
	}
	
	echo json_encode(['status'=>'success','refs'=>$jsonID,'names'=>$jsonName]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

?>