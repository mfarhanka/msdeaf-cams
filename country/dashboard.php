<?php
session_start();
// Check if user is logged in and is a country manager
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'country_manager') {
    header("location: ../login.php");
    exit;
}
require_once '../includes/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Country Dashboard - CAMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f8ff; font-family: 'Segoe UI', sans-serif; }
        .navbar { background-color: #0d6efd; }
        .navbar-brand, .nav-link { color: #fff !important; }
        .nav-link:hover { color: #e9ecef !important; }
        .card-header { background-color: #0d6efd; color: #fff; }
        .card { border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .sidebar { background-color: #fff; min-height: calc(100vh - 56px); box-shadow: 2px 0 5px rgba(0,0,0,0.05); padding: 20px 0; }
        .sidebar .nav-link { color: #333 !important; padding: 10px 20px; border-radius: 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: #f0f8ff; color: #0d6efd !important; font-weight: bold; border-right: 4px solid #0d6efd; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="#"><i class="fas fa-flag me-2"></i>CAMS Delegation</a>
        <div class="d-flex">
            <span class="navbar-text text-white me-3">Team Manager: <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout <i class="fas fa-sign-out-alt"></i></a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar d-none d-md-block">
            <nav class="nav flex-column">
                <a class="nav-link active" href="dashboard.php"><i class="fas fa-home me-2"></i> Dashboard</a>
                <a class="nav-link" href="#athletes"><i class="fas fa-users me-2"></i> My Athletes (Roster)</a>
                <a class="nav-link" href="#book"><i class="fas fa-bed me-2"></i> Book Accommodation</a>
                <a class="nav-link" href="#rooming"><i class="fas fa-list-ol me-2"></i> Room Assignments</a>
                <a class="nav-link" href="#finance"><i class="fas fa-file-invoice-dollar me-2"></i> Financial Summary</a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 p-4">
            <h2 class="mb-4 text-primary">Delegation Dashboard</h2>
            
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-center text-white bg-primary">
                        <div class="card-body">
                            <h3><i class="fas fa-user-friends"></i></h3>
                            <h5 class="card-title">Registered Athletes</h5>
                            <p class="card-text fs-4">24</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center bg-white border-primary border">
                        <div class="card-body text-primary">
                            <h3><i class="fas fa-bed"></i></h3>
                            <h5 class="card-title">Rooms Booked</h5>
                            <p class="card-text fs-4">12</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center text-white bg-success">
                        <div class="card-body">
                            <h3><i class="fas fa-check-circle"></i></h3>
                            <h5 class="card-title">Balance Due</h5>
                            <p class="card-text fs-4">$0.00</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Athletes Roster Table -->
            <div class="card" id="athletes">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Athlete & Official Roster</h5>
                    <button class="btn btn-light btn-sm text-primary fw-bold"><i class="fas fa-plus"></i> Add Athlete</button>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Manage your delegation. Ensure gender is accurate for rooming assignments.</p>
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>Sport / Category</th>
                                <th>Passport ID</th>
                                <th>Room Assignment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>John Doe</td>
                                <td>M</td>
                                <td>Athletics - 100m</td>
                                <td><span class="badge bg-secondary">Encrypted</span></td>
                                <td><span class="badge bg-warning text-dark">Unassigned</span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td>Jane Smith</td>
                                <td>F</td>
                                <td>Swimming - Freestyle</td>
                                <td><span class="badge bg-secondary">Encrypted</span></td>
                                <td>Room 101 (Hotel A)</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>