<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CAMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #004a99;
            --secondary-blue: #e6f0ff;
            --accent-blue: #007bff;
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background-color: var(--primary-blue); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .navbar-brand, .nav-link { color: white !important; }
        .nav-link:hover { color: #e9ecef !important; }
        .card-header { background-color: var(--primary-blue); color: #fff; }
        .card { border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .sidebar { background-color: white; border-right: 1px solid #dee2e6; min-height: calc(100vh - 56px); box-shadow: 2px 0 5px rgba(0,0,0,0.05); padding: 20px 0; }
        .sidebar .nav-link { color: #333 !important; padding: 10px 20px; border-radius: 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: var(--secondary-blue); color: var(--primary-blue) !important; font-weight: bold; border-right: 4px solid var(--primary-blue); }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-trophy me-2"></i>CAMS Admin</a>
        <div class="d-flex">
            <span class="navbar-text text-white me-3">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout <i class="fas fa-sign-out-alt"></i></a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar d-none d-md-block">
            <nav class="nav flex-column">
                <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a>
                <a class="nav-link <?php echo $current_page == 'championships.php' ? 'active' : ''; ?>" href="championships.php"><i class="fas fa-medal me-2"></i> Championships</a>
                <a class="nav-link <?php echo $current_page == 'hotels.php' ? 'active' : ''; ?>" href="hotels.php"><i class="fas fa-hotel me-2"></i> Hotels & Pricing</a>
                <a class="nav-link <?php echo $current_page == 'delegations.php' ? 'active' : ''; ?>" href="#countries"><i class="fas fa-globe me-2"></i> Delegations Overview</a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 p-4">
            <?php if(isset($msg) && !empty($msg)) echo $msg; ?>
