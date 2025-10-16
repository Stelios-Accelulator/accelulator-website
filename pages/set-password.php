<?php
require_once("../includes/header.php");
require_once __DIR__ . '/../includes/functions.php';
$token = $_GET['t'] ?? '';
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Set your password</title></head>
<body>
  <h1>Create your password</h1>
  <form method="post" action="/scripts/completeInvite.php">
	<input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
	<div><label>New password</label><input type="password" name="pw1" required></div>
	<div><label>Confirm password</label><input type="password" name="pw2" required></div>
	<button type="submit">Save</button>
  </form>
</body>
</html>