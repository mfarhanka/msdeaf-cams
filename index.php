<?php
session_start();

if (!empty($_SESSION['loggedin']) && !empty($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('location: admin/dashboard.php');
        exit;
    }

    if ($_SESSION['role'] === 'country_manager') {
        header('location: country/dashboard.php');
        exit;
    }
}

header('location: login.php');
exit;