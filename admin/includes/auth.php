<?php
session_start();
// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("location: ../login.php");
    exit;
}
require_once '../includes/db.php';
$msg = '';
?>