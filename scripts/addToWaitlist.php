<?php // Script to add a name and address to the waitlist
session_start();
require_once('../includes/functions.php');

$name = $_COOKIE['name'];
$email = $_COOKIE['email'];

echo <<<_DESTROYCOOKIES
<script>
	eraseCookie('name');
	eraseCookie('email');
</script>
_DESTROYCOOKIES;

$q = queryMysql("INSERT INTO waitlist (`NAME`,`EMAIL`) VALUES ('$name','$email')");
?>