<?php
session_start();
// Check if user is logged in and is a country manager
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'country_manager') {
    header("location: ../login.php");
    exit;
}
require_once '../includes/db.php';
require_once '../includes/activity.php';
require_once '../includes/delegate_menu.php';

$stmt = $pdo->prepare("SELECT username, role, status FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['id'] ?? 0]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'country_manager' || ($currentUser['status'] ?? 'active') !== 'active') {
    $_SESSION = [];
    session_destroy();
    header("location: ../login.php");
    exit;
}

$_SESSION['username'] = $currentUser['username'];
$msg = '';

if (isset($_SESSION['country_access_error']) && is_string($_SESSION['country_access_error'])) {
    $msg = "<div class='alert alert-warning alert-dismissible fade show'>" . htmlspecialchars($_SESSION['country_access_error']) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    unset($_SESSION['country_access_error']);
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentMenuItem = findDelegateMenuItemByHref($currentPage);

if ($currentMenuItem !== null && !isAppSettingEnabled($pdo, $currentMenuItem['setting_key'], true)) {
    $visibleMenuItems = array_values(getVisibleDelegateMenuItems($pdo));

    if ($visibleMenuItems !== []) {
        $_SESSION['country_access_error'] = $currentMenuItem['label'] . ' is currently hidden by the administrator.';
        header('location: ' . $visibleMenuItems[0]['href']);
        exit;
    }

    http_response_code(403);
    echo 'Access denied. All delegation menu items are currently hidden by the administrator.';
    exit;
}
?>