<style>
	#advancedUploadContainer{
		padding-top: 1em;
		padding-bottom: 1em;
	}
</style>

<div id="content">
	<div class="padded">
		<h1>Upload File</h1>
		
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
			<button id="advUploadBtn">Advanced Upload</button>
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
		</script>
	</div>
</div>
<div id='empty'></div>
