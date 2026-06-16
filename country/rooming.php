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

                        if ($selectedOccupants === null) {
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

$reservationsStmt = $pdo->prepare("SELECT b.id, b.rooms_reserved, c.title AS championship_title,
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

$athleteOptions = [];
foreach ($athletes as $athlete) {
    $athleteOptions[] = [
        'id' => (int) $athlete['id'],
        'name' => trim((string) $athlete['first_name'] . ' ' . (string) $athlete['last_name']),
        'gender' => (string) $athlete['gender'],
        'booking_id' => isset($athlete['booking_id']) ? (int) $athlete['booking_id'] : 0,
        'room_number' => (string) ($athlete['room_number'] ?? ''),
        'championship_title' => (string) ($athlete['championship_title'] ?? ''),
        'hotel_name' => (string) ($athlete['hotel_name'] ?? ''),
        'room_type_name' => (string) ($athlete['room_type_name'] ?? ''),
    ];
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Room Grouping</h1>
        <p class="text-muted mb-0">Simple flow: select delegate, choose room, and review names in that room.</p>
    </div>
    <a href="book.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-calendar-check me-1"></i> Manage Reservations
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title mb-3">Assign Delegate to Room Group</h5>
                <?php if (count($athletes) > 0 && count($reservationOptions) > 0): ?>
                    <form method="POST" id="quickRoomingForm">
                        <input type="hidden" name="action" value="assign_room">

                        <div class="mb-3">
                            <label for="athleteSelect" class="form-label">1) Select Delegate</label>
                            <select id="athleteSelect" name="athlete_id" class="form-select" required>
                                <option value="">-- Select Delegate --</option>
                            </select>
                        </div>

                        <div id="currentAssignmentBox" class="border rounded p-3 bg-light-subtle mb-3 text-muted small">
                            Select a delegate to view current room assignment and roommate list.
                        </div>

                        <div class="mb-3">
                            <label for="bookingSelect" class="form-label">2) Select Reservation</label>
                            <select id="bookingSelect" name="booking_id" class="form-select" required>
                                <option value="">-- Select Reserved Booking --</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="roomGroupingSelect" class="form-label">3) Select Room Group</label>
                            <select id="roomGroupingSelect" name="room_grouping" class="form-select" required disabled>
                                <option value="">-- Select Room Group --</option>
                            </select>
                        </div>

                        <div id="selectedGroupPreview" class="border rounded p-3 mb-3">
                            <div class="fw-semibold mb-2">Selected Room Group</div>
                            <p class="text-muted small mb-0">Choose a reservation and room group to view names in that room.</p>
                        </div>

                        <button type="submit" id="saveAssignmentButton" class="btn btn-primary" disabled>
                            <i class="bi bi-check-circle me-1"></i> Save Assignment
                        </button>
                    </form>

                    <form method="POST" id="unassignForm" class="mt-2">
                        <input type="hidden" name="action" value="unassign_room">
                        <input type="hidden" name="athlete_id" id="unassignAthleteId" value="">
                        <button type="submit" id="unassignButton" class="btn btn-outline-danger btn-sm" disabled onclick="return confirm('Unassign this delegate from room grouping?');">
                            <i class="bi bi-x-circle me-1"></i> Unassign Selected Delegate
                        </button>
                    </form>
                <?php elseif (count($athletes) === 0): ?>
                    <p class="text-muted mb-0">No delegates found. Add delegates first in Athletes & Officials.</p>
                <?php else: ?>
                    <p class="text-muted mb-0">No reserved rooms found. Reserve rooms first on the booking page.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title mb-1">Reservation Snapshot</h5>
                <p class="text-muted small mb-3">Quick reference for remaining slots.</p>
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
        <h5 class="card-title mb-3">Current Room Groups</h5>
        <?php if (count($roomGroups) > 0): ?>
            <div class="row g-3">
                <?php foreach ($roomGroups as $roomGroup): ?>
                    <div class="col-lg-6">
                        <div class="border rounded p-3 h-100">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($roomGroup['hotel_name']); ?> - <?php echo htmlspecialchars($roomGroup['room_number']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($roomGroup['room_type_name']); ?> (<?php echo (int) $roomGroup['capacity']; ?> pax/room)</div>
                                </div>
                                <span class="badge text-bg-primary"><?php echo count($roomGroup['occupants']); ?> / <?php echo (int) $roomGroup['capacity']; ?> Pax</span>
                            </div>
                            <div class="small text-muted mb-2"><?php echo htmlspecialchars($roomGroup['championship_title']); ?></div>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($roomGroup['occupants'] as $occupant): ?>
                                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                        <span><?php echo htmlspecialchars($occupant['name']); ?></span>
                                        <span class="text-muted small"><?php echo htmlspecialchars($occupant['gender']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted text-center py-4 mb-0">No room groups available yet. Reserve rooms and then assign delegates.</p>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var athletes = <?php echo json_encode($athleteOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var reservationOptions = <?php echo json_encode($reservationOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var roomGroupPreview = <?php echo json_encode($roomGroupPreview, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    var form = document.getElementById('quickRoomingForm');
    if (!form) {
        return;
    }

    var athleteSelect = document.getElementById('athleteSelect');
    var bookingSelect = document.getElementById('bookingSelect');
    var roomGroupingSelect = document.getElementById('roomGroupingSelect');
    var currentAssignmentBox = document.getElementById('currentAssignmentBox');
    var selectedGroupPreview = document.getElementById('selectedGroupPreview');
    var saveAssignmentButton = document.getElementById('saveAssignmentButton');
    var unassignAthleteId = document.getElementById('unassignAthleteId');
    var unassignButton = document.getElementById('unassignButton');

    var selectedAthlete = null;

    function findReservationById(bookingId) {
        for (var i = 0; i < reservationOptions.length; i++) {
            if (String(reservationOptions[i].id) === String(bookingId)) {
                return reservationOptions[i];
            }
        }
        return null;
    }

    function fillAthleteOptions() {
        athleteSelect.innerHTML = '<option value="">-- Select Delegate --</option>';
        athletes.forEach(function (athlete) {
            var option = document.createElement('option');
            option.value = String(athlete.id);
            option.textContent = athlete.name + ' (' + athlete.gender + ')';
            athleteSelect.appendChild(option);
        });
    }

    function fillBookingOptions(defaultBookingId) {
        bookingSelect.innerHTML = '<option value="">-- Select Reserved Booking --</option>';
        reservationOptions.forEach(function (reservation) {
            var option = document.createElement('option');
            option.value = String(reservation.id);
            option.textContent = reservation.hotel_name + ' / ' + reservation.room_type_name + ' (' + reservation.remaining_slots + ' slots left)';
            if (defaultBookingId && String(defaultBookingId) === String(reservation.id)) {
                option.selected = true;
            }
            bookingSelect.appendChild(option);
        });
    }

    function fillRoomGroupOptions() {
        var groups = roomGroupPreview[String(bookingSelect.value)] || [];
        var reservation = findReservationById(bookingSelect.value);
        roomGroupingSelect.innerHTML = '<option value="">-- Select Room Group --</option>';

        if (!reservation) {
            roomGroupingSelect.disabled = true;
            return;
        }

        groups.forEach(function (group) {
            var option = document.createElement('option');
            option.value = group.room_number;
            option.textContent = group.room_number + ' - ' + group.occupants.length + '/' + reservation.capacity + ' occupied';
            roomGroupingSelect.appendChild(option);
        });

        if (reservation.empty_room_groups > 0) {
            var newOption = document.createElement('option');
            newOption.value = '__new__';
            newOption.textContent = 'Start New Room Group (' + reservation.empty_room_groups + ' available)';
            roomGroupingSelect.appendChild(newOption);
        }

        if (
            selectedAthlete &&
            selectedAthlete.room_number &&
            selectedAthlete.booking_id &&
            String(selectedAthlete.booking_id) === String(bookingSelect.value)
        ) {
            roomGroupingSelect.value = selectedAthlete.room_number;
        }

        roomGroupingSelect.disabled = roomGroupingSelect.options.length === 1;
    }

    function renderCurrentAssignment() {
        if (!selectedAthlete) {
            currentAssignmentBox.className = 'border rounded p-3 bg-light-subtle mb-3 text-muted small';
            currentAssignmentBox.textContent = 'Select a delegate to view current room assignment and roommate list.';
            unassignButton.disabled = true;
            unassignAthleteId.value = '';
            return;
        }

        unassignAthleteId.value = String(selectedAthlete.id);

        if (!selectedAthlete.room_number || !selectedAthlete.booking_id) {
            currentAssignmentBox.className = 'border rounded p-3 bg-light-subtle mb-3';
            currentAssignmentBox.innerHTML = '<div class="fw-semibold">Current Assignment</div><p class="small text-muted mb-0">This delegate is not assigned to any room group yet.</p>';
            unassignButton.disabled = true;
            return;
        }

        var roomMembers = [];
        var groups = roomGroupPreview[String(selectedAthlete.booking_id)] || [];
        groups.forEach(function (group) {
            if (group.room_number === selectedAthlete.room_number) {
                roomMembers = group.occupants || [];
            }
        });

        var html = '';
        html += '<div class="fw-semibold mb-1">Current Assignment: ' + selectedAthlete.room_number + '</div>';
        html += '<div class="small text-muted mb-2">' + selectedAthlete.championship_title + ' / ' + selectedAthlete.hotel_name + ' / ' + selectedAthlete.room_type_name + '</div>';
        html += '<div class="small fw-semibold mb-1">Roommate List</div>';
        if (roomMembers.length === 0) {
            html += '<p class="small text-muted mb-0">No roommates found in this room group.</p>';
        } else {
            html += '<ul class="mb-0 small">';
            roomMembers.forEach(function (member) {
                html += '<li>' + member.name + ' (' + member.gender + ')</li>';
            });
            html += '</ul>';
        }
        currentAssignmentBox.className = 'border rounded p-3 bg-light-subtle mb-3';
        currentAssignmentBox.innerHTML = html;
        unassignButton.disabled = false;
    }

    function renderSelectedRoomPreview() {
        var reservation = findReservationById(bookingSelect.value);
        if (!reservation) {
            selectedGroupPreview.innerHTML = '<div class="fw-semibold mb-2">Selected Room Group</div><p class="text-muted small mb-0">Choose a reservation and room group to view names in that room.</p>';
            saveAssignmentButton.disabled = true;
            return;
        }

        if (!roomGroupingSelect.value) {
            selectedGroupPreview.innerHTML = '<div class="fw-semibold mb-2">Selected Room Group</div><p class="text-muted small mb-0">Select a room group to see who is currently assigned.</p>';
            saveAssignmentButton.disabled = true;
            return;
        }

        if (roomGroupingSelect.value === '__new__') {
            selectedGroupPreview.innerHTML = '<div class="fw-semibold mb-1 text-success">New Room Group</div><p class="small text-muted mb-0">A new empty room group will be created from your reserved room inventory.</p>';
            saveAssignmentButton.disabled = !selectedAthlete;
            return;
        }

        var selectedGroup = null;
        var groups = roomGroupPreview[String(bookingSelect.value)] || [];
        groups.forEach(function (group) {
            if (group.room_number === roomGroupingSelect.value) {
                selectedGroup = group;
            }
        });

        if (!selectedGroup) {
            selectedGroupPreview.innerHTML = '<div class="fw-semibold mb-2">Selected Room Group</div><p class="text-muted small mb-0">The selected room group is no longer available.</p>';
            saveAssignmentButton.disabled = true;
            return;
        }

        var html = '';
        html += '<div class="d-flex justify-content-between align-items-center mb-2">';
        html += '<div class="fw-semibold">' + selectedGroup.room_number + '</div>';
        html += '<span class="badge text-bg-primary">' + selectedGroup.occupants.length + '/' + reservation.capacity + ' occupants</span>';
        html += '</div>';

        if (selectedGroup.occupants.length === 0) {
            html += '<p class="small text-muted mb-0">No names assigned in this room group yet.</p>';
        } else {
            html += '<ul class="mb-0 small">';
            selectedGroup.occupants.forEach(function (member) {
                html += '<li>' + member.name + ' (' + member.gender + ')</li>';
            });
            html += '</ul>';
        }

        selectedGroupPreview.innerHTML = html;
        saveAssignmentButton.disabled = !selectedAthlete;
    }

    function syncFromAthleteSelection() {
        selectedAthlete = null;
        for (var i = 0; i < athletes.length; i++) {
            if (String(athletes[i].id) === String(athleteSelect.value)) {
                selectedAthlete = athletes[i];
                break;
            }
        }

        if (selectedAthlete) {
            fillBookingOptions(selectedAthlete.booking_id || '');
        } else {
            fillBookingOptions('');
        }

        fillRoomGroupOptions();
        renderCurrentAssignment();
        renderSelectedRoomPreview();
    }

    athleteSelect.addEventListener('change', syncFromAthleteSelection);

    bookingSelect.addEventListener('change', function () {
        fillRoomGroupOptions();
        renderSelectedRoomPreview();
    });

    roomGroupingSelect.addEventListener('change', function () {
        renderSelectedRoomPreview();
    });

    fillAthleteOptions();
});
</script>

<?php
require_once 'includes/footer.php';
?>
