<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$user = checkUser();
if ($user === '') exit("User not authenticated.");

$ref = getUsersCompanyId($user);
$table_forecast = $ref . '_forecasts';

// 1️⃣ Get the forecasts for the company
$stmt = $pdo->prepare("
	SELECT 
		ACTUAL_FORECAST  AS MIX,
		FORECAST_NAME    AS NAME,
		FORECAST_VERSION AS VERSION,

		/* Timescale in 'Mon YYYY – Mon YYYY' */
		CONCAT(
			DATE_FORMAT(
				MIN(COALESCE(STR_TO_DATE(MONTH, '%e %b-%y'), STR_TO_DATE(MONTH, '%b-%y'))),
				'%b %Y'
			),
			' – ',
			DATE_FORMAT(
				MAX(COALESCE(STR_TO_DATE(MONTH, '%e %b-%y'), STR_TO_DATE(MONTH, '%b-%y'))),
				'%b %Y'
			)
		) AS TIMESCALE,

		MIN(DATESTAMP)    AS CREATED,
		MAX(IS_PUBLISHED) AS PUBLISHED

	FROM $table_forecast
	GROUP BY 
		ACTUAL_FORECAST,
		FORECAST_NAME,
		FORECAST_VERSION

	/* Sort by earliest month in the forecast window */
	ORDER BY 
		MIN(COALESCE(STR_TO_DATE(MONTH, '%e %b-%y'), STR_TO_DATE(MONTH, '%b-%y'))) ASC,
		MIN(DATESTAMP) ASC
");

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
// ⚠️ At some point, want to change this to a fetch request so that I can repopulate the table when a change is made. For now, keep as is.

?>

<script>
	
	// HELPERS
	
	function handlePublishToggle(mix, name, version, isChecked) {
		const action = isChecked ? 'publish' : 'unpublish';
	
		// Optional: immediate UI feedback
		console.log(`${action.toUpperCase()}: ${mix} ${name} v${version}`);
	
		fetch('/scripts/toggleForecastPublish.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-Token': window.csrfToken
			},
			body: JSON.stringify({
				mix: mix,
				name: name,
				version: version,
				published: isChecked ? 1 : 0
			})
		})
		.then(res => res.json())
		.then(data => {
			if (data.status === 'success') {
				console.log(`${mix} ${name} v${version} successfully ${action}ed.`);
			} else {
				alert(`Failed to ${action} forecast: ${data.message || 'unknown error'}`);
				// Revert checkbox if it failed
				event.target.checked = !isChecked;
			}
		})
		.catch(err => {
			console.error('Network error:', err);
			alert('Network error while updating publish status.');
			event.target.checked = !isChecked;
		});
	}
	
	// Changes the Forecast Name in the _forecasts table
	function saveForecastName(mix, name, version){
		const input = document.getElementById('forecastInput');
		if (!input) { alert('No input field found.'); return; }
	
		const newName = (input.value || '').trim();
	
		if (newName === '' || newName === name){
			alert(`The name you've chosen is either blank or hasn't changed. Please revise and try again.`);
			return;
		}
	
		// Prevent double-clicks
		const btn = document.getElementById('saveForecastButton');
		if (btn) btn.disabled = true;
	
		fetch("/scripts/changeForecastName.php", {
			method: "POST",
			credentials: "same-origin",
			headers: {
				"Content-Type": "application/json",
				"Accept": "application/json",
				"X-CSRF-Token": window.csrfToken
			},
			body: JSON.stringify({ mix, name, version, newName })
		})
		.then(r => r.json())
		.then(data => {
			if (data.status === "success") {
				alert(`${mix} ${name} ${version} has successfully been changed to ${mix} ${newName} ${version}.`);
				// Update the visible table cell in-place (optional), or just reload:
				// Find the row by its current values:
//				const nameCell = [...document.querySelectorAll('#forecastsBody tr')].find(tr =>
//					tr.querySelector('td:nth-child(1)')?.textContent === mix &&
//					tr.querySelector('td:nth-child(2)')?.textContent === name &&
//					tr.querySelector('td:nth-child(3)')?.textContent === String(version)
//				)?.querySelector('td:nth-child(2)');
//				if (nameCell) nameCell.textContent = newName;
//				if (nameCell) nameCell.name = newName;
//	
//				destroyMenu('menuContainer');
				// Or force a simple reload instead of DOM surgery:
				location.reload();
			} else {
				alert(data.message || 'Rename failed.');
			}
		})
		.catch(err => {
			console.error(err);
			alert('Network error while renaming forecast.');
		})
		.finally(() => { if (btn) btn.disabled = false; });
	}
	
	// Creates the Edit Forecast Menu
	function createEditForecastMenu(mix,name,version){
		
		// Destroy existing menu if found
		let menuExists = document.getElementById('menuContainer');
		if (menuExists != null){
			destroyMenu('menuContainer');
		};
		
		let contentView									= document.getElementById('contentView');
		
		let editMenu										=	document.createElement('div');
		editMenu.id											=	'menuContainer';
		editMenu.classList.add("menu");
		
		let editMenuHeader							=	document.createElement('div');
		editMenuHeader.id								=	'draggableEditMenuHeader';
		editMenuHeader.classList.add("menuHeader");
		
		let editMenuHeaderText					=	document.createElement('strong');
		editMenuHeaderText.textContent	=	'Edit Forecast';
		
		let closeMenuButton							=	document.createElement('button');
		closeMenuButton.textContent			=	'X';
		closeMenuButton.addEventListener("click",() => destroyMenu('menuContainer'));
		
		editMenuHeader.appendChild(editMenuHeaderText);
		editMenuHeader.appendChild(closeMenuButton);
		
		editMenu.appendChild(editMenuHeader);
		
		let forecastNameDiv									=	document.createElement('div');
		forecastNameDiv.classList.add("menuRow");
		
		let forecastNameInput								=	document.createElement('input');
		forecastNameInput.id								=	'forecastInput';
		forecastNameInput.type							=	'text';
		forecastNameInput.value							=	`${name}`;
		forecastNameInput.classList.add("menuInputField");
		
		forecastNameDiv.appendChild(forecastNameInput);
		editMenu.appendChild(forecastNameDiv);
		
		let saveForecastDiv									=	document.createElement('div');
		saveForecastDiv.classList.add("buttonGroup");
		
		let saveForecastButton							=	document.createElement('button');
		saveForecastButton.id								=	'saveForecastButton';
		saveForecastButton.textContent			=	'Save';
		saveForecastButton.addEventListener("click",() => saveForecastName(mix, name, version));
		
		saveForecastDiv.appendChild(saveForecastButton);
		editMenu.appendChild(saveForecastDiv);
		
		contentView.appendChild(editMenu);
		
		makeDraggable(editMenu);
	}
	
	function downloadForecast(mix, name, version){
		const url = `/scripts/exportForecastXlsx.php?mix=${encodeURIComponent(mix)}&name=${encodeURIComponent(name)}&version=${encodeURIComponent(version)}`;
	
		fetch(url, { method: 'GET', headers: { 'X-CSRF-Token': window.csrfToken } })
			.then(async (res) => {
				if (!res.ok) {
					const t = await res.text().catch(()=> '');
					throw new Error(`HTTP ${res.status}: ${t || 'No response body'}`);
				}
				const disp = res.headers.get('Content-Disposition') || '';
				const m = /filename="?([^"]+)"?$/i.exec(disp);
				const blob = await res.blob();
				const a = document.createElement('a');
				a.href = URL.createObjectURL(blob);
				a.download = m ? m[1] : `${mix} ${name} ${version} Forecast.xlsx`;
				document.body.appendChild(a); a.click(); URL.revokeObjectURL(a.href); a.remove();
			})
			.catch(err => {
				console.error('Download failed:', err);
				alert(`Could not download: ${err.message}`);
			});
	}
	
	function deleteForecast(mix,name,version){
		const proceed = confirm(`Are you sure you want to delete ${mix} ${name} ${version}?\n\nOnce this is deleted, it cannot be restored.`);
		if (proceed) {
			// To be written: script to delete the forecast using fetch
			fetch("/scripts/deleteForecast.php", {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					"X-CSRF-Token": csrfToken
				},
				body: JSON.stringify({
					mix: mix,
					name: name,
					version: version
				})
			})
			.then(res => res.json())
			.then(data => {
				if (data.status === "success") {
					alert(`${mix} ${name} ${version} has successfully deleted.`);
					// Reload the page
				}
			});
		} else {
			console.log("Cancelled by user");
		}
	}
	
	
	const forecastsData = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
	
	document.addEventListener('DOMContentLoaded', () => {
		
		const tableBody = document.getElementById('forecastsBody');
		if (!tableBody || !forecastsData) return; // Ensure that table body and forecastsData exist, if not, quit the script
		
		let x = 1;
		
		forecastsData.forEach(f => {
			let newRow 				= document.createElement('tr');
			newRow.id				= `forecastRow${x}`;
			
			let mixTd 				=	document.createElement('td');
			mixTd.id				=	`mixTD${x}`;
			mixTd.textContent		=	f.MIX;
			
			let nameTd 				=	document.createElement('td');
			nameTd.id				=	`nameTD${x}`;
			nameTd.textContent		=	f.NAME;
			
			let versionTd			=	document.createElement('td');
			versionTd.id			=	`versionTD${x}`;
			versionTd.textContent	=	f.VERSION;
			
			let timescaleTd			=	document.createElement('td');
			timescaleTd.id			=	`timescaleTD${x}`;
			timescaleTd.textContent	=	f.TIMESCALE ?? '-';
			
			let createdTd			=	document.createElement('td');
			createdTd.id			=	`createdTD${x}`;
			createdTd.textContent	=	new Date(f.CREATED).toLocaleString();
			
			let inputTd				= document.createElement('td');
			let publishedInput		=	document.createElement('input');
			publishedInput.id		=	`published${x}`;
			publishedInput.classList.add('col-published');
			publishedInput.type		=	'checkbox';
			publishedInput.checked	=	(f.PUBLISHED == 1);
			
			publishedInput.addEventListener('change', (event) => {
				const isChecked = event.target.checked;
				handlePublishToggle(f.MIX, f.NAME, f.VERSION, isChecked);
			});
			
			inputTd.appendChild(publishedInput);
			
			let actionsTd			=	document.createElement('td');
			actionsTd.id			=	`actionsTd${x}`;
			actionsTd.classList.add('actionButtons');
			
			let editButton			=	document.createElement('button');
			editButton.id			=	`editButton${x}`;
			editButton.textContent	=	'Edit';
			editButton.mix			=	f.MIX;
			editButton.name			=	f.NAME;
			editButton.version		=	f.VERSION
			editButton.addEventListener("click",() => createEditForecastMenu(f.MIX,f.NAME,f.VERSION));
			
			let downloadButton			=	document.createElement('button');
			downloadButton.id			=	`downloadButton${x}`;
			downloadButton.textContent	=	'Download';
			downloadButton.addEventListener("click",() => downloadForecast(f.MIX,f.NAME,f.VERSION));
			
			let deleteButton			=	document.createElement('button');
			deleteButton.id				=	`deleteButton${x}`;
			deleteButton.textContent	=	'Delete';
			deleteButton.addEventListener("click",() => deleteForecast(f.MIX,f.NAME,f.VERSION));
			
			/* --------------------------------------------------------------------------- */
			/* ASYNC BLOCK TO ASSESS THE USER LEVEL AND THEN PRODUCE THE AVAILABLE BUTTONS */
			/* --------------------------------------------------------------------------- */
			// Introduced to ensure that only superusers can see the download button for now as it doesn't work properly
			// Once this is fixed, we can remove the async element and just add the buttons for each
			(async () => {
				
				const level = await returnUserAccessLevel();	// Returns the user's access level 
				
				actionsTd.appendChild(editButton);				// Always appends the edit button
				if(level===10){									// Checks if the user is a superuser and, if so, appends the download button
					actionsTd.appendChild(downloadButton);
				}
				actionsTd.appendChild(deleteButton);			// Always appends the delete button
			})();
			
			newRow.appendChild(mixTd);
			newRow.appendChild(nameTd);
			newRow.appendChild(versionTd);
			newRow.appendChild(timescaleTd);
			newRow.appendChild(createdTd);
			newRow.appendChild(inputTd);
			newRow.appendChild(actionsTd);
			
			tableBody.appendChild(newRow);
			
			x++;
			
		});
		
	});
	
</script>
<style>
	/* Toolbar layout + spacing */
	.actionButtons{
	  display: flex;
	  flex-wrap: wrap;          /* lets it go to two rows when needed */
	  align-items: center;      /* vertical centering */
	  gap: 0.5rem 0.75rem;      /* row gap / column gap */
	  padding: 0.5em 0;
	}
	
	/* make the card/table size to its content rather than span full width */
	.fit-card {
	  display: inline-block;     /* shrink to content */
	  max-width: 100%;           /* don’t overflow small screens */
	}
	
	/* keep horizontal scroll on narrow screens without forcing 100% width */
	.fit-wrap {
	  display: inline-block;     /* shrink to content */
	  max-width: 100%;
	  overflow-x: auto;
	}
	
	/* let the table size to content */
	.fit-table {
	  width: auto;               /* key change: stop stretching */
	  table-layout: auto;        /* columns size to their contents */
	}
	
	.fit-table th, .fit-table td {
	  white-space: nowrap;       /* keeps your compact look */
	}
	
	/* center header & cells for the Published column */
	#forecastsTable th.col-published,
	#forecastsTable td.col-published {
	  text-align: center;
	  width: 90px;               /* optional: consistent column width */
	}
	
	/* ensure the checkbox is visually centered */
	#forecastsTable td.col-published input[type="checkbox"] {
	  display: block;
	  margin: 0 auto;
	}
	
	#forecastsTable th, #forecastsTable td{
		padding-left: 1em;
		padding-right: 1em;
	}
	
</style>
<div class="padded">
	<h2>Forecast Hub</h2>
	<p class="roleSub" style="margin-top:0;">Manage which forecasts are published, rename, download, or upload new ones.</p>
	
	<!-- Table card -->
	<div class="settingsPanel fit-card">
		<div class="table-wrap fit-wrap"><!-- responsive scroll on narrow screens --> <!-- .table-wrap is in your CSS --> <?php /* filecite below references .table-wrap */ ?>
		<table id="forecastsTable" class="fit-table">
			<thead>
				<tr>
					<th class="col-mix">Mix</th>
					<th class="col-name">Name</th>
					<th class="col-version">Ver</th>
					<th class="col-timescale">Timescale</th>
					<th class="col-created">Created</th>
					<th class="col-published">Published</th>
					<th class="col-actions">Actions</th>
				</tr>
			</thead>
			<tbody id="forecastsBody">
			<!-- Filled by JS: getForecasts() -->
			</tbody>
		</table>
		
		</div>
	</div>
	<!-- Row actions help -->
	<div class="roleSub" style="margin-top:.5rem;">
		Tip: Click <em>Edit</em> to rename that specific forecast.
	</div>
	
	
</div>