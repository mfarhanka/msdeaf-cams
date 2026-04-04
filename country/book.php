<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'assign_room') {
        $athleteId = intval($_POST['athlete_id'] ?? 0);
        $bookingId = intval($_POST['booking_id'] ?? 0);
        $roomNumber = trim($_POST['room_number'] ?? '');

        if ($athleteId && $bookingId && !empty($roomNumber)) {
            // Check if athlete belongs to this country
            $athleteCheck = $pdo->prepare("SELECT id FROM athletes WHERE id = ? AND country_id = ?");
            $athleteCheck->execute([$athleteId, $countryId]);
            if ($athleteCheck->fetch()) {
                // Check if booking belongs to this country
                $bookingCheck = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND country_id = ?");
                $bookingCheck->execute([$bookingId, $countryId]);
                if ($bookingCheck->fetch()) {
                    // Check if room assignment already exists
                    $existing = $pdo->prepare("SELECT id FROM room_assignments WHERE athlete_id = ?");
                    $existing->execute([$athleteId]);
                    if ($existing->fetch()) {
                        $updateStmt = $pdo->prepare("UPDATE room_assignments SET booking_id = ?, room_number = ? WHERE athlete_id = ?");
                        $updateStmt->execute([$bookingId, $roomNumber, $athleteId]);
                    } else {
                        $insertStmt = $pdo->prepare("INSERT INTO room_assignments (booking_id, room_number, athlete_id) VALUES (?, ?, ?)");
                        $insertStmt->execute([$bookingId, $roomNumber, $athleteId]);
                    }
                    $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle'></i> Room assigned successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $msg = "<div class='alert alert-danger'>Invalid booking selection.</div>";
                }
            } else {
                $msg = "<div class='alert alert-danger'>Invalid athlete selection.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please select athlete, booking, and room number.</div>";
        }
    }
}

// Get athletes for this country
$athletesStmt = $pdo->prepare("SELECT a.*, ra.room_number, ra.booking_id
    FROM athletes a
    LEFT JOIN room_assignments ra ON a.id = ra.athlete_id
    WHERE a.country_id = ?
    ORDER BY a.last_name ASC, a.first_name ASC");
$athletesStmt->execute([$countryId]);
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get bookings for this country with room availability
$bookingsStmt = $pdo->prepare("SELECT b.*, c.title AS championship_title, h.name AS hotel_name, rt.name AS room_type_name,
        rt.capacity, rt.price_per_night, b.rooms_reserved,
        (SELECT COUNT(*) FROM room_assignments ra WHERE ra.booking_id = b.id) AS assigned_rooms
    FROM bookings b
    JOIN championships c ON b.championship_id = c.id
    JOIN hotels h ON b.hotel_id = h.id
    JOIN room_types rt ON b.room_type_id = rt.id
    WHERE b.country_id = ? AND b.status <> 'Cancelled'
    ORDER BY c.start_date ASC");
$bookingsStmt->execute([$countryId]);
$bookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Room Assignments</h1>
</div>

<?php if (!empty($msg)) echo $msg; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title">Registered Athletes</h5>
                <?php if (count($athletes) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Gender</th>
                                    <th>Current Room</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($athletes as $athlete): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($athlete['gender']); ?></td>
                                    <td>
                                        <?php if ($athlete['room_number']): ?>
                                            <span class="badge bg-success"><?php echo htmlspecialchars($athlete['room_number']); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo $athlete['id']; ?>">
                                            <i class="fas fa-bed"></i> Assign Room
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No athletes registered yet. Add athletes first.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title">Available Rooms</h5>
                <?php if (count($bookings) > 0): ?>
                    <?php foreach ($bookings as $booking): ?>
                        <div class="mb-3 p-3 border rounded">
                            <h6 class="mb-2"><?php echo htmlspecialchars($booking['hotel_name']); ?> - <?php echo htmlspecialchars($booking['room_type_name']); ?></h6>
                            <small class="text-muted d-block"><?php echo htmlspecialchars($booking['championship_title']); ?></small>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small>Rooms: <?php echo $booking['assigned_rooms']; ?>/<?php echo $booking['rooms_reserved']; ?> assigned</small>
                                <small>$<?php echo number_format($booking['price_per_night'], 2); ?>/night</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted mb-0">No bookings yet. Book accommodation first.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php foreach ($athletes as $athlete): ?>
<div class="modal fade" id="assignModal<?php echo $athlete['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Assign Room - <?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign_room">
                    <input type="hidden" name="athlete_id" value="<?php echo $athlete['id']; ?>">

                    <div class="mb-3">
                        <label class="form-label">Select Booking</label>
                        <select name="booking_id" class="form-select" required>
                            <option value="">-- Choose Booking --</option>
                            <?php foreach ($bookings as $booking): ?>
                                <?php $available = $booking['rooms_reserved'] - $booking['assigned_rooms']; ?>
                                <option value="<?php echo $booking['id']; ?>" <?php echo $available <= 0 ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($booking['hotel_name'] . ' - ' . $booking['room_type_name'] . ' (' . $available . ' rooms available)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Room Number</label>
                        <input type="text" name="room_number" class="form-control" placeholder="e.g., 101, 102A" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign Room</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
require_once 'includes/footer.php';
?>