<?
use PhpOffice\PhpSpreadsheet\IOFactory;

function loadPhpSpreadsheet($uploadedFilePath) {
	$spreadsheet = IOFactory::load($uploadedFilePath);
	return $spreadsheet->getActiveSheet()->toArray();
}
?>