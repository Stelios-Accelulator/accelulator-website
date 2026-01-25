<style>
	#advancedUploadContainer{
		padding-top: 1em;
		padding-bottom: 1em;
	} /* keep existing spacing */

	#content {
		max-width: 100%;
		margin: 0 auto;
	} /* overall layout for Upload vs Contractors */

	.upload-layout{
		display: flex;
		flex-direction: column;
		gap: 1.5rem;
		margin-top: 0.75rem;
	}

	@media (min-width: 900px){
		.upload-layout{
			flex-direction: row;
			align-items: flex-start;
		}
		.upload-panel,
		.contractor-panel{
			flex: 1 1 0;
		}
	}

	
	.upload-panel,
	.contractor-panel{
		background: var(--panel);
		border-radius: var(--radius);
		box-shadow: var(--shadow-2);
		padding: 1.25rem 1.5rem;
		border: 1px solid #e5e7eb;
	} /* card styling to match the rest of the app */

	.upload-panel h2,
	.contractor-panel h2{
		margin-top: 0;
		margin-bottom: 0.75rem;
	}

	
	#drop-area{
		border-radius: 10px;
		border: 1px dashed #cbd5e1;
		background: #fff;
		box-shadow: 0 1px 3px rgba(15,23,42,0.06);
		padding: 1.5rem;
		margin-bottom: 1rem;
		cursor: pointer;
		transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
	}/* make the drag/drop area feel more like a first-class widget */
	
	#drop-area p{
		margin: 0;
		font-size: 0.98rem;
		color: var(--muted-ink);
	}
	#drop-area.highlight{
		border-color: var(--brand-ink);
		background-color: #f0f8ff;
		box-shadow: 0 3px 8px rgba(7,164,188,0.18);
	}

	#fileName{
		margin-top: 0.5rem;
		font-weight: 600;
		font-size: 0.95rem;
	}

	.menuRow{
		margin-top: 0.35rem;
	} /* nicer radio layout */

	.menuRow input[type="radio"]{
		margin-right: 0.5rem;
	}

	
	#content .menuRow select,
	#content .menuRow input[type="date"],
	#content .menuRow input[type="number"],
	#content .menuRow input[type="text"]{
		border: 1px solid #cbd5e1;
		border-radius: 8px;
		padding: 0.4rem 0.5rem;
		font: inherit;
		background: #fff;
		box-shadow: 0 0 0 rgba(0,0,0,0);
		transition: border-color .15s ease, box-shadow .15s ease;
	} /* inputs in this page: tidy, rounded, consistent */

	#content .menuRow select:focus,
	#content .menuRow input[type="date"]:focus,
	#content .menuRow input[type="number"]:focus,
	#content .menuRow input[type="text"]:focus{
		outline: none;
		border-color: var(--brand-ink);
		box-shadow: 0 0 0 3px rgba(7,164,188,0.18);
	}

	
	.contractor-panel .menuRow{
		gap: 0.75rem;
		flex-wrap: wrap;
	} /* keep contractor inputs on one line but with breathing room */

	@media (min-width: 900px){
		.contractor-panel .menuRow{
			flex-wrap: nowrap;
		}
	}

	
	#content button{
		border-radius: 999px;
		border: 0;
		padding: 0.45rem 0.9rem;
		font: inherit;
		font-weight: 600;
		cursor: pointer;
		background: var(--brand);
		color: #fff;
		box-shadow: 0 1px 3px rgba(0,0,0,0.16);
		transition:
			background-color .15s ease,
			box-shadow .15s ease,
			transform .06s ease,
			filter .15s ease;
	} /* button styling – borrow from auth/buttons style but scoped here */

	#content button:hover{
		filter: brightness(1.15);
		box-shadow: 0 3px 8px rgba(0,0,0,0.18);
	}

	#content button:active{
		transform: translateY(1px);
		box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
	}

	#content button:focus-visible{
		outline: 3px solid var(--brand-ink);
		outline-offset: 2px;
	}

	
	#advUploadBtn{
		background: #fff;
		color: var(--brand);
		border: 1px solid #cbd5e1;
		box-shadow: 0 1px 2px rgba(0,0,0,0.06);
	} /* make the Upload button look secondary compared to the red CTA */

	
	#submitPaymentButton{
		min-width: 110px;
		text-align: center;
	} /* contractor submit button is your primary action */

	
	p.small{
		font-size: 0.9rem;
		color: var(--muted-ink);
		margin-bottom: 0.25rem;
	} /* small text tweaks */

	#result{
		margin-top: 0.75rem;
		font-size: 0.95rem;
	}
	
	.browse-link{
		cursor: pointer;
		text-decoration: underline;
	} // Tiny bit of CSS to make it look like a link
	
</style>

<div id="content">
	<div class="padded">
		<h1>Import Actuals</h1>
		<div class="upload-layout">
			<section class="upload-panel">
				<h2>Upload Payroll File</h2>
				
				<div id="drop-area">
				<p>
					Drag & drop your payroll file (.xlsx) here or
					<label for="fileInput" class="browse-link">browse</label>
				</p>
				<input type="file" id="fileInput" name="spreadsheet" accept=".xlsx,.xls,.csv" required hidden />
				</div>
				
				<div class="menuRow">
					<p class="small"><strong>How are your pay elements laid out?</strong></p>
				</div>
				
				<div class="menuRow">
					
					<label>
						<input type="radio" name="layout_mode" value="horizontal" checked>
						One row per employee, with separate columns for each pay element (e.g. Base Pay, Overtime, Bonus)
					</label><br>
				</div>
				
				<div class="menuRow">
					
					<label>
						<input type="radio" name="layout_mode" value="vertical" />
						One row per pay element, with a "Type" column and a single "Amount/GBP" column
					</label>
				</div>
				
				<div class="menuRow" id="advancedUploadContainer">
					<button id="advUploadBtn">Upload</button>
				</div>
				
				<div id="fileName" class="menuRow"></div>
				<div id="result"></div>
			</section>
		
			<section id="contractorPanel" class='contractor-panel'>
				<h2>Contractors</h2>
				
				<div class="menuRow">
					<h3>Enter a Contract Payment</h3>
				</div>
				
				<table>
					<thead>
						<tr class="menuRow">
							<th>Contractor</th>
							<th>Payment Date</th>
							<th>Amount Paid</th>
						</tr>
					</thead>
					<tbody>
						<tr class="menuRow">
							<td>
								<select name="contractorSelect" id="contractorSelect">
									<option value="-1" selected="true">New Contractor</option>
								</select>
							</td>
							<td>
								<input id="contractorPaymentDate" name="contractorPaymentDate" type="date">
							</td>
							<td>
								<input id="contractorPaymentValue" name="contractorPaymentValue" type="number" min=0 step=100>
							</td>
						</tr>
					</tbody>
				</table>
				<!--
				<div class="menuRow">
					<select name="contractorSelect" id="contractorSelect">
						<option value="-1" selected="true">New Contractor</option>
					</select>
					<input id="contractorPaymentDate" name="contractorPaymentDate" type="date">
					<input id="contractorPaymentValue" name="contractorPaymentValue" type="number" min=0 step=100>
				</div>
				-->
				<div class="menuRow">
					<button id="submitPaymentButton" name="submitPaymentButton" hidden="true">Create</button>
				</div>
			</section>
		</div>
		
		<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
		<script>
		  const dropArea = document.getElementById('drop-area');
		  const fileInput = document.getElementById('fileInput');
		  const uploadBtn = document.getElementById('uploadBtn');
		  const advUploadBtn = document.getElementById('advUploadBtn');
		  const fileNameDisplay = document.getElementById('fileName');
		  let selectedFile = null;
		
		  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
				dropArea.addEventListener(eventName, e => e.preventDefault());
				dropArea.addEventListener(eventName, e => e.stopPropagation());
		  }); // Prevent default drag behaviours
		
		  
		  ['dragenter', 'dragover'].forEach(eventName => {
				dropArea.addEventListener(eventName, () => dropArea.classList.add('highlight'));
		  }); // Highlight drop area
			
		  ['dragleave', 'drop'].forEach(eventName => {
				dropArea.addEventListener(eventName, () => dropArea.classList.remove('highlight'));
		  });
		
		  dropArea.addEventListener('drop', e => {
				selectedFile = e.dataTransfer.files[0];
				fileNameDisplay.textContent = selectedFile.name;
		  }); // Handle dropped files
		
		  fileInput.addEventListener('change', e => {
				selectedFile = e.target.files[0];
				fileNameDisplay.textContent = selectedFile.name;
		  }); // Handle manual selection
						
			advUploadBtn.addEventListener('click', () => {
				if (!selectedFile) {
				fileNameDisplay.textContent = "Please select a file.";
				return;
				}
			
				const formData = new FormData();
				formData.append('spreadsheet', selectedFile);
			
				// Work out which layout they chose
				const selectedLayout = document.querySelector('input[name="layout_mode"]:checked');
				const mode = selectedLayout ? selectedLayout.value : 'horizontal';
			
				// Pick the correct PHP script
				const url = (mode === 'vertical')
				? '/scripts/verticalExcelUpload.php'
				: '/scripts/excelAdvancedUpload.php';
			
				$.ajax({
				url: url,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function (response) {
					$("#result").html(response);
				},
				error: function () {
					$("#result").text("There was an error processing the file.");
				}
				});
			}); // Handle advanced upload (horizontal vs vertical)
			
			async function fetchContractorNames() {
				// Pulls through the names of the Contractors present in the user's Resources table
				try {
					const result = await fetch("/scripts/getContractorNames.php", {
						method: "POST",
						headers: {
							"Content-Type": "application/json",
							"X-CSRF-Token": window.csrfToken
						},
						body: JSON.stringify({})
					});
			
					const data = await result.json();
					// Expecting: { status: "success" | "empty", refs: [...], names: [...] }
			
					if (!data || (data.status !== "success" && data.status !== "empty")) {
						console.error("Contractor fetch returned unexpected payload:", data);
						return;
					}
			
					const refs  = Array.isArray(data.refs)  ? data.refs  : [];
					const names = Array.isArray(data.names) ? data.names : [];
					const contractorSelect = document.getElementById("contractorSelect");
			
					// Reset the select back to the default option
					contractorSelect.innerHTML = "";
					const defaultOpt = document.createElement("option");
					defaultOpt.value = "-1";
					defaultOpt.selected = true;
					defaultOpt.textContent = "New Contractor";
					contractorSelect.appendChild(defaultOpt);
			
					// If there are no contractors, we’re done (this is your “fail gracefully”)
					if (refs.length === 0) {
						return;
					}
			
					// Build options from parallel arrays
					refs.forEach((ref, index) => {
						const option = document.createElement("option");
						option.value = ref;
						option.textContent = names[index] || `Contractor ${index + 1}`;
						contractorSelect.appendChild(option);
					});
			
				} catch (err) {
					console.error("Error fetching contractor names:", err);
				}
			}
			
			fetchContractorNames();
			
			const contractorButton			= document.getElementById('submitPaymentButton');
			const contractorSelect			= document.getElementById('contractorSelect');
			const contractorPaymentDate		= document.getElementById('contractorPaymentDate');
			const contractorPaymentValue	= document.getElementById('contractorPaymentValue');
			
			contractorPaymentDate.value		= new Date().toISOString().slice(0, 10);
			
			// start hidden
			contractorButton.hidden = true;
			
			function changeContractorButton(button, select) {
				if (select.value === "-1") {
					button.textContent = "Create";
				} else {
					button.textContent = "Submit";
				}
			}
			
			function updateContractorButtonVisibility(button, dateInput, valueInput) {
				const dateVal  = dateInput.value;
				const valueVal = valueInput.value;
			
				if (dateVal !== "" && valueVal !== "") {
					button.hidden = false;
				} else {
					button.hidden = true;
				}
			}
			
			/* -------------- //
			// WIRE UP EVENTS //
			// -------------- */
			
			contractorSelect.addEventListener("change", () => {
				changeContractorButton(contractorButton, contractorSelect);
				updateContractorButtonVisibility(contractorButton, contractorPaymentDate, contractorPaymentValue);
			});
			
			contractorPaymentDate.addEventListener("change", () => {
				updateContractorButtonVisibility(contractorButton, contractorPaymentDate, contractorPaymentValue);
			});
			
			contractorPaymentValue.addEventListener("change", () => {
				updateContractorButtonVisibility(contractorButton, contractorPaymentDate, contractorPaymentValue);
			});
			
			contractorButton.addEventListener("click", async () => {
				if (contractorSelect.value === "-1") {
					enterContractorDetails(contractorButton, contractorSelect, contractorPaymentDate, contractorPaymentValue);
				} else {
					await submitContractorPayment(contractorSelect, contractorPaymentDate, contractorPaymentValue);
				}
			});
			
			async function fetchDepartments(departmentSelect){
				try{
					const result = await fetch("/scripts/fetchDepartments.php", {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-CSRF-Token': window.csrfToken
						},
						body: JSON.stringify({})
					});
					const data = await result.json();
					
					if(!data || (data.status !== "success" && data.status !== "empty")){
						console.error("Department fetch returned unexpected payload:", data);
						return;
					}
					
					const rows = Array.isArray(data.rows) ? data.rows : [];
					
					departmentSelect.innerHTML = "";
					dSelect = document.getElementById('departmentSelect');
					
					
					if (rows.length === 0){
						const defaultOpt = document.createElement("option");
						defaultOpt.value = 0;
						defaultOpt.selected = true;
						defaultOpt.textContent = 'No departments created';
						document.getElementById('departmentSelect').appendChild(defaultOpt);
					} else {
						rows.forEach((row) => {
							const opt = document.createElement("option");
							opt.value = row.REF;
							opt.textContent = row.DEPARTMENT;
							departmentSelect.appendChild(opt);
						});
					}
				}catch{
					
				}
			}
			
			
			function enterContractorDetails(contractorButton, contractorSelect, contractorPaymentDate, contractorPaymentValue){
				contractorButton.hidden	= true;
				const contractorPanel	= document.getElementById('contractorPanel')
				const newDetailsDiv		= document.createElement('div');
				
				newDetailsDiv.id = 'newDetailsDiv';
				newDetailsDiv.classList.add('menurow');
				contractorPanel.appendChild(newDetailsDiv);
				
				const headerRow = document.createElement('div');
				headerRow.classList.add('menuRow');
				newDetailsDiv.appendChild(headerRow);
				
				const header		= document.createElement('h3');
				header.textContent	= 'Enter the details of the new contractor';
				headerRow.appendChild(header);
				
				const closeButton		= document.createElement('button');
				closeButton.id			= 'closeButton';
				closeButton.textContent	= 'Close';
				closeButton.addEventListener("click", () => {
					closeContractorDetails(contractorButton);
				})
				headerRow.appendChild(closeButton);
				
				let firstNameRow = document.createElement('div');
				firstNameRow.classList.add('menuRow');
				newDetailsDiv.appendChild(firstNameRow);
				
				let firstNameLabel			= document.createElement('label');
				firstNameLabel.for			= 'firstNameInput';
				firstNameLabel.textContent	= 'Firstname (required)';
				firstNameRow.appendChild(firstNameLabel);
				
				let firstNameInput			= document.createElement('input');
				firstNameInput.type			= 'text';
				firstNameInput.id			= 'firstNameInput';
				firstNameInput.name			= 'firstNameInput';
				firstNameInput.placeholder	= '[Required]';
				firstNameRow.appendChild(firstNameInput);
				firstNameRow.addEventListener('change',() => {
					createContractorSaveButton(newDetailsDiv);
				});
				
				let middleNameRow = document.createElement('div');
				middleNameRow.classList.add('menuRow');
				newDetailsDiv.appendChild(middleNameRow);
				
				let middleNameLabel			= document.createElement('label');
				middleNameLabel.for			= 'middleNameInput';
				middleNameLabel.textContent	= 'Middle Name (optional)';
				middleNameRow.appendChild(middleNameLabel);
				
				let middleNameInput			= document.createElement('input');
				middleNameInput.type		= 'text';
				middleNameInput.id			= 'middleNameInput';
				middleNameInput.name		= 'middleNameInput';
				middleNameInput.placeholder	= '[Optional]';
				middleNameRow.appendChild(middleNameInput);
				middleNameInput.addEventListener('change',() => {
					createContractorSaveButton(newDetailsDiv);
				});
				
				let surnameRow = document.createElement('div');
				surnameRow.classList.add('menuRow');
				newDetailsDiv.appendChild(surnameRow);
				
				let surnameLabel			= document.createElement('label');
				surnameLabel.for			= 'surnameInput';
				surnameLabel.textContent	= 'Surname (optional)';
				surnameRow.appendChild(surnameLabel);
				
				let surnameInput			= document.createElement('input');
				surnameInput.type			= 'text';
				surnameInput.id				= 'surnameInput';
				surnameInput.name			= 'surnameInput';
				surnameInput.placeholder	= '[Optional]';
				surnameRow.appendChild(surnameInput);
				surnameInput.addEventListener('change',() => {
					createContractorSaveButton(newDetailsDiv);
				});
				
				let departmentRow = document.createElement('div');
				departmentRow.classList.add('menuRow');
				newDetailsDiv.appendChild(departmentRow);
				
				let departmentLabel			= document.createElement('label');
				departmentLabel.for			= 'departmentSelect';
				departmentLabel.textContent	= 'Department';
				departmentRow.appendChild(departmentLabel);
				
				let departmentSelect		= document.createElement('select');
				departmentSelect.name		= 'departmentSelect';
				departmentSelect.id			= 'departmentSelect';
				departmentRow.appendChild(departmentSelect);
				fetchDepartments(departmentSelect);
				departmentSelect.addEventListener('change',() => {
					createContractorSaveButton(newDetailsDiv);
				});
				
				let startDateRow = document.createElement('div');
				startDateRow.classList.add('menuRow');
				newDetailsDiv.appendChild(startDateRow);
				
				let startDateLabel			= document.createElement('label');
				startDateLabel.for			= 'startDateInput';
				startDateLabel.textContent	= 'Start Date';
				startDateRow.appendChild(startDateLabel);
				
				let startDateInput			= document.createElement('input');
				startDateInput.type			= 'date';
				startDateInput.value		= new Date().toISOString().slice(0, 10);
				startDateInput.name			= 'startDateInput';
				startDateInput.id			= 'startDateInput';
				startDateRow.appendChild(startDateInput);
				startDateInput.addEventListener('change',() => {
					createContractorSaveButton(newDetailsDiv);
				});
				
				let dailyRateRow = document.createElement('div');
				dailyRateRow.classList.add('menuRow');
				newDetailsDiv.appendChild(dailyRateRow);
				
				let dailyRateLabel			= document.createElement('label');
				dailyRateLabel.for			= 'dailyRateInput';
				dailyRateLabel.textContent	= 'Daily Rate (GBP)';
				dailyRateRow.appendChild(dailyRateLabel);
				
				let dailyRateInput			= document.createElement('input');
				dailyRateInput.type			= 'number';
				dailyRateInput.name			= 'dailyRateInput';
				dailyRateInput.id			= 'dailyRateInput';
				dailyRateInput.placeholder	= 250.00;
				dailyRateInput.min			= 0.00;
				dailyRateInput.step			= 50;
				dailyRateInput.max			= 999999.99;
				dailyRateRow.appendChild(dailyRateInput);
				dailyRateInput.addEventListener('change',() => {
					createContractorSaveButton(newDetailsDiv);
				});
				
				let fteRow = document.createElement('div');
				fteRow.classList.add('menuRow');
				newDetailsDiv.appendChild(fteRow);
				
				let fteLabel			= document.createElement('label');
				fteLabel.for			= 'fteInput';
				fteLabel.textContent	= 'Full Time Equivalent (FTE)';
				fteRow.appendChild(fteLabel);
				
				let fteInput			= document.createElement('input');
				fteInput.type			= 'number';
				fteInput.name			= 'fteInput';
				fteInput.id				= 'fteInput';
				fteInput.placeholder	= 1.0;
				fteInput.step			= 0.1;
				fteInput.min			= 0.1;
				fteInput.max			= 1.0;
				fteRow.appendChild(fteInput);
				fteInput.addEventListener('change',() => {
					createContractorSaveButton(newDetailsDiv);
				});
				
			}
			
			function createContractorSaveButton(newDetailsDiv){
				const firstName = document.getElementById('firstNameInput');
				const middleName = document.getElementById('middleNameInput');
				const surname = document.getElementById('surnameInput');
				const department = document.getElementById('departmentSelect');
				const startDate = document.getElementById('startDateInput');
				const dailyRate = document.getElementById('dailyRateInput');
				const fte = document.getElementById('fteInput');
				const paymentDate = document.getElementById('contractorPaymentDate');
				const paymentValue = document.getElementById('contractorPaymentValue');
				
				
				button = document.getElementById('saveContractorButton');
				
				if(button != null){
					button.parentNode.removeChild(button);
				}
				
				if(firstName.value != '' && department.value != '' && startDate.value != '' && dailyRate.value != '' && fte.value != ''){
					let saveContractorButton = document.createElement('button');
					saveContractorButton.textContent = 'Save';
					saveContractorButton.id = 'saveContractorButton';
					saveContractorButton.addEventListener('click',()=>{
						saveContractor(paymentDate,paymentValue);
					});
					newDetailsDiv.appendChild(saveContractorButton);
				}
			}
			
			async function saveContractor(contractorPaymentDate,contractorPaymentValue){
				const firstName = scrub(document.getElementById('firstNameInput').value);
				const middleName = scrub(document.getElementById('middleNameInput').value);
				const surname = scrub(document.getElementById('surnameInput').value);
				const department = scrub(document.getElementById('departmentSelect').value);
				const departmentName = scrub(document.getElementById('departmentSelect').selectedOptions[0]?.text || '');
				const startDate = scrub(document.getElementById('startDateInput').value);
				const dailyRate = Number(scrub(document.getElementById('dailyRateInput').value));
				const fte = Number(scrub(document.getElementById('fteInput').value));
				const salary = dailyRate * 5 * 48 * fte;
				const thisContainer = document.getElementById('newDetailsDiv');
				const paymentDate = scrub(contractorPaymentDate.value);
				const paymentValue = Number(scrub(contractorPaymentValue.value));
				// dd/mm/yyyy (UK)
				const formattedPayDate = paymentDate
				  ? new Intl.DateTimeFormat('en-GB').format(new Date(paymentDate + 'T00:00:00'))
				  : '';
				
				// £5,000 (only show pence if they exist)
				const isWhole = Number.isFinite(paymentValue) && Math.abs(paymentValue % 1) < 1e-9;
				
				const formattedPayValue = Number.isFinite(paymentValue)
				  ? new Intl.NumberFormat('en-GB', {
					  style: 'currency',
					  currency: 'GBP',
					  minimumFractionDigits: isWhole ? 0 : 2,
					  maximumFractionDigits: isWhole ? 0 : 2
					}).format(paymentValue)
				  : '';
				
				try {
					// 1) Create contractor: resources + details
					const createRes = await fetch("/scripts/createContractor.php", {
						method: "POST",
						headers: {
							"Content-Type": "application/json",
							"X-CSRF-Token": window.csrfToken
						},
						body: JSON.stringify({
							firstName,
							middleName,
							surname,
							departmentRef: Number(department),
							startDate,
							dailyRate,
							fte
						})
					});
				
					const createData = await createRes.json().catch(async () => ({ status:"error", message: await createRes.text() }));
					if (!createRes.ok || createData.status !== "success") {
						console.error("createContractor failed:", createData);
						alert(createData.message || "Could not create contractor.");
						return;
					}
				
					const newRef = Number(createData.resourceRef);
				
					// 2) Save the payment to _actuals
					const payRes = await fetch("/scripts/insertContractorPayment.php", {
						method: "POST",
						headers: {
							"Content-Type": "application/json",
							"X-CSRF-Token": window.csrfToken
						},
						body: JSON.stringify({
							resourceRef: newRef,
							paymentDate,
							paymentValue
						})
					});
				
					const payData = await payRes.json().catch(async () => ({ status:"error", message: await payRes.text() }));
					if (!payRes.ok || payData.status !== "success") {
						console.error("insertContractorPayment failed:", payData);
						alert(payData.message || "Contractor created, but payment could not be saved.");
						return;
					}
				
					// Refresh dropdown and select new contractor
					await fetchContractorNames();
					const contractorSelect = document.getElementById("contractorSelect");
					contractorSelect.value = String(newRef);
					changeContractorButton(document.getElementById('submitPaymentButton'), contractorSelect);
				
					alert(`${createData.name} created and payment saved.`);
				
					closeContractorDetails(document.getElementById('submitPaymentButton'));
				
				} catch (err) {
					console.error("saveContractor error:", err);
					alert("Could not save contractor/payment.");
				}
				
			}
			
			function closeContractorDetails(contractorButton){
				removeElement('newDetailsDiv');
				contractorButton.hidden = false;
			}
			
			function uploadContractorPayment(contractorSelect, contractorPaymentDate, contractorPaymentValue){
				if(contractorSelect.value == -1){
						
				} else {
					
				}
			}
			
			async function submitContractorPayment(contractorSelect, contractorPaymentDate, contractorPaymentValue){
				const resourceRef  = Number(contractorSelect.value);
				const paymentDate  = scrub(contractorPaymentDate.value);
				const paymentValue = Number(scrub(contractorPaymentValue.value));
			
				if (!Number.isFinite(resourceRef) || resourceRef < 0) {
					alert("Please select a contractor.");
					return;
				}
				if (!paymentDate || !Number.isFinite(paymentValue) || paymentValue <= 0) {
					alert("Please enter a valid payment date and amount.");
					return;
				}
			
				try{
					const res = await fetch("/scripts/insertContractorPayment.php", {
						method: "POST",
						headers: {
							"Content-Type": "application/json",
							"X-CSRF-Token": window.csrfToken
						},
						body: JSON.stringify({ resourceRef, paymentDate, paymentValue })
					});
			
					const data = await res.json().catch(async () => ({ status:"error", message: await res.text() }));
					if (!res.ok || data.status !== "success") {
						console.error("insertContractorPayment failed:", data);
						alert(data.message || "Could not save contractor payment.");
						return;
					}
			
					alert("Payment saved.");
				}catch(err){
					console.error("Error saving contractor payment:", err);
					alert("Could not save contractor payment.");
				}
			}
			
		</script>
	</div>
</div>
<div id='empty'></div>
