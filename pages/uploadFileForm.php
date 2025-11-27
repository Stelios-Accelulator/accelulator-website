<style>
	#advancedUploadContainer{
		padding-top: 1em;
		padding-bottom: 1em;
	}
</style>

<div id="content">
	<div class="padded">
		<h1>Import Actuals</h1>
		<h2>Upload Payroll File</h2>
		
		<div id="drop-area">
		  <p>Drag & drop your payroll file (.xlsx) here or <label for="fileInput"><a href="#">browse</a></label></p>
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
		
		<!--
		<div id="template-download">
			<a href="/downloads/PayrollFileTemplate.xlsx" download>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"  height="1.5em" width="1.5em">
				  <path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15M9 12l3 3m0 0 3-3m-3 3V2.25" />
				</svg>
				Download Payroll Template (.xlsx)
			</a>
		</div>
		-->
		
		<div id="fileName" class="menuRow"></div>
		<div id="result"></div>
		
		<h2>Contractors</h2>
		
		<div class="menuRow">
			<h3>Enter a Contract Payment</h3>
		</div>
		<div class="menuRow">
			<select name="contractorSelect" id="contractorSelect">
				<option value="-1" selected="true">New Contractor</option>
			</select>
			<input id="contractorPaymentDate" name="contractorPaymentDate" type="date">
			<input id="contractorPaymentValue" name="contractorPaymentValue" type="number" min=0 step=100>
			<button id="submitPaymentButton" name="submitPaymentButton" hidden="true">Create</button>
		</div>
		
		<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
		<script>
		  const dropArea = document.getElementById('drop-area');
		  const fileInput = document.getElementById('fileInput');
		  const uploadBtn = document.getElementById('uploadBtn');
		  const advUploadBtn = document.getElementById('advUploadBtn');
		  const fileNameDisplay = document.getElementById('fileName');
		  let selectedFile = null;
		
		  // Prevent default drag behaviors
		  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
			dropArea.addEventListener(eventName, e => e.preventDefault());
			dropArea.addEventListener(eventName, e => e.stopPropagation());
		  });
		
		  // Highlight drop area
		  ['dragenter', 'dragover'].forEach(eventName => {
			dropArea.addEventListener(eventName, () => dropArea.classList.add('highlight'));
		  });
		  ['dragleave', 'drop'].forEach(eventName => {
			dropArea.addEventListener(eventName, () => dropArea.classList.remove('highlight'));
		  });
		
		  // Handle dropped files
		  dropArea.addEventListener('drop', e => {
			selectedFile = e.dataTransfer.files[0];
			fileNameDisplay.textContent = selectedFile.name;
		  });
		
		  // Handle manual selection
		  fileInput.addEventListener('change', e => {
			selectedFile = e.target.files[0];
			fileNameDisplay.textContent = selectedFile.name;
		  });
		
		  // Clicking the label triggers the hidden input
		  document.querySelector('label[for="fileInput"]').addEventListener('click', () => fileInput.click());
		  
		  
			// Handle advanced upload (horizontal vs vertical)
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
			});
			
			async function fetchContractorNames() {
				try {
					const result = await fetch("../scripts/getContractorNames.php", {
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
			
			const contractorButton        = document.getElementById('submitPaymentButton');
			const contractorSelect        = document.getElementById('contractorSelect');
			const contractorPaymentDate   = document.getElementById('contractorPaymentDate');
			const contractorPaymentValue  = document.getElementById('contractorPaymentValue');
			
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
			
			// wire up events
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
		</script>
	</div>
</div>
<div id='empty'></div>
