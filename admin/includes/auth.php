<?php
session_start();
// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("location: ../login.php");
    exit;
}
require_once '../includes/db.php';
require_once '../includes/activity.php';

$stmt = $pdo->prepare("SELECT username, role, status FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['id'] ?? 0]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'admin' || ($currentUser['status'] ?? 'active') !== 'active') {
    $_SESSION = [];
    session_destroy();
    header("location: ../login.php");
    exit;
}

$_SESSION['username'] = $currentUser['username'];
$msg = '';
?>