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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-blue: #004a99;
            --secondary-blue: #e6f0ff;
            --accent-blue: #007bff;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background-color: var(--primary-blue);
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            padding: 0.5rem 1rem;
        }

        .navbar-brand, .nav-link {
            color: white !important;
        }

        .sidebar {
            background-color: white;
            min-height: calc(100vh - 56px);
            border-right: 1px solid #dee2e6;
            max-width: 220px;
            padding-top: 0.75rem;
        }

        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            margin-bottom: 12px;
        }

        .card-body {
            padding: 0.9rem 1rem;
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid var(--secondary-blue);
            font-weight: bold;
            color: var(--primary-blue);
            padding: 0.75rem 1rem;
        }

        .btn-primary {
            background-color: var(--primary-blue);
            border: none;
            padding: 0.55rem 1rem;
        }

        .btn-primary:hover {
            background-color: #003366;
        }

        .badge-status {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 20px;
        }

        .hotel-price {
            font-size: 1.25rem;
            color: var(--primary-blue);
            font-weight: bold;
        }

        .nav-pills .nav-link {
            color: #333 !important;
            padding: 0.55rem 0.9rem;
            font-size: 0.92rem;
        }

        .nav-pills .nav-link:hover {
            background-color: var(--secondary-blue);
            color: var(--primary-blue) !important;
        }

        .nav-pills .nav-link.active {
            background-color: var(--primary-blue) !important;
            color: white !important;
        }

        .container-fluid {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }

        main {
            padding-top: 1rem;
            padding-bottom: 1rem;
        }

        .border-bottom {
            margin-bottom: 0.8rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php"><i class="bi bi-building-check me-2"></i>CAMS | World Deaf Sports</a>
        <div class="d-flex align-items-center">
            <span class="text-white small me-3"><i class="bi bi-person-circle me-1"></i> Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav class="col-md-2 d-none d-md-block sidebar py-3">
            <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                <div class="admin-only">
                    <h6 class="px-3 mb-2 text-muted small text-uppercase fw-bold">Management</h6>
                    <a class="nav-link w-100 text-start <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
                    <a class="nav-link w-100 text-start <?php echo $current_page == 'championships.php' ? 'active' : ''; ?>" href="championships.php"><i class="bi bi-trophy me-2"></i>Championships</a>
                    <a class="nav-link w-100 text-start <?php echo $current_page == 'hotels.php' ? 'active' : ''; ?>" href="hotels.php"><i class="bi bi-hospital me-2"></i>Hotels & Pricing</a>
                    <a class="nav-link w-100 text-start <?php echo $current_page == 'delegations.php' ? 'active' : ''; ?>" href="delegations.php"><i class="bi bi-globe me-2"></i>Delegations</a>
                    <a class="nav-link w-100 text-start <?php echo $current_page == 'tshirts.php' ? 'active' : ''; ?>" href="tshirts.php"><i class="bi bi-tags me-2"></i>T-Shirt Sizes</a>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="col-md-10 ms-sm-auto px-md-4 py-4">
            <?php if(isset($msg) && !empty($msg)) echo $msg; ?>
