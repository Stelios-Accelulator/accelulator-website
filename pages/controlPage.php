<?php
session_start();
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/functions.php');
$csrf = generateCsrfToken();

function getUsersFromDatabase(){
	$pdo = getPDO();
		$stmt = $pdo->prepare("
				SELECT 
						users.REF AS REF,
						users.EMAIL AS EMAIL, 
						users.MAIN AS MAIN,
						user_details.FIRSTNAME AS FIRSTNAME,
						user_details.SURNAME AS SURNAME,
						user_access.ACCESS_LEVEL AS ACCESS_LEVEL,
						user_access.ACTIVE AS ACTIVE
				FROM
						users 
				LEFT JOIN
						user_details ON users.REF = user_details.USER_ID
				LEFT JOIN
						user_access ON users.REF = user_access.USERREF 
				ORDER BY
						users.REF
		");
		
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC); // ✅ no equals sign
}

try {
	
	$users = getUsersFromDatabase();
	
} catch (Throwable $e) {
	http_response_code(500);
	if (ob_get_length()) { ob_clean(); }
	echo json_encode(['error' => 'SERVER_ERROR']);
	exit;
}

?>

<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

<script>

		// ✅ clean echo of PHP -> JS
		const usersFromDB = <?= json_encode($users, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
		
		function deleteUser(reference,firstname,surname){
			const proceed = confirm(`Are you sure you want to delete ${firstname} ${surname}?`);
			if (proceed) {
				console.log(`Confirmed deletion of ref: ${reference}.`);
				
				const payload = {
					reference: reference
				}
				
				fetch('/scripts/deleteUser.php', {
					method: 'POST', 
					headers: {
						'Content-Type': 'application/json',
						'X-CSRF-Token': window.csrfToken
					},
					body: JSON.stringify(payload)
				})
				.then(res => res.json())
				.then((data) => {
					if (data.status === 'success') {
						alert(`Successfully deleted ${firstname} ${surname}`)
					}else{
						alert(`Failed to delete ${firstname} ${surname}`);
					}
				}) 
			} else {
				console.log(`Aborted deletion of ref: ${reference}.`);
			}
		}
		
		document.addEventListener('DOMContentLoaded', () => {
			const table = document.getElementById('usersTable');
		
			// Clear any existing content
			table.innerHTML = '';
		
			if (!usersFromDB || usersFromDB.length === 0) {
				const row = document.createElement('tr');
				const cell = document.createElement('td');
				cell.colSpan = 6;
				cell.textContent = 'No users found';
				row.appendChild(cell);
				table.appendChild(row);
				return;
			}
		
			// Create table header
			const headerRow = document.createElement('tr');
			const headers = ['ID', 'Email', 'First Name', 'Surname', 'Access Level', 'Active','Actions'];
			headers.forEach(text => {
				const th = document.createElement('th');
				th.textContent = text;
				headerRow.appendChild(th);
			});
			table.appendChild(headerRow);
			
			// Create rows dynamically
			usersFromDB.forEach(u => {
				const row = document.createElement('tr');
				
				[ // Create the fields from the table
					u.REF,
					u.EMAIL,
					u.FIRSTNAME || '',
					u.SURNAME || '',
					u.ACCESS_LEVEL || 0,
					u.ACTIVE == 1 ? 'Yes' : 'No'
				].forEach(value => {
					const td = document.createElement('td');
					td.textContent = value;
					row.appendChild(td);
					
				});
				
				const deleteRowButton = document.createElement('button');
				deleteRowButton.ref = u.REF;
				deleteRowButton.textContent = 'Delete';
				deleteRowButton.addEventListener(
					"click",
					() => {deleteUser(u.REF,u.FIRSTNAME,u.SURNAME)}
				);
				row.appendChild(deleteRowButton);
				
				
				table.appendChild(row);
			});
		});
		
</script>

<div id="controlSettings" class="padded">
		<h1>Superuser Control Settings</h1>
		<table id="usersTable"></table>
</div>

<?php
require_once(__DIR__ . '/../includes/footer.php');
?>