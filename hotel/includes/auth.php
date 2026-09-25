<?php
session_start();

if (empty($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'hotel') {
    header('location: login.php');
    exit;
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/activity.php';

$stmt = $pdo->prepare("SELECT u.id, u.username, u.role, u.status, u.hotel_id, h.name AS hotel_name
    FROM users u
    LEFT JOIN hotels h ON h.id = u.hotel_id
    WHERE u.id = ? LIMIT 1");
$stmt->execute([$_SESSION['id'] ?? 0]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'hotel' || $currentUser['status'] !== 'active' || empty($currentUser['hotel_id'])) {
    $_SESSION = [];
    session_destroy();
    header('location: login.php');
    exit;
}

$_SESSION['username'] = $currentUser['username'];
$hotelId = (int) $currentUser['hotel_id'];
