<?php
// logout.php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/activity.php';

$actor = getActorDetailsFromSession();
if (isset($pdo) && $pdo instanceof PDO && $actor['id'] !== null) {
	recordActivity(
		$pdo,
		'logout',
		'user',
		$actor['id'],
		'User signed out.',
		[],
		$actor['id'],
		$actor['role'],
		$actor['username']
	);
}

// Unset all of the session variables
$_SESSION = array();
// Destroy the session.
session_destroy();
// Redirect to login page
header("location: login.php");
exit;
?>