<?php // Script to extract the NI bands and populate an object in JavaScript
require_once('../includes/functions.php');
header('Content-Type: application/json');

try {
	$stmt = $pdo->prepare("SELECT FROM_DATE, TO_DATE, SECONDARY_THRESHOLD_MONTHLY, RATE FROM ni_employers_rates ORDER BY FROM_DATE ASC");
	$stmt->execute();
	$niRates = $stmt->fetchAll(PDO::FETCH_ASSOC);
	echo json_encode($niRates);
} catch (PDOException $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>