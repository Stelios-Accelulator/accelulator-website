<script>
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
});
</script>

<div class="padded dashboard-wrap">
	<h1 class="dash-title">Dashboard</h1>
	<p class="dash-sub" id="dashSub">Welcome back, choose where you'd like to go</p>

	<ul class="dash-grid">
		<li>
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

		<li>
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

		<li>
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

		<li>
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