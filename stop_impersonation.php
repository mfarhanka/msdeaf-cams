<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/activity.php';

$adminSession = $_SESSION['impersonator_admin'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !is_array($adminSession)
    || empty($adminSession['id'])
    || empty($_SESSION['impersonation_return_csrf'])
    || !hash_equals((string) $_SESSION['impersonation_return_csrf'], (string) ($_POST['csrf_token'] ?? ''))
) {
    header('location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, role, status FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
$stmt->execute([(int) $adminSession['id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || ($admin['status'] ?? 'active') !== 'active') {
    $_SESSION = [];
    session_destroy();
    header('location: login.php');
    exit;
}

$delegationId = isset($_SESSION['id']) ? (int) $_SESSION['id'] : null;
$delegationUsername = isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;

$_SESSION['loggedin'] = true;
$_SESSION['id'] = (int) $admin['id'];
$_SESSION['username'] = (string) $admin['username'];
$_SESSION['role'] = 'admin';
unset($_SESSION['impersonator_admin'], $_SESSION['show_login_announcement'], $_SESSION['impersonation_return_csrf']);
$_SESSION['delegation_impersonation_csrf'] = bin2hex(random_bytes(32));
session_regenerate_id(true);

recordActivity(
    $pdo,
    'delegation_impersonation_ended',
    'user',
    $delegationId,
    'Administrator returned from the delegation portal.',
    ['delegation_username' => $delegationUsername],
    (int) $admin['id'],
    'admin',
    (string) $admin['username']
);

header('location: admin/delegations.php');
exit;
?>
