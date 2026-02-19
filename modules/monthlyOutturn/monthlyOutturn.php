<?php
// monthlyOutturn.php — lightweight shell; all data comes from getResourcesRoleFinancials.php

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../includes/functions.php';

// Auth
$user = checkUser();
if (!$user) {
	// checkUser() likely redirects already; this is just a safety net.
	exit;
}

// Company ref (not strictly needed for the new approach, but useful context)
$ref = getUsersCompanyId($user);

// Department selection (cookie)
$depSel = 0;
if (isset($_COOKIE['department']) && ctype_digit((string)$_COOKIE['department'])) {
	$depSel = (int)$_COOKIE['department'];
}

// CSRF token — try to expose whatever your validateCsrfToken() expects.
// If your codebase has a helper to ensure it exists, use it.
if (function_exists('generateCsrfToken')) {
	// Some codebases use this name
	@generateCsrfToken();
} elseif (function_exists('getCsrfToken')) {
	// Some codebases use this name
	@getCsrfToken();
}

$csrf = $_SESSION['csrf_token'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Monthly Outturn</title>

	<!-- If you have a shared header/css include, keep it here -->
</head>
<body>

<!-- Your existing page markup / containers should remain as-is.
		 If monthlyOutturn.js expects specific DOM elements, keep them here. -->

<script>
	// Globals expected by your legacy JS
	var employeeLibrary = [],
		lib_resources   = [],
		objects         = [],
		departments     = [],
		forecasts       = [],
		userOutturn     = [],
		niBands         = [],
		niBandLookup    = [],
		roles           = [];

	// Make CSRF available to JS (used by getResourcesRoleFinancials.php)
	window.csrfToken = <?php echo json_encode($csrf); ?>;

	// Department selected at load-time (from cookie)
	window.depSel = <?php echo (int)$depSel; ?>;
</script>

<!-- Your module JS (must define populateAllFromJson, applyRolesToEmployees, etc.) -->
<script src="/modules/monthlyOutturn/monthlyOutturn.js"></script>

<script>
(async function () {
	try {
		// Build POST payload for department filtering
		const payload = { department: window.depSel || 0 };

		const res = await fetch('/scripts/getResourcesRoleFinancials.php', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/json',
				'X-CSRF-Token': window.csrfToken || ''
			},
			body: JSON.stringify(payload)
		});

		const data = await res.json().catch(() => null);
		if (!res.ok || !data || data.status !== 'success') {
			console.error('[monthlyOutturn] dataset load failed:', { http: res.status, data });
			throw new Error(data?.message || ('Dataset load failed (HTTP ' + res.status + ')'));
		}

		// Hydrate your JS arrays (this is the single source of truth now)
		if (typeof populateAllFromJson !== 'function') {
			throw new Error('populateAllFromJson() not found (monthlyOutturn.js not loaded or missing function)');
		}
		populateAllFromJson(data);

		// Normal init flow
		if (typeof applyRolesToEmployees === 'function') applyRolesToEmployees();
		if (typeof applyDepartments === 'function') applyDepartments();

		if (typeof loadPayRises === 'function') await loadPayRises();
		if (typeof renderMonthlyOutturn === 'function') renderMonthlyOutturn();
		
		// Trigger forecast load on first render (same as user toggling the dropdown)
		const forecastSelect = document.getElementById('forecastSelect');
		if (forecastSelect) {
			forecastSelect.dispatchEvent(new Event('change', { bubbles: true }));
		}

	} catch (e) {
		console.error('[monthlyOutturn] init failed:', e);
		// Optional: if you have toast(), use it:
		// if (typeof toast === 'function') toast('Monthly outturn failed to load.', { type: 'error' });
	}
})();
</script>

</body>
</html>