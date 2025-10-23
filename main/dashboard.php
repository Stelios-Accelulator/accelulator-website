<script>

// ---- DETERMINE IF THE USER IS IN DEMO MODE ----
async function demoStatus(){
	let demoMode = 0; // Set the demo mode off ahead of finding out if the user is in demo still
	try {
		const demoResult = await fetch("/scripts/getDemoStatus.php", {
			method: "POST",
			headers: { "X-CSRF-Token": window.csrfToken }
		});
		if (!demoResult.ok) throw new Error('Bad response');
		demoMode = parseInt(await demoResult.json(), 10) || 0;
	} catch (e) {
		console.error("Error fetching demo status:", e);
		return;
	}
	
	if (demoMode == 1){
		document.getElementById('actualUploadListItem')?.remove();
		document.getElementById('globalSettingsListItem')?.remove();
	}else{
		assessUserLevel();
	}
}

// ---- DETERMINE WHAT THE USER CAN SEE ----
async function assessUserLevel(){
	let userAccessLevel = 0; // Set the default access level to -1 as 0 is the free account
	try {
		const accessResult = await fetch("/scripts/getUserAccessLevel.php", {
			method: "POST",
			headers: { "X-CSRF-Token": window.csrfToken }
		});
		if (!accessResult.ok) throw new Error('Bad response');
		userAccessLevel = parseInt(await accessResult.json(), 10) || 0; // Changed this to -1 as 0 now is the free account
		
	} catch (e) {
		console.error("Error fetching access level:", e);
		return; // bail
	}
	
	switch (userAccessLevel) {
		// Alternatively, once everything else is fixed, we should build what we need rather than removing what we don't need.
		
		case 0:
			// Free account
			
			break;
		
		case 1:
			// View Only (Payroll) — remove StaffCast and Global Settings
			document.getElementById('staffCastApplicationListItem')?.remove();
			document.getElementById('globalSettingsListItem')?.remove();
			break;
	
		case 2:
			// Administration - remove payroll upload
			document.getElementById('actualUploadListItem')?.remove();
			break;
	
		case 3:
			// Restricted (Auditor) - remove payroll upload and global settings
			document.getElementById('actualUploadListItem')?.remove();
			document.getElementById('globalSettingsListItem')?.remove();
			document.getElementById('comingSoonListItem')?.remove();
			break;
		
		case 4:
			// Line Manager
			document.getElementById('actualUploadListItem')?.remove();
			document.getElementById('globalSettingsListItem')?.remove();
			document.getElementById('comingSoonListItem')?.remove();
			break;
		
		case 5:
			// Analyst
			document.getElementById('actualUploadListItem')?.remove();
			document.getElementById('globalSettingsListItem')?.remove();
			document.getElementById('comingSoonListItem')?.remove();
			break;
		
		case 6:
			// Cost Centre Manager
			document.getElementById('actualUploadListItem')?.remove();
			document.getElementById('globalSettingsListItem')?.remove();
			document.getElementById('comingSoonListItem')?.remove();
			break;
		
		case 7:
			// Department Manager
			document.getElementById('actualUploadListItem')?.remove();
			document.getElementById('globalSettingsListItem')?.remove();
			document.getElementById('comingSoonListItem')?.remove();
			break;
		
		case 8:
			// Functional Manager
			document.getElementById('actualUploadListItem')?.remove();
			document.getElementById('globalSettingsListItem')?.remove();
			document.getElementById('comingSoonListItem')?.remove();
			break;
		
		case 9:
			// Full Access - No restrictions
			
			break;
		
		case 10:
			// Superuser - No restrictions
			
			break;
		
		default:
			console.warn(`Unknown userAccessLevel: ${userAccessLevel}`);
	}
}

$(document).ready(function(){
	$("#staffCastA").click(function(){
		fadeLoadContent("contentView","/pages/staffCastApp.php",200,500);
	});
	$("#actualUploadLink").click(function(){
		fadeLoadContent("contentView","/pages/uploadFileForm.php",200,500);
	});
	$("#globalSettingsLink").click(function(){
		fadeLoadContent("contentView","/pages/companySettings.php",200,500);
	});

	// --- Welcome line from cookie ---
	function getCookie(name){
	  // escape regex specials in `name`
	  const escaped = name.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
	  const m = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
	  return m ? decodeURIComponent(m[1]) : null;
	}
	function toTitleCase(str){
		return str.replace(/\b\w/g, c => c.toUpperCase());
	}
	// Expecting cookie 'user' like 'stelios.miltiadou$40accelulator.com' or 'stelios.miltiadou@accelulator.com'
	const raw = getCookie('user');
	let name = 'there';
	if (raw){
		// normalise: split before '@' or '$40'
		let beforeAt = raw.split('@')[0].split('$40')[0];
		// turn dots/underscores into spaces
		beforeAt = beforeAt.replace(/[._]+/g,' ');
		name = toTitleCase(beforeAt);
	}
	document.getElementById('dashSub').textContent =`Welcome back ${name}, choose where you'd like to go`;
	
	demoStatus();
	
});
</script>

<div class="padded dashboard-wrap">
	<h1 class="dash-title">Dashboard</h1>
	<p class="dash-sub" id="dashSub">Welcome back, choose where you'd like to go</p>

	<ul class="dash-grid">
		<li id="staffCastApplicationListItem">
			<a id="staffCastA" href="#" class="dash-card">
				<span class="dash-card-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="24" height="24"><path d="M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4ZM8 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-2.7 0-8 1.34-8 4v2h10v-2c0-1.6.8-3 2-3.9A10.9 10.9 0 0 0 8 13Zm8 0c-2.67 0-8 2.34-8 5v2h16v-2c0-2.66-5.33-5-8-5Z"/></svg>
				</span>
				<span class="dash-card-body">
					<span class="dash-card-title">StaffCast</span>
					<span class="dash-card-desc">People cost forecasting & variance.</span>
				</span>
			</a>
		</li>

		<li id="actualUploadListItem">
			<a id="actualUploadLink" href="#" class="dash-card">
				<span class="dash-card-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 3v12m0-12 4 4m-4-4-4 4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
				</span>
				<span class="dash-card-body">
					<span class="dash-card-title">Upload Actuals</span>
					<span class="dash-card-desc">Import payroll from the template.</span>
				</span>
			</a>
		</li>

		<li id="globalSettingsListItem">
			<a id="globalSettingsLink" href="../pages/companySettings.php" class="dash-card">
				<span class="dash-card-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="24" height="24" fill="none"
						 stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
						 xmlns="http://www.w3.org/2000/svg">
					  <path d="M4 6h16M4 12h16M4 18h16"/>
					  <circle cx="8" cy="6" r="2"/>
					  <circle cx="16" cy="12" r="2"/>
					  <circle cx="6" cy="18" r="2"/>
					</svg>
				</span>
				<span class="dash-card-body">
					<span class="dash-card-title">Company Settings</span>
					<span class="dash-card-desc">Year-end, access, and options.</span>
				</span>
			</a>
		</li>

		<li id="comingSoonListItem">
			<a href="#" class="dash-card is-disabled" aria-disabled="true" tabindex="-1">
				<span class="dash-card-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 2 9 9l-7 3 7 3 3 7 3-7 7-3-7-3-3-7Z"/></svg>
				</span>
				<span class="dash-card-body">
					<span class="dash-card-title">Coming Soon</span>
					<span class="dash-card-desc">More tools on the way.</span>
				</span>
				<span class="dash-badge">SOON</span>
			</a>
		</li>
	</ul>
</div>