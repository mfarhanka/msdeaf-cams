<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'assign_room') {
        $athleteId = (int) ($_POST['athlete_id'] ?? 0);
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $roomGrouping = trim((string) ($_POST['room_grouping'] ?? ''));

        if ($athleteId <= 0 || $bookingId <= 0 || $roomGrouping === '') {
            $msg = "<div class='alert alert-warning'>Please select a delegate member, reservation, and room grouping.</div>";
        } else {
            $athleteStmt = $pdo->prepare("SELECT id FROM athletes WHERE id = ? AND country_id = ?");
            $athleteStmt->execute([$athleteId, $countryId]);
            $athlete = $athleteStmt->fetch(PDO::FETCH_ASSOC);

            $bookingStmt = $pdo->prepare("SELECT b.id, b.rooms_reserved, rt.capacity
                FROM bookings b
                JOIN room_types rt ON rt.id = b.room_type_id
                WHERE b.id = ? AND b.country_id = ? AND b.status <> 'Cancelled'
                LIMIT 1");
            $bookingStmt->execute([$bookingId, $countryId]);
            $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$athlete) {
                $msg = "<div class='alert alert-danger'>Invalid delegate selection.</div>";
            } elseif (!$booking) {
                $msg = "<div class='alert alert-danger'>Invalid reservation selection.</div>";
            } else {
                $capacity = max(1, (int) $booking['capacity']);
                $reservedRooms = max(0, (int) $booking['rooms_reserved']);
                $capacityLimit = $reservedRooms * $capacity;

                $existingAssignmentStmt = $pdo->prepare("SELECT booking_id, room_number FROM room_assignments WHERE athlete_id = ? LIMIT 1");
                $existingAssignmentStmt->execute([$athleteId]);
                $existingAssignment = $existingAssignmentStmt->fetch(PDO::FETCH_ASSOC);

                $assignedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM room_assignments WHERE booking_id = ?");
                $assignedCountStmt->execute([$bookingId]);
                $assignedCount = (int) $assignedCountStmt->fetchColumn();

                if ((!$existingAssignment || (int) $existingAssignment['booking_id'] !== $bookingId) && $assignedCount >= $capacityLimit) {
                    $msg = "<div class='alert alert-warning'>This reservation is already at full athlete capacity.</div>";
                } else {
                    $occupancyStmt = $pdo->prepare("SELECT room_number, COUNT(*) AS occupants FROM room_assignments WHERE booking_id = ? GROUP BY room_number");
                    $occupancyStmt->execute([$bookingId]);
                    $roomOccupancy = [];
                    foreach ($occupancyStmt->fetchAll(PDO::FETCH_ASSOC) as $occupancyRow) {
                        $roomOccupancy[$occupancyRow['room_number']] = (int) $occupancyRow['occupants'];
                    }

                    $currentRoomNumber = $existingAssignment && (int) $existingAssignment['booking_id'] === $bookingId
                        ? trim((string) $existingAssignment['room_number'])
                        : '';
                    $targetRoomNumber = '';

                    if ($roomGrouping === '__new__') {
                        for ($index = 1; $index <= $reservedRooms; $index++) {
                            $candidate = 'Room ' . $index;
                            if (!isset($roomOccupancy[$candidate]) || $roomOccupancy[$candidate] === 0) {
                                $targetRoomNumber = $candidate;
                                break;
                            }
                        }

                        if ($targetRoomNumber === '') {
                            $msg = "<div class='alert alert-warning'>No empty reserved room group is available. Choose an existing room group instead.</div>";
                        }
                    } else {
                        $selectedOccupants = $roomOccupancy[$roomGrouping] ?? null;
                        $isCurrentRoomSelection = $currentRoomNumber !== '' && $currentRoomNumber === $roomGrouping;
                        $isReservedRoomName = preg_match('/^Room\s+(\d+)$/', $roomGrouping, $roomMatch) === 1;
                        $selectedRoomIndex = $isReservedRoomName ? (int) $roomMatch[1] : 0;
                        $isEmptyReservedRoom = $isReservedRoomName
                            && $selectedRoomIndex >= 1
                            && $selectedRoomIndex <= $reservedRooms
                            && $selectedOccupants === null;

                        if ($selectedOccupants === null && !$isEmptyReservedRoom) {
                            $msg = "<div class='alert alert-warning'>The selected room group is no longer available.</div>";
                        } elseif ($selectedOccupants >= $capacity && !$isCurrentRoomSelection) {
                            $msg = "<div class='alert alert-warning'>The selected room group is already full.</div>";
                        } else {
                            $targetRoomNumber = $roomGrouping;
                        }
                    }

                    if ($targetRoomNumber !== '') {
                        if ($existingAssignment) {
                            $updateStmt = $pdo->prepare("UPDATE room_assignments SET booking_id = ?, room_number = ? WHERE athlete_id = ?");
                            $updateStmt->execute([$bookingId, $targetRoomNumber, $athleteId]);
                        } else {
                            $insertStmt = $pdo->prepare("INSERT INTO room_assignments (booking_id, room_number, athlete_id) VALUES (?, ?, ?)");
                            $insertStmt->execute([$bookingId, $targetRoomNumber, $athleteId]);
                        }

                        $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-check-circle me-1'></i>Delegate member assigned to room group successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    }
                }
            }
        }
    } elseif ($_POST['action'] === 'unassign_room') {
        $athleteId = (int) ($_POST['athlete_id'] ?? 0);
        if ($athleteId <= 0) {
            $msg = "<div class='alert alert-warning'>Please select a delegate member to unassign.</div>";
        } else {
            $deleteStmt = $pdo->prepare("DELETE ra FROM room_assignments ra JOIN athletes a ON a.id = ra.athlete_id WHERE ra.athlete_id = ? AND a.country_id = ?");
            $deleteStmt->execute([$athleteId, $countryId]);
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-x-circle me-1'></i>Room assignment removed successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

$athletesStmt = $pdo->prepare("SELECT a.id, a.first_name, a.last_name, a.gender,
    ra.room_number,
    b.id AS booking_id,
    c.title AS championship_title,
    h.name AS hotel_name,
    rt.name AS room_type_name
    FROM athletes a
    LEFT JOIN room_assignments ra ON ra.athlete_id = a.id
    LEFT JOIN bookings b ON b.id = ra.booking_id
    LEFT JOIN championships c ON c.id = b.championship_id
    LEFT JOIN hotels h ON h.id = b.hotel_id
    LEFT JOIN room_types rt ON rt.id = b.room_type_id
    WHERE a.country_id = ?
    ORDER BY a.last_name ASC, a.first_name ASC");
$athletesStmt->execute([$countryId]);
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);

$reservationsStmt = $pdo->prepare("SELECT b.id, b.rooms_reserved, b.booking_start_date, b.booking_end_date, c.title AS championship_title,
    h.name AS hotel_name, rt.name AS room_type_name, rt.capacity, rt.price_per_night,
    (SELECT COUNT(*) FROM room_assignments ra WHERE ra.booking_id = b.id) AS assigned_athletes,
    (SELECT COUNT(DISTINCT ra.room_number) FROM room_assignments ra WHERE ra.booking_id = b.id AND ra.room_number IS NOT NULL AND ra.room_number <> '') AS used_room_groups
    FROM bookings b
    JOIN championships c ON c.id = b.championship_id
    JOIN hotels h ON h.id = b.hotel_id
    JOIN room_types rt ON rt.id = b.room_type_id
    WHERE b.country_id = ? AND b.status <> 'Cancelled' AND b.rooms_reserved > 0
    ORDER BY c.start_date ASC, h.name ASC, rt.name ASC");
$reservationsStmt->execute([$countryId]);
$reservations = $reservationsStmt->fetchAll(PDO::FETCH_ASSOC);

$roomingRowsStmt = $pdo->prepare("SELECT
    b.id AS booking_id,
    c.title AS championship_title,
    h.name AS hotel_name,
    rt.name AS room_type_name,
    rt.capacity,
    ra.room_number,
    a.id AS athlete_id,
    a.first_name,
    a.last_name,
    a.gender
    FROM room_assignments ra
    JOIN bookings b ON b.id = ra.booking_id
    JOIN athletes a ON a.id = ra.athlete_id
    JOIN championships c ON c.id = b.championship_id
    JOIN hotels h ON h.id = b.hotel_id
    JOIN room_types rt ON rt.id = b.room_type_id
    WHERE b.country_id = ?
        AND b.status <> 'Cancelled'
        AND ra.room_number IS NOT NULL
        AND ra.room_number <> ''
    ORDER BY c.start_date ASC, h.name ASC, rt.name ASC, ra.room_number ASC, a.last_name ASC, a.first_name ASC");
$roomingRowsStmt->execute([$countryId]);
$roomingRows = $roomingRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$roomGroups = [];
$roomGroupPreview = [];
foreach ($roomingRows as $roomingRow) {
    $groupKey = $roomingRow['booking_id'] . '|' . $roomingRow['room_number'];
    if (!isset($roomGroups[$groupKey])) {
        $roomGroups[$groupKey] = [
            'booking_id' => (int) $roomingRow['booking_id'],
            'championship_title' => $roomingRow['championship_title'],
            'hotel_name' => $roomingRow['hotel_name'],
            'room_type_name' => $roomingRow['room_type_name'],
            'capacity' => (int) $roomingRow['capacity'],
            'room_number' => $roomingRow['room_number'],
            'occupants' => [],
        ];
    }

    $occupant = [
        'athlete_id' => (int) $roomingRow['athlete_id'],
        'name' => trim($roomingRow['first_name'] . ' ' . $roomingRow['last_name']),
        'gender' => $roomingRow['gender'],
    ];
    $roomGroups[$groupKey]['occupants'][] = $occupant;

    $bookingIdKey = (string) $roomingRow['booking_id'];
    if (!isset($roomGroupPreview[$bookingIdKey])) {
        $roomGroupPreview[$bookingIdKey] = [];
    }
    if (!isset($roomGroupPreview[$bookingIdKey][$roomingRow['room_number']])) {
        $roomGroupPreview[$bookingIdKey][$roomingRow['room_number']] = [
            'room_number' => $roomingRow['room_number'],
            'occupants' => [],
        ];
    }
    $roomGroupPreview[$bookingIdKey][$roomingRow['room_number']]['occupants'][] = [
        'name' => $occupant['name'],
        'gender' => $occupant['gender'],
    ];
}

foreach ($roomGroupPreview as $bookingIdKey => $roomGroupsForBooking) {
    $roomGroupPreview[$bookingIdKey] = array_values($roomGroupsForBooking);
}

$reservationOptions = [];
foreach ($reservations as $reservation) {
    $capacity = max(1, (int) $reservation['capacity']);
    $totalCapacity = (int) $reservation['rooms_reserved'] * $capacity;
    $reservationOptions[] = [
        'id' => (int) $reservation['id'],
        'championship_title' => $reservation['championship_title'],
        'hotel_name' => $reservation['hotel_name'],
        'room_type_name' => $reservation['room_type_name'],
        'capacity' => $capacity,
        'rooms_reserved' => (int) $reservation['rooms_reserved'],
        'assigned_athletes' => (int) $reservation['assigned_athletes'],
        'used_room_groups' => (int) $reservation['used_room_groups'],
        'remaining_slots' => max(0, $totalCapacity - (int) $reservation['assigned_athletes']),
        'empty_room_groups' => max(0, (int) $reservation['rooms_reserved'] - (int) $reservation['used_room_groups']),
        'price_per_night' => (float) $reservation['price_per_night'],
    ];
}

$roomCards = [];
foreach ($reservations as $reservation) {
    $bookingId = (int) $reservation['id'];
    $capacity = max(1, (int) $reservation['capacity']);

    for ($roomIndex = 1; $roomIndex <= (int) $reservation['rooms_reserved']; $roomIndex++) {
        $roomNumber = 'Room ' . $roomIndex;
        $roomKey = $bookingId . '|' . $roomNumber;
        $occupants = [];

        if (isset($roomGroups[$roomKey])) {
            $occupants = $roomGroups[$roomKey]['occupants'];
        }

        $roomCards[] = [
            'booking_id' => $bookingId,
            'room_number' => $roomNumber,
            'championship_title' => $reservation['championship_title'],
            'booking_start_date' => $reservation['booking_start_date'],
            'booking_end_date' => $reservation['booking_end_date'],
            'hotel_name' => $reservation['hotel_name'],
            'room_type_name' => $reservation['room_type_name'],
            'capacity' => $capacity,
            'occupants' => $occupants,
            'is_full' => count($occupants) >= $capacity,
        ];
    }
}

$unassignedAthletes = [];
foreach ($athletes as $athlete) {
    if (!empty($athlete['booking_id']) || !empty($athlete['room_number'])) {
        continue;
    }

    $unassignedAthletes[] = [
        'id' => (int) $athlete['id'],
        'name' => trim((string) $athlete['first_name'] . ' ' . (string) $athlete['last_name']),
        'gender' => (string) $athlete['gender'],
    ];
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Room Grouping</h1>
        <p class="text-muted mb-0">All booked rooms are shown below. Pick an unassigned delegate inside each room box.</p>
    </div>
    <a href="book.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-calendar-check me-1"></i> Manage Reservations
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title mb-2">Available Delegates</h5>
                <p class="text-muted small mb-3">Once assigned to a room, a delegate disappears from all other room selection lists.</p>
                <?php if (count($unassignedAthletes) > 0): ?>
                    <div class="d-grid gap-2">
                        <?php foreach ($unassignedAthletes as $athlete): ?>
                            <div class="border rounded p-2 d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($athlete['name']); ?></span>
                                <span class="text-muted small"><?php echo htmlspecialchars($athlete['gender']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (count($athletes) === 0): ?>
                    <p class="text-muted mb-0">No delegates found. Add delegates first in Athletes & Officials.</p>
                <?php else: ?>
                    <p class="text-muted mb-0">All delegates are already assigned to rooms.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title mb-1">Booking Snapshot</h5>
                <p class="text-muted small mb-3">Quick reference for room inventory and remaining slots.</p>
                <?php if (count($reservationOptions) > 0): ?>
                    <div class="d-grid gap-2">
                        <?php foreach ($reservationOptions as $reservationOption): ?>
                            <div class="border rounded p-2">
                                <div class="fw-semibold"><?php echo htmlspecialchars($reservationOption['hotel_name']); ?> / <?php echo htmlspecialchars($reservationOption['room_type_name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($reservationOption['championship_title']); ?></div>
                                <div class="small mt-1">Rooms: <?php echo (int) $reservationOption['rooms_reserved']; ?> | Slots left: <?php echo (int) $reservationOption['remaining_slots']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No active reservations yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h5 class="card-title mb-3">Booked Rooms</h5>
        <?php if (count($roomCards) > 0): ?>
            <div class="row g-3">
                <?php foreach ($roomCards as $roomCard): ?>
                    <div class="col-lg-6">
                        <div class="border rounded p-3 h-100">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($roomCard['hotel_name']); ?> - <?php echo htmlspecialchars($roomCard['room_number']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($roomCard['room_type_name']); ?> (<?php echo (int) $roomCard['capacity']; ?> pax/room)</div>
                                </div>
                                <span class="badge <?php echo $roomCard['is_full'] ? 'text-bg-danger' : 'text-bg-primary'; ?>"><?php echo count($roomCard['occupants']); ?> / <?php echo (int) $roomCard['capacity']; ?> Pax</span>
                            </div>
                            <div class="small text-muted"><?php echo htmlspecialchars($roomCard['championship_title']); ?></div>
                            <div class="small text-muted mb-3">Booked stay: <?php echo htmlspecialchars(date('M d, Y', strtotime($roomCard['booking_start_date'])) . ' - ' . date('M d, Y', strtotime($roomCard['booking_end_date']))); ?></div>

                            <div class="small fw-semibold mb-2">Assigned Delegates</div>
                            <?php if (count($roomCard['occupants']) > 0): ?>
                                <ul class="list-group list-group-flush mb-3">
                                    <?php foreach ($roomCard['occupants'] as $occupant): ?>
                                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                                            <div>
                                                <div><?php echo htmlspecialchars($occupant['name']); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($occupant['gender']); ?></div>
                                            </div>
                                            <form method="POST" onsubmit="return confirm('Unassign this delegate from <?php echo htmlspecialchars($roomCard['room_number']); ?>?');">
                                                <input type="hidden" name="action" value="unassign_room">
                                                <input type="hidden" name="athlete_id" value="<?php echo (int) $occupant['athlete_id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted small mb-3">No delegates assigned to this room yet.</p>
                            <?php endif; ?>

                            <?php if (!$roomCard['is_full']): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="assign_room">
                                    <input type="hidden" name="booking_id" value="<?php echo (int) $roomCard['booking_id']; ?>">
                                    <input type="hidden" name="room_grouping" value="<?php echo htmlspecialchars($roomCard['room_number']); ?>">

                                    <label class="form-label small fw-semibold" for="delegate-<?php echo (int) $roomCard['booking_id']; ?>-<?php echo (int) preg_replace('/\D+/', '', $roomCard['room_number']); ?>">Add Delegate</label>
                                    <div class="d-flex gap-2">
                                        <select
                                            class="form-select form-select-sm"
                                            id="delegate-<?php echo (int) $roomCard['booking_id']; ?>-<?php echo (int) preg_replace('/\D+/', '', $roomCard['room_number']); ?>"
                                            name="athlete_id"
                                            <?php echo count($unassignedAthletes) === 0 ? 'disabled' : ''; ?>
                                            required
                                        >
                                            <option value="">-- Select Unassigned Delegate --</option>
                                            <?php foreach ($unassignedAthletes as $athlete): ?>
                                                <option value="<?php echo (int) $athlete['id']; ?>"><?php echo htmlspecialchars($athlete['name'] . ' (' . $athlete['gender'] . ')'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm" <?php echo count($unassignedAthletes) === 0 ? 'disabled' : ''; ?>>Assign</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="small text-danger fw-semibold">This room is full.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted text-center py-4 mb-0">No booked rooms available yet. Reserve rooms first on the booking page.</p>
        <?php endif; ?>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
