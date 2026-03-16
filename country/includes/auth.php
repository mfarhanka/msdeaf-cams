<?php
session_start();
// Check if user is logged in and is a country manager
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'country_manager') {
    header("location: ../login.php");
    exit;
}
require_once '../includes/db.php';
$msg = '';
?>