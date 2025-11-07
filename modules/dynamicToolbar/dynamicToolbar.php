<?php // Script to get the details associated with each of the employees in the company
session_start();
require_once('../../includes/functions.php');
echo "<script>";
require_once('./dynamicToolbar.js');
echo "</script>";
?>

<script>
	
	// JavaScript to produce buttons
	// 3) Build toolbar only now
	const toolbar = document.createElement('div');
	toolbar.id = 'toolbar';
	
	const addRoleBtn = document.createElement('button');
	addRoleBtn.id = "addRole";
	addRoleBtn.classList.add("toolbarIcon");          // <- fix: use .add("...")
	addRoleBtn.title = "Add a new role";
	addRoleBtn.addEventListener("click", () => {
		if (typeof createAddRoleMenu === "function") createAddRoleMenu();
	});
	addRoleBtn.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
			<path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
		</svg>
		<span class="toolbarIcon">Add Role</span>
	`;
	
	const addAttachRolesBtn = document.createElement('button');
	addAttachRolesBtn.id = "attachRoles";
	addAttachRolesBtn.classList.add("toolbarIcon");          // <- fix: use .add("...")
	addAttachRolesBtn.title = "Attach employees to roles";
	addAttachRolesBtn.addEventListener("click", () => {
		if (typeof createAttachMenu === "function") createAttachMenu();
	});
	addAttachRolesBtn.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
		  <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
		</svg>
		<span id="attachRolesText" class="toolbarIcon">Attach</span>
	`;
	
	const addDepartmentBtn = document.createElement('button');
	addDepartmentBtn.id = "addDepartment";
	addDepartmentBtn.classList.add("toolbarIcon");          // <- fix: use .add("...")
	addDepartmentBtn.title = "Add Department";
	addDepartmentBtn.addEventListener("click", () => {
		if (typeof createDepartmentMenu === "function") createDepartmentMenu();
	});
	addDepartmentBtn.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
		  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
		</svg>

		<span id="addDepartmentText" class="toolbarIcon">Add Department</span>
	`;
	
	const addForecastBtn = document.createElement('button');
	addForecastBtn.id = "forecast";
	addForecastBtn.classList.add("toolbarIcon");          // <- fix: use .add("...")
	addForecastBtn.title = "Create Forecast";
	addForecastBtn.addEventListener("click", () => {
		if (typeof createForecastMenu === "function") createForecastMenu();
	});
	addForecastBtn.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
			<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
		</svg>
		<span id="preferencesText" class="toolbarIcon">Forecast</span>
	`;
	
	const addAllocatePayTypesBtn = document.createElement('button');
	addAllocatePayTypesBtn.id = "allocatePayTypes";
	addAllocatePayTypesBtn.classList.add("toolbarIcon");          // <- fix: use .add("...")
	addAllocatePayTypesBtn.title = "Allocate Pay Types";
	addAllocatePayTypesBtn.addEventListener("click", () => {
		if (typeof createPaytypeMenu === "function") createPaytypeMenu();
	});
	addAllocatePayTypesBtn.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
			 <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
		</svg>
		
		<span id="allocatePayTypesText" class="toolbarIcon">Pay Types</span>
	`;
	
	const addExportBtn = document.createElement('button');
	addExportBtn.id = "export";
	addExportBtn.classList.add("toolbarIcon");
	addExportBtn.title = "Export View to Excel";
	addExportBtn.addEventListener("click", () => {
		if(typeof createExportMenu === "function") createExportMenu();
	});
	addExportBtn.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
			<path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15m0-3-3-3m0 0-3 3m3-3V15" />
		</svg>
	`;
	
	const addPreferencesBtn = document.createElement('button');
	addPreferencesBtn.id = "preferences";
	addPreferencesBtn.classList.add("toolbarIcon");          // <- fix: use .add("...")
	addPreferencesBtn.title = "Open Preferences";
	addPreferencesBtn.addEventListener("click", () => {
		if (typeof createSettingsMenu === "function") createSettingsMenu();
	});
	addPreferencesBtn.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
			<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
		</svg>
	`;
	
	/* FEEDBACK BUTTON */
	// Allows the user to email feedback@accelulator.com
	const feedbackButton	=	document.createElement('button');
	feedbackButton.id 		=	'feedbackButton';
	feedbackButton.classList.add('toolbarIcon');
	feedbackButton.title	=	'Provide feedback';
	feedbackButton.addEventListener(
		"click",
		() => {
			provideFeedback();
		}
	);
	feedbackButton.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
		  <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
		</svg>

	`;
	
	/* REPORT BUG BUTTON */
	// Allows the user to report a bug
	const reportBugButton	=	document.createElement('button');
	reportBugButton.id		=	'reportBugButton';
	reportBugButton.classList.add('toolbarIcon');
	reportBugButton.title	=	'Report a bug';
	reportBugButton.addEventListener(
		"click",
		() => {
			reportBug();
		}
	);
	reportBugButton.innerHTML	=	`
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
		  <path stroke-linecap="round" stroke-linejoin="round" d="M12 12.75c1.148 0 2.278.08 3.383.237 1.037.146 1.866.966 1.866 2.013 0 3.728-2.35 6.75-5.25 6.75S6.75 18.728 6.75 15c0-1.046.83-1.867 1.866-2.013A24.204 24.204 0 0 1 12 12.75Zm0 0c2.883 0 5.647.508 8.207 1.44a23.91 23.91 0 0 1-1.152 6.06M12 12.75c-2.883 0-5.647.508-8.208 1.44.125 2.104.52 4.136 1.153 6.06M12 12.75a2.25 2.25 0 0 0 2.248-2.354M12 12.75a2.25 2.25 0 0 1-2.248-2.354M12 8.25c.995 0 1.971-.08 2.922-.236.403-.066.74-.358.795-.762a3.778 3.778 0 0 0-.399-2.25M12 8.25c-.995 0-1.97-.08-2.922-.236-.402-.066-.74-.358-.795-.762a3.734 3.734 0 0 1 .4-2.253M12 8.25a2.25 2.25 0 0 0-2.248 2.146M12 8.25a2.25 2.25 0 0 1 2.248 2.146M8.683 5a6.032 6.032 0 0 1-1.155-1.002c.07-.63.27-1.222.574-1.747m.581 2.749A3.75 3.75 0 0 1 15.318 5m0 0c.427-.283.815-.62 1.155-.999a4.471 4.471 0 0 0-.575-1.752M4.921 6a24.048 24.048 0 0 0-.392 3.314c1.668.546 3.416.914 5.223 1.082M19.08 6c.205 1.08.337 2.187.392 3.314a23.882 23.882 0 0 1-5.223 1.082" />
		</svg>

	`;
	
	const addDemoBtn = document.createElement('button');
	addDemoBtn.id = "demo";
	addDemoBtn.classList.add("toolbarIcon");          // <- fix: use .add("...")
	addDemoBtn.title = "Turn Demo Off";
	addDemoBtn.addEventListener("click", () => {
		if (typeof turnDemoOff === "function") turnDemoOff();
	});
	addDemoBtn.innerHTML = `
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 toolbarIcon" height="1.5em" width="1.5em">
			<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
		</svg>

		<span id="preferencesText" class="toolbarIcon">Demo: <strong>On</strong></span>
	`;
	
	// Check the user level and then populate the toolbar
	
	(async function buildToolbar(){
		const container = document.getElementById('dynamicToolbarView');
		if (!container) return;
		
		// 1) Get demo status first
		let demo = 0;
		try {
			const demoRes = await fetch("/scripts/getDemoStatus.php", {
				method: "POST",
				headers: { "X-CSRF-Token": window.csrfToken }
			})
			if (!demoRes.ok) throw new Error('Bad response');
			demoStatus = parseInt(await demoRes.json(), 10) || 0;
		} catch (e) {
			console.error("Error fetching access level:", e);
			return;
		}
		
		// 2) Get access level first
		let userAccessLevel = 0; // Changed this to -1 as 0 now is the free account
		try {
			const res = await fetch("/scripts/getUserAccessLevel.php", {
				method: "POST",
				headers: { "X-CSRF-Token": window.csrfToken }
			});
			if (!res.ok) throw new Error('Bad response');
			userAccessLevel = parseInt(await res.json(), 10) || 0; // Changed this to -1 as 0 now is the free account
		} catch (e) {
			console.error("Error fetching access level:", e);
			return; // bail
		}
		
		// 3)
		if(demoStatus == 1) {
			toolbar.appendChild(addPreferencesBtn);
			toolbar.appendChild(addDemoBtn);
			container.appendChild(toolbar);
		} else {
			switch (userAccessLevel) {
				case 0: // Free account
				
					toolbar.appendChild(addRoleBtn);
					toolbar.appendChild(addAttachRolesBtn);
					toolbar.appendChild(addDepartmentBtn);
					toolbar.appendChild(addForecastBtn);
					toolbar.appendChild(addAllocatePayTypesBtn);
					// toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					container.appendChild(toolbar);
					
					break;
				
				case 1: // Payroll - Do not add any tools, they should not be on this page
					
					break;
				
				case 2: // Administrator - Check what I want this person to be able to do
					
					toolbar.appendChild(addRoleBtn);
					toolbar.appendChild(addAttachRolesBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				case 3: // Auditor
					
					// toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				case 4: // Line Manager
					
					toolbar.appendChild(addRoleBtn);
					toolbar.appendChild(addAttachRolesBtn);
					// toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				case 5: // Analyst
					
					toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				case 6: // Cost Centre Manager
					
					toolbar.appendChild(addRoleBtn);
					toolbar.appendChild(addAttachRolesBtn);
					// toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				case 7: // Department Manager
					
					toolbar.appendChild(addRoleBtn);
					toolbar.appendChild(addAttachRolesBtn);
					// toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				case 8: // Functional Manager
					
					toolbar.appendChild(addRoleBtn);
					toolbar.appendChild(addAttachRolesBtn);
					// toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				case 9: // Full Access
					
					toolbar.appendChild(addRoleBtn);
					toolbar.appendChild(addAttachRolesBtn);
					toolbar.appendChild(addDepartmentBtn);
					toolbar.appendChild(addForecastBtn);
					toolbar.appendChild(addAllocatePayTypesBtn);
					// toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				case 10: // Superuser
					
					toolbar.appendChild(addRoleBtn);
					toolbar.appendChild(addAttachRolesBtn);
					toolbar.appendChild(addDepartmentBtn);
					toolbar.appendChild(addForecastBtn);
					toolbar.appendChild(addAllocatePayTypesBtn);
					toolbar.appendChild(addExportBtn);
					toolbar.appendChild(addPreferencesBtn);
					toolbar.appendChild(feedbackButton);
					toolbar.appendChild(reportBugButton);
					container.appendChild(toolbar);
					
					break;
					
				default:
					// Default setting - don't show anything
					
					
					break;
			}
		}
		
	})();
</script>


<?
echo '<script src="/modules/dynamicToolbar/dynamicToolbar.js"></script>';
?>