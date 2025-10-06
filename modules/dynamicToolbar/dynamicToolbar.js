// JavaScript code used specifically with the dynamic toolbar

function attachResourceToRole() {
	const selectedResourceEl = document.getElementById('resourceSelect');
	const selectedRoleEl    = document.getElementById('roleSelect');
	if (!selectedResourceEl || !selectedRoleEl) return;
	
	const payload = {
		resourceRef: selectedResourceEl.value,
		roleRef: selectedRoleEl.value
	};
	
	fetch('/scripts/attachRole.php', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-CSRF-Token': window.csrfToken   // make sure this is defined
		},
		body: JSON.stringify(payload)
	})
	.then(async (res) => {
		const text = await res.text();           // read raw for easier debugging
		try { return JSON.parse(text); }         // try to parse JSON
		catch {
			console.error('Non‑JSON reply from PHP:', text);
			throw new Error('Bad JSON from server');
		}
  	})
	.then((data) => {
		if (data.status === 'success') {
			const roleArrayRef = parseInt(selectedRoleEl.value, 10) - 1;
			if (Array.isArray(roles) && roles[roleArrayRef]) {
				roles[roleArrayRef].filledReference = selectedResourceEl.value;
			}
			allocateRoles();
			createTable();
			createSummaryTable();
		}
	})
	.catch(err => console.error('attachResourceToRole failed:', err));
}

function saveForecast(){
	
	let actualMonths = document.getElementById('actualMonths');
	let forecastMonths = document.getElementById('forecastMonths');
	let forecastName = document.getElementById('forecastName');
	let actualMonthsText = scrub(actualMonths.value);
	let a = actualMonthsText;
	let forecastMonthsText = scrub(forecastMonths.value);
	let f = forecastMonthsText;
	let forecastNameText = scrub(forecastName.value);
	
	if(actualMonthsText == null){ // If the user input is unacceptable, set it to 00
		actualMonthsText = "00";
	}
	
	if(forecastMonthsText == null){ // If the user input is unacceptable, set it to 00
		forecastMonthsText = "12";
	}
	
	if(actualMonthsText.length <= 1){ // If the digits on the actuals is less than 2, make it 2 digits
		actualMonthsText = "0" + actualMonthsText;
	}
	
	if(forecastMonthsText.length <= 1){ // If the digits on the actuals is less than 2, make it 2 digits
		forecastMonthsText = "0" + forecastMonthsText;
	}
	
	if(forecastNameText.length <= 0){ // If the Forecast Name is 
		forecastNameText = "Baseline";
	}
	
	let actual_forecast = actualMonthsText + "+" + forecastMonthsText;
	let forecast_name = forecastNameText;
	
	setCookie('a',a);
	setCookie('f',f);
	setCookie('actual_forecast',actual_forecast);
	setCookie('forecast_name',forecast_name);
	
	const safeResources = lib_resources.map(resource => {
		const actuals = {};
		const outturn = {};
	
		// Copy properties manually
		for (let key in resource.actuals) {
			actuals[key] = resource.actuals[key];
		}
		for (let key in resource.outturn) {
			outturn[key] = resource.outturn[key];
		}
	
		return {
			...resource,
			actuals,
			outturn
		};
	});
	
	const safeRoles = roles.map(role => {
		const actuals = {};
		const outturn = {};
	
		// Copy properties manually
		for (let key in role.actuals) {
			actuals[key] = role.actuals[key];
		}
		for (let key in role.outturn) {
			outturn[key] = role.outturn[key];
		}
	
		return {
			...role,
			actuals,
			outturn
		};
	});
	
	fetch("/scripts/saveForecast.php", {
		method: "POST",
		headers: {
			"Content-Type": "application/json",
			"X-CSRF-Token": window.csrfToken // <-- include the token
		},
		body: JSON.stringify({
			resources: safeResources,
			roles: safeRoles
		})
	})
	
	//.then(response => response.json())
	.then(response => response.text())
	.then(text => {
	  console.log("Raw response from PHP:", text);
	  try {
		const json = JSON.parse(text);
		console.log("Parsed JSON:", json);
	  } catch (err) {
		console.error("Failed to parse JSON:", err);
	  }
	})
	.then(data => {
		console.log("Response from PHP:", data);
	});
}

function correctForecastMonthsNumber(){
	const actualMonths = document.getElementById('actualMonths');
	const maximumValue = 12;
	let actualMonthsValue = Number(scrub(actualMonths.value));
	
	if(actualMonthsValue < 0 || actualMonthsValue == null){
		document.getElementById('actualMonths').value = 0;
		actualMonthsValue = 0
	}
	
	if(actualMonthsValue >11){
		document.getElementById('actualMonths').value = 11;
		actualMonthsValue = 11;
	}
	
	document.getElementById('forecastMonths').value = maximumValue - actualMonthsValue;
}

function correctActualMonthsNumber(){
	const forecastMonths = document.getElementById('forecastMonths');
	const maximumValue = 12;
	let forecastMonthsValue = Number(scrub(forecastMonths.value));
	
	if(forecastMonthsValue < 0 || forecastMonthsValue == null){
		document.getElementById('forecastMonths').value = 1;
		forecastMonthsValue = 1;
	}
	
	if(forecastMonthsValue > 12){
		document.getElementById('forecastMonths').value = 12;
		forecastMonthsValue = 12;
	}
	
	document.getElementById('actualMonths').value = maximumValue - forecastMonthsValue;
}

function actionSaveSettings(){
	let userActualMonths = document.getElementById('actualMonthsInput');
	let userOutturnMonths = document.getElementById('outturnMonthsInput');
	
	let cleanActualMonths = Number(scrub(userActualMonths.value));
	let cleanOutturnMonths = Number(scrub(userOutturnMonths.value));
	
	if (typeof cleanActualMonths === 'number' && !isNaN(cleanActualMonths)){
		Math.round(cleanActualMonths);
	} else {
		cleanActualMonths = 7;
	}
	
	if (typeof cleanOutturnMonths === 'number' && !isNaN(cleanOutturnMonths)){
		Math.round(cleanOutturnMonths);
	} else {
		cleanOutturnMonths = 6;
	}
	
	eraseCookie('aMonths');
	eraseCookie('oMonths');
	
	setCookie('aMonths', cleanActualMonths);
	setCookie('oMonths', cleanOutturnMonths);
	
	$("#empty").load("/scripts/updateSettings.php")
	
	createTable();
	
}

function createAddRoleMenu(){
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null){
		destroyMenu('menuContainer');
	};
	
	// CREATE THE MENU
	roleMenu = document.createElement("div");
	roleMenu.id = "menuContainer";
	
	departmentSelector = "";
	
	departments.forEach(department =>{
		departmentSelector = departmentSelector + "<option value='" + department.ref + "'>" + department.department + "</option>";
	});
	
	document.getElementById("contentView").appendChild(roleMenu);
	roleMenu.innerHTML = `
	<div class='menuHeader'><strong>Add Role</strong><button onClick='destroyMenu("menuContainer");'><strong>X</strong></button></div>
	<div class='menuRow'><label>Type:</label>
		<select name='type' id='contractType' class='menuInputField'>
			<option value='1'>Permanent</option>
			<option value='2'>Fixed Term</option>
			<option value='3'>Contractor</option>
			<option value='4'>Temporary</option>
		</select>
	</div>
	<div class='menuRow'><label>Job Title:</label><span class='spacer'></span><input type='text' value ='' id='newRole' class='menuInputField'></div>
	<div class='menuRow'><label>Department:</label><span class='spacer'></span><select name='department' id='department' class='menuInputField'>${departmentSelector}</select></div>
	<div class='menuRow'><label>FTE:</label><span class='spacer'></span><input type='text' value ='' id='fte' class='menuInputField'></div>
	<div class='menuRow'><label>Salary:</label><span class='spacer'></span><input type='text' value ='' id='salary' class='menuInputField'></div>
	<div class='menuRow'><label>Start Date:</label><span class='spacer'></span><input type='date' value ='' id='startDate' class='menuInputField'></div>
	<div class='menuRow'><label>End Date:</label><span class='spacer'></span><input type='date' value ='' id='endDate' class='menuInputField'></div>
	<div class='buttonGroup'>
		<button onclick='actionAddRole();destroyMenu("menuContainer");'>Add Role</button>
	</div>
	`;
	document.getElementById('newRole').focus();
	
	makeDraggable(roleMenu);
	
}

function createAttachMenu() {
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null) {
		destroyMenu('menuContainer');
	}

	// Create new menu container
	let attachMenu = document.createElement('div');
	attachMenu.id = 'menuContainer';

	// Append to DOM
	document.getElementById("contentView").appendChild(attachMenu);

	// Build inner HTML structure
	attachMenu.innerHTML = `
		<div class='menuHeader'><strong>Attach Employee</strong><button onClick='destroyMenu("menuContainer");'><strong>X</strong></button></div>
		<div class='menuRow'><label>Resource</label><span class='spacer'></span><select name='resourceSelect' id='resourceSelect' class='menuInputField'></select></div>
		<div class='menuRow'><label>Role</label><span class='spacer'></span><select name='roleSelect' id='roleSelect' class='menuInputField'></select></div>
		<div class='buttonGroup'>
			<button onclick="attachResourceToRole(),destroyMenu('menuContainer'),createTable();">Attach</button>
		</div>
	`;

	// Populate resourceSelect with lib_resources
	const resourceSelect = document.getElementById('resourceSelect');
	lib_resources.forEach(resource => {
		if(resource.jobTitle == 'Unallocated'){
			const option = document.createElement('option');
			option.value = resource.ref;
			option.textContent = `${resource.firstname} ${resource.surname}`;
			resourceSelect.appendChild(option);
		}
	});

	// Populate roleSelect with roles
	const roleSelect = document.getElementById('roleSelect');
	roles.forEach(role => {
		if(role.filledReference == 0) {
			const option = document.createElement('option');
			option.value = role.ref;
			option.textContent = `${role.jobTitle} (${role.departmentName || 'Unallocated'})`;
			roleSelect.appendChild(option);
		}
	});
	
	makeDraggable(attachMenu);
	
}

function createDepartmentMenu(){ // Creates and shows the Department menu
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null) {
		destroyMenu('menuContainer');
	}
	
	departmentMenu = document.createElement("div");
	departmentMenu.id = "menuContainer";
	
	document.getElementById("contentView").appendChild(departmentMenu);
	
	departmentMenu.innerHTML = `
	<div id='draggableEmployeeMenuHeader'><strong>Department Menu</strong> <button onClick='destroyMenu("menuContainer");'><strong>X</strong></button></div>
	<div><input id='newDepartmentName' type='text' placeholder='New Department'></div>
	<div><button onclick='actionAddDepartment();'>Add</button></div>
	`;
	
	makeDraggable(departmentMenu);
	
}

function createForecastMenu() {
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null) {
		destroyMenu('menuContainer');
	}

	// Create new menu container
	let forecastMenu = document.createElement('div');
	forecastMenu.id = 'menuContainer';

	// Append to DOM
	document.getElementById("contentView").appendChild(forecastMenu);

	// Build inner HTML structure
	forecastMenu.innerHTML = `
		<div class='menuHeader'><strong>Create Forecast</strong><button onClick='destroyMenu("menuContainer");'><strong>X</strong></button></div>
		<div class='menuRow'><label>Name</label><span class='spacer'></span><input type="text" id="forecastName" class="menuInputField" placeholder="Baseline"></div>
		<div class='menuRow'><label>Actual Months</label><span class='spacer'></span><input type="Number" id="actualMonths" class="menuInputField" value="3" max="11" min="0" onchange="correctForecastMonthsNumber();"></div>
		<div class='menuRow'><label>Forecast Months</label><span class='spacer'></span><input type="Number" id="forecastMonths" class="menuInputField" value="9" max="12" min="1" onchange="correctActualMonthsNumber();"></div>
		<div class='buttonGroup'>
			<button onclick="saveForecast(), destroyMenu('menuContainer');">Save</button><span class='spacer'></span><button onclick="destroyMenu('menuContainer');">Cancel</button>
		</div>
	`;
	
	makeDraggable(forecastMenu);
	
}

function createPaytypeMenu(){
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null){
		destroyMenu('menuContainer');
	};
	
	let contentVew = document.getElementById("contentView");
	let htmlString = '';
	let groupName = '';
	let groupValue = '';
	let a = 0;
	let typeRef = 0;
	
	// CREATE THE MENU
	paytypeMenu = document.createElement("div");
	paytypeMenu.id = "menuContainer";
	
	types.forEach(type => {
		typeRef = Number(type.ref) -1;
		htmlString += `<div class='menuRow'><label for='payTypeSelect` + a + `' id='payTypeSelectLabel_` + a + `'>` + type.type + `</label><span class='spacer'></span><select name='payTypeSelect` + a + `' id='payTypeSelect_` + a +`' class='menuInputField' onchange='updatePayTypeGroup(` + typeRef + `)'>`;
		payTypeGroups.forEach(payTypeGroup =>{
			if(type.payTypeGroupRef == payTypeGroup.ref){
				htmlString += `<option id='payTypeChanged_` + payTypeGroup.ref + `' value='` + payTypeGroup.ref + `' class='menuInputField settingsField' selected='true'>` + payTypeGroup.type +`</option>`;
			} else {
				htmlString += `<option id='payTypeChanged_` + payTypeGroup.ref + `' value='` + payTypeGroup.ref + `' class='menuInputField settingsField'>` + payTypeGroup.type +`</option>`;
			}
		})
		htmlString += `
			</select>
		</div>
		`;
		a++;
	})
	
	
	contentView.appendChild(paytypeMenu);
	paytypeMenu.innerHTML = `<div class='menuHeader'><strong>Allocate Pay Type</strong><button onClick='destroyMenu("menuContainer");'>X</button></div>` + htmlString;
		
	makeDraggable(paytypeMenu);
	
}

function updatePayTypeGroup(typesArrayNumber){
	let typeRef = types[typesArrayNumber].ref;
	let typeGroupRef = document.getElementById("payTypeSelect_" + typesArrayNumber).value;
	
	types[typesArrayNumber].payTypeGroupRef = typeGroupRef;
	fetch("/scripts/updatePayTypeGroups.php", {
		method: "POST",
		headers: {
			"Content-Type": "application/json",
			"X-CSRF-Token": window.csrfToken // <-- include the token
		},
		body: JSON.stringify({
			typeRef: typeRef,
			typeGroupRef: typeGroupRef
		})
	})
	
}

function createSettingsMenu(){
	
	// Destroy existing menu if found
	let menuExists = document.getElementById('menuContainer');
	if (menuExists != null){
		destroyMenu('menuContainer');
	};
	
	
	let aMonths = getCookie('aMonths');
	let oMonths = getCookie('oMonths');
	let contentVew = document.getElementById("contentView");
	
	if(aMonths == null){
		aMonths = 7;
	}
	
	if(oMonths == null){
		oMonths = 6;
	}
	
	// CREATE THE MENU
	settingsMenu = document.createElement("div");
	settingsMenu.id = "menuContainer";
	
	
	contentView.appendChild(settingsMenu);
	settingsMenu.innerHTML = `
		<div class='menuHeader'><strong>Settings</strong><button onClick='destroyMenu("menuContainer");'>X</button></div>
		<div class='menuRow'><label>Actual months</label><span class='spacer'></span><input id="actualMonthsInput" type='number' value='${aMonths}' class='menuInputField settingsField'></div>
		<div class='menuRow'><label>Outturn months</label><span class='spacer'></span><input id="outturnMonthsInput" type='number' value='${oMonths}' class='menuInputField settingsField'></div>
		<div class='buttonGroup'>
			<button onclick="actionSaveSettings();">Update</button>
		</div>	
		`;
		
	makeDraggable(settingsMenu);
	
}

async function turnDemoOff(){
	try {
		const res = await fetch("/scripts/turnDemoOff.php", {
			method: "POST",
			headers: {"X-CSRF-Token": window.csrfToken }
		})
		if (!res.ok) throw new Error('Bad response');
		fadeLoadContent("contentView","/pages/staffCastApp.php",200,500);
	} catch (e) {
		console.error("Error deactivating demo");
		return;
	}
}