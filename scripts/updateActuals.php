<?php
session_start();
require_once('../includes/functions.php');
validateCsrfToken(); // <--- protect this script
header('Content-Type: application/json');

// ✅ Check script runs at all
error_log("✅ Script started");

$user = checkUser();
if ($user == '') {
	echo json_encode(["status" => "error", "message" => "User not authenticated"]);
	// Could send the user home with a message that they're not logged in... even better, send them to the sign in page and ask them to try again
	exit;
}

$ref = getUsersCompanyId($user);
$table_actuals = $ref . '_actuals';
$paytypeGroupTable = $ref . '_paytype_group';

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

// ✅ Log input
error_log("Incoming data: " . print_r($data, true));

if (!$data) {
	echo json_encode(["status" => "error", "message" => "Invalid or empty JSON"]);
	exit;
}

$resourceRef = $data['resourceRef'];
$monthValue = $data['month']; // e.g. 'Apr-25'
$type = $data['type'];             // e.g. 'base'
$value = round(floatval($data['value']), 2);

// ------------------------
// Pull through each of the paytype references from the database for later in the script
// ------------------------

// ✅ Step 1: Lookup TYPE REF
$typeLookupStmt = $pdo->prepare("
	SELECT REF 
	FROM {$paytypeGroupTable} 
	WHERE VALUE = :type
	LIMIT 1
");
$typeLookupStmt->execute([':type' => $type]);
$typeRow = $typeLookupStmt->fetch(PDO::FETCH_ASSOC);

if (!$typeRow) {
	error_log("❌ Type '$type' not found in {$paytypeGroupTable}");
	echo json_encode([
		"status" => "error",
		"message" => "Invalid type '$type' — not found in paytype group table"
	]);
	exit;
}

$typeRef = $typeRow['REF'];
error_log("✅ Type '$type' maps to TYPE_REF: $typeRef");


// ✅ Step 2: Convert 'Apr-25' to date range
$startDate = DateTime::createFromFormat('M-y', $monthValue);
if (!$startDate) {
	echo json_encode(["status" => "error", "message" => "Invalid month format: $monthValue"]);
	exit;
}
$startDate->modify('first day of this month');
$endDate = clone $startDate;
$endDate->modify('last day of this month');
$start = $startDate->format('Y-m-d');
$end = $endDate->format('Y-m-d');

error_log("✅ Date range: $start to $end");

// ✅ Step 3: Fetch matching rows
$stmt = $pdo->prepare("
	SELECT REF, VALUE
	FROM {$table_actuals}
	WHERE EMP_KEY = :resourceRef
	  AND TYPE = :typeRef
	  AND DATE BETWEEN :start AND :end
");

$stmt->execute([
	':resourceRef' => $resourceRef,
	':typeRef' => $typeRef,
	':start' => $start,
	':end' => $end
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$numRows = count($rows);

if ($numRows === 0) {
	error_log("❌ No matching rows found");
	echo json_encode([
		"status" => "error",
		"message" => "No matching entries found.",
		"debug" => [
			"resourceRef" => $resourceRef,
			"type" => $type,
			"typeRef" => $typeRef,
			"start" => $start,
			"end" => $end,
			"table" => $table_actuals
		]
	]);
	exit;
}

error_log("✅ Found $numRows matching rows");

// ✅ Step 4: Calculate current total and adjustment
$currentTotal = 0;
foreach ($rows as $r) {
	$currentTotal += floatval($r['VALUE']);
}
$currentTotal = round($currentTotal, 2);
$delta = $value - $currentTotal;
$adjustment = round($delta / $numRows, 2);

error_log("Current total: $currentTotal; Target: $value; Delta: $delta; Per-row adj: $adjustment");

if (abs($delta) < 0.01) {
	echo json_encode([
		"status" => "nochange",
		"message" => "Total already matches target value.",
		"currentTotal" => $currentTotal
	]);
	exit;
}

// ✅ Step 5: Update rows
$updateStmt = $pdo->prepare("
	UPDATE {$table_actuals}
	SET VALUE = ROUND(VALUE + :adj, 2)
	WHERE REF = :ref
");

$totalAdjusted = 0;
$updatesDone = 0;

for ($i = 0; $i < $numRows; $i++) {
	$row = $rows[$i];

	if ($i === $numRows - 1) {
		$finalAdjustment = round($value - $totalAdjusted - floatval($row['VALUE']), 2);
	} else {
		$finalAdjustment = $adjustment;
	}

	if (abs($finalAdjustment) < 0.005) {
		error_log("⚠️ Skipping REF {$row['REF']}, adj too small ($finalAdjustment)");
		continue;
	}

	$success = $updateStmt->execute([
		':adj' => $finalAdjustment,
		':ref' => $row['REF']
	]);

	if (!$success) {
		error_log("❌ Update failed for REF {$row['REF']}: " . print_r($updateStmt->errorInfo(), true));
		echo json_encode([
			"status" => "error",
			"message" => "Update failed for REF {$row['REF']}",
			"pdo_error" => $updateStmt->errorInfo()
		]);
		exit;
	}

	error_log("✅ Updated REF {$row['REF']} by $finalAdjustment");
	$updatesDone++;
	$totalAdjusted += round(floatval($row['VALUE']) + $finalAdjustment, 2);
}

// ✅ Step 6: Respond
echo json_encode([
	"status" => "success",
	"message" => "Updated $updatesDone entries",
	"type" => $type,
	"typeRef" => $typeRef,
	"from" => $currentTotal,
	"to" => $value,
	"finalTotal" => number_format($totalAdjusted, 2),
	"numRows" => $numRows
]);
?>