<?php
session_start();
if (empty($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'volunteer') {
    header('location: ../../login.php'); exit;
}
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/activity.php';
$mustChangeColumnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
$mustChangePasswordSelect = $mustChangeColumnStmt->fetch(PDO::FETCH_ASSOC)
    ? 'must_change_password'
    : '0 AS must_change_password';
$stmt = $pdo->prepare("SELECT id, username, role, status, {$mustChangePasswordSelect} FROM users WHERE id=? LIMIT 1");
$stmt->execute([$_SESSION['id'] ?? 0]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || $currentUser['role'] !== 'volunteer' || $currentUser['status'] !== 'active') {
    $_SESSION=[]; session_destroy(); header('location: ../../login.php'); exit;
}
if ((int)$currentUser['must_change_password'] === 1 && basename($_SERVER['PHP_SELF'] ?? '') !== 'change-password.php') {
    header('location: change-password.php'); exit;
}
