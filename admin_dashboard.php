<?php
session_start();
// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}
require_once 'includes/db.php';

$msg = '';

// Handle POST actions for Championships
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_championship') {
        $title = trim($_POST['title']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $location = trim($_POST['location']);
        
        if (!empty($title) && !empty($start_date) && !empty($end_date)) {
            $stmt = $pdo->prepare("INSERT INTO championships (title, start_date, end_date, location) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$title, $start_date, $end_date, $location])) {
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle'></i> Championship added successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $msg = "<div class='alert alert-danger'>Failed to add championship.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please fill in all required fields.</div>";
        }
    } elseif ($_POST['action'] === 'edit_championship') {
        $id = $_POST['id'];
        $title = trim($_POST['title']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $location = trim($_POST['location']);
        
        $stmt = $pdo->prepare("UPDATE championships SET title=?, start_date=?, end_date=?, location=? WHERE id=?");
        if ($stmt->execute([$title, $start_date, $end_date, $location, $id])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle'></i> Championship updated!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'delete_championship') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM championships WHERE id=?");
        if ($stmt->execute([$id])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Championship deleted!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'add_hotel') {
        $name = trim($_POST['name']);
        $address = trim($_POST['address']);
        $total_rooms = $_POST['total_rooms'];
        
        $stmt = $pdo->prepare("INSERT INTO hotels (name, address, total_rooms) VALUES (?, ?, ?)");
        if ($stmt->execute([$name, $address, $total_rooms])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-hotel'></i> Hotel added successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'delete_hotel') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM hotels WHERE id=?");
        if ($stmt->execute([$id])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Hotel deleted!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'add_room_type') {
        $hotel_id = $_POST['hotel_id'];
        $name = trim($_POST['name']);
        $capacity = $_POST['capacity'];
        $price_per_night = $_POST['price_per_night'];
        $total_allotment = $_POST['total_allotment'];
        
        $stmt = $pdo->prepare("INSERT INTO room_types (hotel_id, name, capacity, price_per_night, total_allotment) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$hotel_id, $name, $capacity, $price_per_night, $total_allotment])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-bed'></i> Room Type added!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'delete_room_type') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM room_types WHERE id=?");
        if ($stmt->execute([$id])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Room type deleted!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// Fetch total stats
$champ_count = $pdo->query("SELECT COUNT(*) FROM championships")->fetchColumn();
$hotel_count = $pdo->query("SELECT COUNT(*) FROM hotels")->fetchColumn();

// Fetch all championships
$champs_stmt = $pdo->query("SELECT * FROM championships ORDER BY start_date DESC");
$championships = $champs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all hotels
$hotels_stmt = $pdo->query("SELECT * FROM hotels ORDER BY id DESC");
$hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all room types
$room_types_stmt = $pdo->query("SELECT r.*, h.name as hotel_name FROM room_types r JOIN hotels h ON r.hotel_id = h.id ORDER BY h.name ASC");
$room_types = $room_types_stmt->fetchAll(PDO::FETCH_ASSOC);
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
        <a class="navbar-brand fw-bold" href="#"><i class="fas fa-trophy me-2"></i>CAMS Admin</a>
        <div class="d-flex">
            <span class="navbar-text text-white me-3">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout <i class="fas fa-sign-out-alt"></i></a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar d-none d-md-block">
            <nav class="nav flex-column">
                <a class="nav-link active" href="admin_dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a>
                <a class="nav-link" href="#championships"><i class="fas fa-medal me-2"></i> Championships</a>
                <a class="nav-link" href="#hotels"><i class="fas fa-hotel me-2"></i> Hotels & Pricing</a>
                <a class="nav-link" href="#countries"><i class="fas fa-globe me-2"></i> Delegations Overview</a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 p-4">
            <h2 class="mb-4 text-primary">System Administrator Overview</h2>
            
            <?php if(!empty($msg)) echo $msg; ?>

            <div class="row">
                <!-- Stat Cards -->
                <div class="col-md-3">
                    <div class="card text-center text-white bg-primary">
                        <div class="card-body">
                            <h3><i class="fas fa-medal"></i></h3>
                            <h5 class="card-title">Championships</h5>
                            <p class="card-text fs-4"><?php echo $champ_count; ?> Active</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center bg-white border-primary border">
                        <div class="card-body text-primary">
                            <h3><i class="fas fa-hotel"></i></h3>
                            <h5 class="card-title">Hotels</h5>
                            <p class="card-text fs-4"><?php echo $hotel_count; ?> Listed</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center text-white bg-info">
                        <div class="card-body">
                            <h3><i class="fas fa-users"></i></h3>
                            <h5 class="card-title">Total Athletes</h5>
                            <p class="card-text fs-4">0</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center bg-white border-info border">
                        <div class="card-body text-info">
                            <h3><i class="fas fa-file-invoice-dollar"></i></h3>
                            <h5 class="card-title">Total Bookings</h5>
                            <p class="card-text fs-4">0</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Championships Management Section -->
            <div class="card mt-4" id="championships">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Manage Championships</h5>
                    <button class="btn btn-light btn-sm text-primary fw-bold" data-bs-toggle="modal" data-bs-target="#addChampionshipModal">
                        <i class="fas fa-plus"></i> Add New
                    </button>
                </div>
                <div class="card-body">
                    <?php if (count($championships) > 0): ?>
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Dates</th>
                                    <th>Location</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($championships as $c): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($c['id']); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($c['title']); ?></td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($c['start_date'])) . ' - ' . date('M d, Y', strtotime($c['end_date'])); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['location']); ?></td>
                                    <td>
                                        <!-- Edit Button -->
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $c['id']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <!-- Delete Button / Form -->
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this championship?');">
                                            <input type="hidden" name="action" value="delete_championship">
                                            <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>

                                        <!-- Edit Modal specific to this record -->
                                        <div class="modal fade" id="editModal<?php echo $c['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header bg-primary text-white">
                                                            <h5 class="modal-title">Edit Championship</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="action" value="edit_championship">
                                                            <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label text-start d-block">Title</label>
                                                                <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($c['title']); ?>" required>
                                                            </div>
                                                            <div class="row text-start">
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label">Start Date</label>
                                                                    <input type="date" name="start_date" class="form-control" value="<?php echo $c['start_date']; ?>" required>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label">End Date</label>
                                                                    <input type="date" name="end_date" class="form-control" value="<?php echo $c['end_date']; ?>" required>
                                                                </div>
                                                            </div>
                                                            <div class="mb-3 text-start">
                                                                <label class="form-label">Location</label>
                                                                <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($c['location']); ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info text-center">No championships have been created yet. Click "Add New" to start.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Hotels Management Section -->
            <div class="card mt-4" id="hotels">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Manage Hotels</h5>
                    <button class="btn btn-light btn-sm text-secondary fw-bold" data-bs-toggle="modal" data-bs-target="#addHotelModal">
                        <i class="fas fa-plus"></i> Add Hotel
                    </button>
                </div>
                <div class="card-body">
                    <?php if (count($hotels) > 0): ?>
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Hotel Name</th>
                                    <th>Address</th>
                                    <th>Total Rooms</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hotels as $h): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($h['id']); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($h['name']); ?></td>
                                    <td><?php echo htmlspecialchars($h['address']); ?></td>
                                    <td><?php echo htmlspecialchars($h['total_rooms']); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this hotel?');">
                                            <input type="hidden" name="action" value="delete_hotel">
                                            <input type="hidden" name="id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info text-center">No hotels found. Click "Add Hotel" to start.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Room Types Management Section (Pricing) -->
            <div class="card mt-4" id="room_types">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Room Types & Pricing</h5>
                    <button class="btn btn-light btn-sm text-success fw-bold" data-bs-toggle="modal" data-bs-target="#addRoomTypeModal">
                        <i class="fas fa-plus"></i> Add Room Type
                    </button>
                </div>
                <div class="card-body">
                    <?php if (count($room_types) > 0): ?>
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Hotel Name</th>
                                    <th>Room Type</th>
                                    <th>Capacity</th>
                                    <th>Price / Night ($)</th>
                                    <th>Allotment</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($room_types as $rt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($rt['hotel_name']); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($rt['name']); ?></td>
                                    <td><?php echo htmlspecialchars($rt['capacity']); ?> Persons</td>
                                    <td class="text-success fw-bold">$<?php echo number_format($rt['price_per_night'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($rt['total_allotment']); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this room type?');">
                                            <input type="hidden" name="action" value="delete_room_type">
                                            <input type="hidden" name="id" value="<?php echo $rt['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info text-center">No room types defined yet. Click "Add Room Type" to set pricing.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ADD New Championship Modal -->
<div class="modal fade" id="addChampionshipModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Championship</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_championship">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Championship Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g., World Deaf Athletics 2024" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">Start Date</label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">End Date</label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Location</label>
                        <input type="text" name="location" class="form-control" placeholder="City, Country" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Championship</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ADD Hotel Modal -->
<div class="modal fade" id="addHotelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title">Add New Hotel</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_hotel">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Hotel Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Complete Address</label>
                        <textarea name="address" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Total Rooms Available</label>
                        <input type="number" name="total_rooms" class="form-control" min="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save Hotel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ADD Room Type Modal -->
<div class="modal fade" id="addRoomTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Add Room Type & Pricing</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_room_type">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Select Hotel</label>
                        <select name="hotel_id" class="form-select" required>
                            <option value="">-- Choose Hotel --</option>
                            <?php foreach($hotels as $h): ?>
                                <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">Room Type Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Double" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">Capacity</label>
                            <input type="number" name="capacity" class="form-control" placeholder="Persons per room" min="1" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">Price per Night ($)</label>
                            <input type="number" step="0.01" name="price_per_night" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">Allotment</label>
                            <input type="number" name="total_allotment" class="form-control" placeholder="No. of rooms" min="1" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Pricing</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>