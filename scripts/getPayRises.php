<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$user = checkUser();
$company = getUsersCompanyId($user);
$table = "{$company}_pay_rises";

$stmt = $pdo->prepare("
	SELECT 
		REF,
		RESOURCE_REF,
		EFFECTIVE_DATE,
		RISE_KIND, 
		VALUE, 
		NOTE, 
		APPLIED_FLAG
	FROM $table 
	ORDER BY
		EFFECTIVE_DATE ASC,
		REF ASC
");

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
echo json_encode(
	[
		'status'=>'success', 
		'rows'=>$rows
	], 
	JSON_UNESCAPED_UNICODE
);