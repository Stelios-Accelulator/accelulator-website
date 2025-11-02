<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$ref = $in['ref'] ?? null;

$user = checkUser();
$company = getUsersCompanyId($user);
$table = "{$company}_pay_rises";

if ($ref){
	$sql = "
		UPDATE $table 
		SET 
			RESOURCE_REF=:res,
			EFFECTIVE_DATE=:dt,
			RISE_KIND=:kind, 
			VALUE=:val, 
			NOTE=:note, 
			APPLIED_FLAG=:applied
		WHERE
			REF=:ref
	";
} else {
	$sql = "
		INSERT INTO $table (
			RESOURCE_REF,
			EFFECTIVE_DATE,
			RISE_KIND,
			VALUE,
			NOTE,
			APPLIED_FLAG
		) VALUES (
			:res,
			:dt,
			:kind,
			:val,
			:note,
			:applied
		)
	";
}

$stmt = $pdo->prepare($sql);
$stmt->execute([
	':ref'		=>	$ref,
	':res'		=>	(int)$in['resourceRef'],
	':dt'		=>	$in['effectiveDate'],			// 'YYYY-MM-01'
	':kind'		=>	$in['kind'],					// PCT|ABS|NEW
	':val'		=>	(float)$in['value'],
	':note'		=>	$in['note'] ?? null,
	':applied'	=>	(int)!!($in['applied'] ?? 0),
]);
echo json_encode(
	[
		'status'=>'success'
	]
);