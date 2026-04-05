<?php
require_once 'includes/auth.php';

// Handle POST actions for Hotels & Room Types
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_hotel') {
        $name = trim($_POST['name']);
        $address = trim($_POST['address']);
        
        $stmt = $pdo->prepare("INSERT INTO hotels (name, address, total_rooms) VALUES (?, ?, 0)");
        if ($stmt->execute([$name, $address])) {
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
            // Update actual hotel total_rooms column
            $pdo->prepare("UPDATE hotels SET total_rooms = (SELECT COALESCE(SUM(total_allotment), 0) FROM room_types WHERE hotel_id = ?) WHERE id = ?")->execute([$hotel_id, $hotel_id]);
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-bed'></i> Room Type added!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'delete_room_type') {
        $id = $_POST['id'];
        // get hotel_id before deleting
        $h_stmt = $pdo->prepare("SELECT hotel_id FROM room_types WHERE id=?");
        $h_stmt->execute([$id]);
        $hotel_id = $h_stmt->fetchColumn();

        $stmt = $pdo->prepare("DELETE FROM room_types WHERE id=?");
        if ($stmt->execute([$id])) {
            // Update actual hotel total_rooms column
            $pdo->prepare("UPDATE hotels SET total_rooms = (SELECT COALESCE(SUM(total_allotment), 0) FROM room_types WHERE hotel_id = ?) WHERE id = ?")->execute([$hotel_id, $hotel_id]);
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Room type deleted!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// Fetch all hotels and dynamically calculate total rooms from room types
$hotels_stmt = $pdo->query("
    SELECT h.id, h.name, h.address, COALESCE(SUM(rt.total_allotment), 0) AS calculated_total_rooms 
    FROM hotels h 
    LEFT JOIN room_types rt ON h.id = rt.hotel_id 
    GROUP BY h.id, h.name, h.address 
    ORDER BY h.id DESC
");
$hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedHotelId = isset($_GET['hotel_id']) ? (int) $_GET['hotel_id'] : 0;
$selectedHotelName = 'All Hotels';

if ($selectedHotelId > 0) {
    foreach ($hotels as $hotel) {
        if ((int) $hotel['id'] === $selectedHotelId) {
            $selectedHotelName = $hotel['name'];
            break;
        }
    }
}

if ($selectedHotelId > 0) {
    $room_types_stmt = $pdo->prepare("SELECT r.*, h.name as hotel_name FROM room_types r JOIN hotels h ON r.hotel_id = h.id WHERE r.hotel_id = ? ORDER BY r.name ASC");
    $room_types_stmt->execute([$selectedHotelId]);
} else {
    $room_types_stmt = $pdo->query("SELECT r.*, h.name as hotel_name FROM room_types r JOIN hotels h ON r.hotel_id = h.id ORDER BY h.name ASC, r.name ASC");
}
$room_types = $room_types_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Hotels & Pricing Management</h1>
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
                        <td><?php echo htmlspecialchars($h['calculated_total_rooms']); ?></td>
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
        <form method="GET" class="row g-2 align-items-end mb-3">
            <div class="col-md-6 col-lg-4">
                <label for="roomTypeHotelFilter" class="form-label text-muted fw-bold">Show Pricing For Hotel</label>
                <select id="roomTypeHotelFilter" name="hotel_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">All Hotels</option>
                    <?php foreach ($hotels as $hotel): ?>
                        <option value="<?php echo (int) $hotel['id']; ?>" <?php echo $selectedHotelId === (int) $hotel['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($hotel['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-lg-8">
                <div class="text-muted small">Currently showing: <span class="fw-semibold"><?php echo htmlspecialchars($selectedHotelName); ?></span></div>
            </div>
        </form>

        <?php if (count($room_types) > 0): ?>
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Hotel Name</th>
                        <th>Room Type</th>
                        <th>Capacity</th>
                        <th>Price / Pax / Day ($)</th>
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
            <div class="alert alert-info text-center">No room types found for the selected hotel.</div>
        <?php endif; ?>
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
                            <label class="form-label text-muted fw-bold">Price per Pax per Day ($)</label>
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

<?php
require_once 'includes/footer.php';
?>