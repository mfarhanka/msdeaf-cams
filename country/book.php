<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'assign_room') {
        $athleteId = intval($_POST['athlete_id'] ?? 0);
        $roomTypeId = intval($_POST['room_type_id'] ?? 0);
        $championshipId = intval($_POST['championship_id'] ?? 0);

        if ($athleteId && $roomTypeId && $championshipId) {
            $athleteCheck = $pdo->prepare("SELECT id FROM athletes WHERE id = ? AND country_id = ?");
            $athleteCheck->execute([$athleteId, $countryId]);
            if ($athleteCheck->fetch()) {
                $roomTypeCheck = $pdo->prepare("SELECT hotel_id, total_allotment FROM room_types WHERE id = ?");
                $roomTypeCheck->execute([$roomTypeId]);
                $roomTypeInfo = $roomTypeCheck->fetch(PDO::FETCH_ASSOC);
                if ($roomTypeInfo) {
                    $assignedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM room_assignments ra JOIN bookings bb ON ra.booking_id = bb.id WHERE bb.room_type_id = ? AND bb.status <> 'Cancelled'");
                    $assignedCountStmt->execute([$roomTypeId]);
                    $assignedCount = intval($assignedCountStmt->fetchColumn());
                    $availableRooms = intval($roomTypeInfo['total_allotment']) - $assignedCount;

                    if ($availableRooms <= 0) {
                        $msg = "<div class='alert alert-warning'>No available rooms left for the selected hotel room type.</div>";
                    } else {
                        $bookingCheck = $pdo->prepare("SELECT b.id, b.rooms_reserved, COALESCE(COUNT(ra.id), 0) AS assigned_rooms
                            FROM bookings b
                            LEFT JOIN room_assignments ra ON ra.booking_id = b.id
                            WHERE b.country_id = ? AND b.championship_id = ? AND b.room_type_id = ? AND b.status <> 'Cancelled'
                            GROUP BY b.id
                            ORDER BY b.id ASC
                            LIMIT 1");
                        $bookingCheck->execute([$countryId, $championshipId, $roomTypeId]);
                        $bookingInfo = $bookingCheck->fetch(PDO::FETCH_ASSOC);

                        if ($bookingInfo) {
                            if (intval($bookingInfo['assigned_rooms']) >= intval($bookingInfo['rooms_reserved'])) {
                                $updateRooms = $pdo->prepare("UPDATE bookings SET rooms_reserved = rooms_reserved + 1 WHERE id = ?");
                                $updateRooms->execute([$bookingInfo['id']]);
                            }
                            $bookingId = $bookingInfo['id'];
                        } else {
                            $insertBooking = $pdo->prepare("INSERT INTO bookings (championship_id, country_id, hotel_id, room_type_id, rooms_reserved, status) VALUES (?, ?, ?, ?, 1, 'Pending')");
                            $insertBooking->execute([$championshipId, $countryId, $roomTypeInfo['hotel_id'], $roomTypeId]);
                            $bookingId = $pdo->lastInsertId();
                        }

                        $assignedStmt = $pdo->prepare("SELECT room_number FROM room_assignments WHERE booking_id = ?");
                        $assignedStmt->execute([$bookingId]);
                        $assignedRooms = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);

                        $roomNumber = '';
                        for ($i = 1; $i <= intval($roomTypeInfo['total_allotment']); $i++) {
                            $candidate = 'Room ' . $i;
                            if (!in_array($candidate, $assignedRooms, true)) {
                                $roomNumber = $candidate;
                                break;
                            }
                        }

                        if (empty($roomNumber)) {
                            $msg = "<div class='alert alert-warning'>No available room numbers left for this room type.</div>";
                        } else {
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
                        }
                    }
                } else {
                    $msg = "<div class='alert alert-danger'>Invalid room type selection.</div>";
                }
            } else {
                $msg = "<div class='alert alert-danger'>Invalid athlete selection.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please select athlete, championship, and hotel room type.</div>";
        }
    } elseif ($_POST['action'] === 'unassign_room') {
        $athleteId = intval($_POST['athlete_id'] ?? 0);

        if ($athleteId) {
            // Check if athlete belongs to this country
            $athleteCheck = $pdo->prepare("SELECT id FROM athletes WHERE id = ? AND country_id = ?");
            $athleteCheck->execute([$athleteId, $countryId]);
            if ($athleteCheck->fetch()) {
                $deleteStmt = $pdo->prepare("DELETE FROM room_assignments WHERE athlete_id = ?");
                if ($deleteStmt->execute([$athleteId])) {
                    $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-times-circle'></i> Room unassigned successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $msg = "<div class='alert alert-danger'>Failed to unassign room.</div>";
                }
            } else {
                $msg = "<div class='alert alert-danger'>Invalid athlete selection.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please select an athlete to unassign.</div>";
        }
    }
}

// Get athletes for this country
$athletesStmt = $pdo->prepare("SELECT a.*, ra.room_number, ra.booking_id, b.room_type_id AS current_room_type_id, b.championship_id AS current_championship_id
    FROM athletes a
    LEFT JOIN room_assignments ra ON a.id = ra.athlete_id
    LEFT JOIN bookings b ON ra.booking_id = b.id
    WHERE a.country_id = ?
    ORDER BY a.last_name ASC, a.first_name ASC");
$athletesStmt->execute([$countryId]);
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get bookings for this country with room availability
// Get current country bookings (for status summary)
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

// Get championship list for room assignment selection.
$championships = $pdo->query("SELECT * FROM championships ORDER BY start_date ASC")->fetchAll(PDO::FETCH_ASSOC);
 
// Get admin-registered room types and availability based on assigned rooms.
$roomTypesStmt = $pdo->prepare("SELECT rt.*, h.name AS hotel_name,
    COALESCE((SELECT COUNT(*) FROM room_assignments ra
        JOIN bookings bb ON ra.booking_id = bb.id
        WHERE bb.room_type_id = rt.id AND bb.status <> 'Cancelled'), 0) AS assigned_rooms
    FROM room_types rt
    JOIN hotels h ON rt.hotel_id = h.id
    ORDER BY h.name ASC, rt.name ASC");
$roomTypesStmt->execute();
$roomTypes = $roomTypesStmt->fetchAll(PDO::FETCH_ASSOC);

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
                                        <?php if ($athlete['room_number']): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to unassign this room?');">
                                                <input type="hidden" name="action" value="unassign_room">
                                                <input type="hidden" name="athlete_id" value="<?php echo $athlete['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger ms-1">
                                                    <i class="fas fa-times"></i> Unassign
                                                </button>
                                            </form>
                                        <?php endif; ?>
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
                <h5 class="card-title">Admin Room Types</h5>
                <?php if (count($roomTypes) > 0): ?>
                    <?php foreach ($roomTypes as $rt): ?>
                        <?php $available = intval($rt['total_allotment']) - intval($rt['assigned_rooms']); ?>
                        <div class="mb-3 p-3 border rounded">
                            <h6 class="mb-2"><?php echo htmlspecialchars($rt['hotel_name']); ?> - <?php echo htmlspecialchars($rt['name']); ?></h6>
                            <div class="text-muted small mb-2">Capacity: <?php echo htmlspecialchars($rt['capacity']); ?> person(s)</div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small><?php echo $available; ?> available</small>
                                <small>$<?php echo number_format($rt['price_per_night'], 2); ?>/night</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted mb-0">No hotel room types registered by admin yet.</p>
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
                        <label class="form-label">Select Championship</label>
                        <select name="championship_id" class="form-select" required>
                            <option value="">-- Choose Championship --</option>
                            <?php foreach ($championships as $champ): ?>
                                <option value="<?php echo $champ['id']; ?>" <?php echo $athlete['current_championship_id'] === $champ['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($champ['title'] . ' (' . date('M d', strtotime($champ['start_date'])) . ' - ' . date('M d', strtotime($champ['end_date'])) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Select Hotel Room Type</label>
                        <select name="room_type_id" class="form-select" required>
                            <option value="">-- Choose Room Type --</option>
                            <?php foreach ($roomTypes as $rt): ?>
                                <?php $available = intval($rt['total_allotment']) - intval($rt['assigned_rooms']); ?>
                                <option value="<?php echo $rt['id']; ?>" <?php echo $available <= 0 ? 'disabled' : ''; ?> <?php echo $athlete['current_room_type_id'] === $rt['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rt['hotel_name'] . ' / ' . $rt['name'] . ' (' . $rt['capacity'] . 'p) — ' . $available . ' available)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="alert alert-info">
                        Room numbers are assigned automatically based on availability.
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