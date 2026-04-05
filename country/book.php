<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';

function isHotelAvailableForChampionship(PDO $pdo, int $championshipId, int $hotelId): bool
{
    $mappingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM championship_hotels WHERE championship_id = ?");
    $mappingCountStmt->execute([$championshipId]);

    if ((int) $mappingCountStmt->fetchColumn() === 0) {
        return true;
    }

    $hotelLinkStmt = $pdo->prepare("SELECT COUNT(*) FROM championship_hotels WHERE championship_id = ? AND hotel_id = ?");
    $hotelLinkStmt->execute([$championshipId, $hotelId]);

    return (int) $hotelLinkStmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_reservation') {
        $championshipId = (int) ($_POST['championship_id'] ?? 0);
        $roomTypeId = (int) ($_POST['room_type_id'] ?? 0);
        $roomsRequested = (int) ($_POST['rooms_reserved'] ?? 0);

        if ($championshipId <= 0 || $roomTypeId <= 0 || $roomsRequested <= 0) {
            $msg = "<div class='alert alert-warning'>Please select a championship, room type, and number of rooms to reserve.</div>";
        } else {
            $roomTypeStmt = $pdo->prepare("SELECT rt.id, rt.hotel_id, rt.name, rt.capacity, rt.total_allotment, h.name AS hotel_name
                FROM room_types rt
                JOIN hotels h ON h.id = rt.hotel_id
                WHERE rt.id = ?");
            $roomTypeStmt->execute([$roomTypeId]);
            $roomType = $roomTypeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$roomType) {
                $msg = "<div class='alert alert-danger'>Invalid room type selection.</div>";
            } elseif (!isHotelAvailableForChampionship($pdo, $championshipId, (int) $roomType['hotel_id'])) {
                $msg = "<div class='alert alert-warning'>The selected hotel is not available for this championship.</div>";
            } else {
                $bookingStmt = $pdo->prepare("SELECT b.id, b.rooms_reserved,
                    (SELECT COUNT(*) FROM room_assignments ra WHERE ra.booking_id = b.id) AS assigned_athletes
                    FROM bookings b
                    WHERE b.country_id = ?
                        AND b.championship_id = ?
                        AND b.room_type_id = ?
                        AND b.status <> 'Cancelled'
                    ORDER BY b.id ASC
                    LIMIT 1");
                $bookingStmt->execute([$countryId, $championshipId, $roomTypeId]);
                $existingBooking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

                $existingBookingId = $existingBooking ? (int) $existingBooking['id'] : 0;
                $assignedAthletes = $existingBooking ? (int) $existingBooking['assigned_athletes'] : 0;
                $capacity = max(1, (int) $roomType['capacity']);
                $minimumRoomsRequired = $assignedAthletes > 0 ? (int) ceil($assignedAthletes / $capacity) : 0;

                if ($roomsRequested < $minimumRoomsRequired) {
                    $msg = "<div class='alert alert-warning'>You cannot reserve fewer than {$minimumRoomsRequired} room(s) while athletes are already assigned to this reservation.</div>";
                } else {
                    $reservedByOthersStmt = $pdo->prepare("SELECT COALESCE(SUM(rooms_reserved), 0)
                        FROM bookings
                        WHERE room_type_id = ?
                            AND status <> 'Cancelled'
                            AND (? = 0 OR id <> ?)");
                    $reservedByOthersStmt->execute([$roomTypeId, $existingBookingId, $existingBookingId]);
                    $reservedByOthers = (int) $reservedByOthersStmt->fetchColumn();

                    $maximumReservable = max(0, (int) $roomType['total_allotment'] - $reservedByOthers);

                    if ($roomsRequested > $maximumReservable) {
                        $msg = "<div class='alert alert-warning'>Only {$maximumReservable} room(s) are currently available for this room type.</div>";
                    } else {
                        if ($existingBooking) {
                            $updateStmt = $pdo->prepare("UPDATE bookings SET hotel_id = ?, rooms_reserved = ?, status = 'Pending' WHERE id = ? AND country_id = ?");
                            $updateStmt->execute([(int) $roomType['hotel_id'], $roomsRequested, $existingBookingId, $countryId]);
                            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-calendar-check me-1'></i>Reservation updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                        } else {
                            $insertStmt = $pdo->prepare("INSERT INTO bookings (championship_id, country_id, hotel_id, room_type_id, rooms_reserved, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
                            $insertStmt->execute([$championshipId, $countryId, (int) $roomType['hotel_id'], $roomTypeId, $roomsRequested]);
                            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-calendar-check me-1'></i>Reservation locked successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                        }
                    }
                }
            }
        }
    } elseif ($_POST['action'] === 'delete_reservation') {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);

        if ($bookingId <= 0) {
            $msg = "<div class='alert alert-warning'>Invalid reservation selected.</div>";
        } else {
            $bookingStmt = $pdo->prepare("SELECT b.id,
                (SELECT COUNT(*) FROM room_assignments ra WHERE ra.booking_id = b.id) AS assigned_athletes
                FROM bookings b
                WHERE b.id = ? AND b.country_id = ? AND b.status <> 'Cancelled'
                LIMIT 1");
            $bookingStmt->execute([$bookingId, $countryId]);
            $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                $msg = "<div class='alert alert-warning'>Reservation not found.</div>";
            } elseif ((int) $booking['assigned_athletes'] > 0) {
                $msg = "<div class='alert alert-warning'>Unassign athletes from this reservation before removing it.</div>";
            } else {
                $deleteStmt = $pdo->prepare("DELETE FROM bookings WHERE id = ? AND country_id = ?");
                $deleteStmt->execute([$bookingId, $countryId]);
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-trash me-1'></i>Reservation removed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            }
        }
    }
}

$championships = $pdo->query("SELECT id, title, start_date, end_date FROM championships ORDER BY start_date ASC")->fetchAll(PDO::FETCH_ASSOC);

$roomTypesStmt = $pdo->prepare("SELECT rt.id, rt.hotel_id, rt.name, rt.capacity, rt.price_per_night, rt.total_allotment, h.name AS hotel_name,
    COALESCE((SELECT SUM(b.rooms_reserved) FROM bookings b WHERE b.room_type_id = rt.id AND b.status <> 'Cancelled'), 0) AS reserved_rooms
    FROM room_types rt
    JOIN hotels h ON h.id = rt.hotel_id
    ORDER BY h.name ASC, rt.name ASC");
$roomTypesStmt->execute();
$roomTypes = $roomTypesStmt->fetchAll(PDO::FETCH_ASSOC);

$championshipHotelRows = $pdo->query("SELECT championship_id, hotel_id FROM championship_hotels")->fetchAll(PDO::FETCH_ASSOC);
$championshipHotelMap = [];
foreach ($championshipHotelRows as $championshipHotelRow) {
    $championshipIdKey = (string) $championshipHotelRow['championship_id'];
    if (!isset($championshipHotelMap[$championshipIdKey])) {
        $championshipHotelMap[$championshipIdKey] = [];
    }
    $championshipHotelMap[$championshipIdKey][] = (int) $championshipHotelRow['hotel_id'];
}

$reservationAvailability = [];
foreach ($roomTypes as $roomType) {
    $reservedRooms = (int) $roomType['reserved_rooms'];
    $availableRooms = max(0, (int) $roomType['total_allotment'] - $reservedRooms);
    $reservationAvailability[(string) $roomType['id']] = [
        'id' => (int) $roomType['id'],
        'hotel_id' => (int) $roomType['hotel_id'],
        'hotel_name' => $roomType['hotel_name'],
        'room_type_name' => $roomType['name'],
        'capacity' => (int) $roomType['capacity'],
        'price_per_night' => (float) $roomType['price_per_night'],
        'total_allotment' => (int) $roomType['total_allotment'],
        'reserved_rooms' => $reservedRooms,
        'available_rooms' => $availableRooms,
    ];
}

$reservationsStmt = $pdo->prepare("SELECT b.id, b.championship_id, b.room_type_id, b.rooms_reserved, b.status,
    c.title AS championship_title, c.start_date, c.end_date,
    h.name AS hotel_name,
    rt.name AS room_type_name, rt.capacity, rt.price_per_night,
    (SELECT COUNT(*) FROM room_assignments ra WHERE ra.booking_id = b.id) AS assigned_athletes,
    (SELECT COUNT(DISTINCT ra.room_number) FROM room_assignments ra WHERE ra.booking_id = b.id AND ra.room_number IS NOT NULL AND ra.room_number <> '') AS used_room_groups
    FROM bookings b
    JOIN championships c ON c.id = b.championship_id
    JOIN hotels h ON h.id = b.hotel_id
    JOIN room_types rt ON rt.id = b.room_type_id
    WHERE b.country_id = ? AND b.status <> 'Cancelled'
    ORDER BY c.start_date ASC, h.name ASC, rt.name ASC");
$reservationsStmt->execute([$countryId]);
$reservations = $reservationsStmt->fetchAll(PDO::FETCH_ASSOC);

$reservationMap = [];
$totalReservedRooms = 0;
foreach ($reservations as $reservation) {
    $reservationMap[$reservation['championship_id'] . '|' . $reservation['room_type_id']] = [
        'booking_id' => (int) $reservation['id'],
        'rooms_reserved' => (int) $reservation['rooms_reserved'],
        'assigned_athletes' => (int) $reservation['assigned_athletes'],
    ];
    $totalReservedRooms += (int) $reservation['rooms_reserved'];
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Book Accommodation</h1>
        <p class="text-muted mb-0">Reserve room inventory first to lock availability for your delegation.</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#reservationModal">
        <i class="bi bi-plus-lg me-1"></i> Reserve Rooms
    </button>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Reserved Rooms</div>
                <div class="display-6 fw-semibold"><?php echo $totalReservedRooms; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Active Reservations</div>
                <div class="display-6 fw-semibold text-primary"><?php echo count($reservations); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Next Step</div>
                <div class="small text-muted">Use <a href="rooming.php">Room Grouping</a> to place athletes into reserved room groups.</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-1">Current Reservations</h5>
                        <p class="text-muted small mb-0">These reservations lock inventory before athlete assignment.</p>
                    </div>
                    <a href="rooming.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-door-open me-1"></i> Go To Room Grouping
                    </a>
                </div>

                <?php if (count($reservations) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Championship</th>
                                    <th>Hotel / Room Type</th>
                                    <th>Reserved</th>
                                    <th>Usage</th>
                                    <th>Rate</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservations as $reservation): ?>
                                    <?php
                                        $capacity = max(1, (int) $reservation['capacity']);
                                        $totalCapacity = (int) $reservation['rooms_reserved'] * $capacity;
                                        $remainingSlots = max(0, $totalCapacity - (int) $reservation['assigned_athletes']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($reservation['championship_title']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars(date('M d, Y', strtotime($reservation['start_date'])) . ' - ' . date('M d, Y', strtotime($reservation['end_date']))); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($reservation['hotel_name']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($reservation['room_type_name'] . ' (' . $capacity . ' pax/room)'); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo (int) $reservation['rooms_reserved']; ?> room(s)</div>
                                            <div class="small text-muted">Capacity: <?php echo $totalCapacity; ?> pax</div>
                                        </td>
                                        <td>
                                            <div class="small text-muted">Assigned athletes: <?php echo (int) $reservation['assigned_athletes']; ?></div>
                                            <div class="small text-muted">Used room groups: <?php echo (int) $reservation['used_room_groups']; ?></div>
                                            <div class="small text-muted">Remaining slots: <?php echo $remainingSlots; ?></div>
                                        </td>
                                        <td>$<?php echo number_format((float) $reservation['price_per_night'], 2); ?>/pax/day</td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary js-edit-reservation" data-bs-toggle="modal" data-bs-target="#reservationModal" data-championship-id="<?php echo (int) $reservation['championship_id']; ?>" data-room-type-id="<?php echo (int) $reservation['room_type_id']; ?>" data-rooms-reserved="<?php echo (int) $reservation['rooms_reserved']; ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this reservation?');">
                                                <input type="hidden" name="action" value="delete_reservation">
                                                <input type="hidden" name="booking_id" value="<?php echo (int) $reservation['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No rooms reserved yet. Reserve rooms here, then assign athletes later on the room grouping page.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-1">Availability Snapshot</h5>
                <p class="text-muted small mb-3">Availability is locked by reserved rooms, not by assigned athletes.</p>
                <?php if (count($roomTypes) > 0): ?>
                    <?php foreach ($roomTypes as $roomType): ?>
                        <?php $availability = $reservationAvailability[(string) $roomType['id']]; ?>
                        <div class="mb-3 p-3 border rounded">
                            <div class="fw-semibold"><?php echo htmlspecialchars($availability['hotel_name']); ?> - <?php echo htmlspecialchars($availability['room_type_name']); ?></div>
                            <div class="small text-muted mb-2">Capacity: <?php echo $availability['capacity']; ?> pax per room</div>
                            <div class="d-flex justify-content-between small">
                                <span><?php echo $availability['available_rooms']; ?> room(s) left</span>
                                <span>$<?php echo number_format($availability['price_per_night'], 2); ?>/pax/day</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted mb-0">No room types registered by admin yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="reservationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="reservationForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Reserve Rooms</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_reservation">

                    <div class="mb-3">
                        <label class="form-label">Select Championship</label>
                        <select name="championship_id" class="form-select js-championship-select" required>
                            <option value="">-- Choose Championship --</option>
                            <?php foreach ($championships as $championship): ?>
                                <option value="<?php echo (int) $championship['id']; ?>"><?php echo htmlspecialchars($championship['title'] . ' (' . date('M d', strtotime($championship['start_date'])) . ' - ' . date('M d', strtotime($championship['end_date'])) . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Filter by Hotel</label>
                        <select class="form-select js-hotel-filter">
                            <option value="">-- All Available Hotels --</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Select Hotel Room Type</label>
                        <select name="room_type_id" class="form-select js-room-type-select" required>
                            <option value="">-- Choose Room Type --</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Rooms To Reserve</label>
                        <input type="number" name="rooms_reserved" class="form-control js-rooms-reserved-input" min="1" required>
                        <div class="form-text">Reserve the number of room groups you want to lock for this championship and room type.</div>
                    </div>

                    <div class="border rounded p-3 bg-light-subtle js-reservation-summary text-muted small">
                        Select a championship and room type to review reservation availability.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Reservation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var reservationAvailability = <?php echo json_encode(array_values($reservationAvailability), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var championshipHotelMap = <?php echo json_encode($championshipHotelMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var reservationMap = <?php echo json_encode($reservationMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var form = document.getElementById('reservationForm');
    var championshipSelect = form.querySelector('.js-championship-select');
    var hotelFilter = form.querySelector('.js-hotel-filter');
    var roomTypeSelect = form.querySelector('.js-room-type-select');
    var roomsInput = form.querySelector('.js-rooms-reserved-input');
    var summary = form.querySelector('.js-reservation-summary');
    var submitButton = form.querySelector('button[type="submit"]');

    function getAllowedRoomTypes(championshipId, hotelId) {
        var mappedHotels = championshipHotelMap[String(championshipId)] || [];
        var hasMappedHotels = mappedHotels.length > 0;

        return reservationAvailability.filter(function (roomType) {
            var matchesChampionship = !championshipId || !hasMappedHotels || mappedHotels.indexOf(roomType.hotel_id) !== -1;
            var matchesHotel = !hotelId || roomType.hotel_id === Number(hotelId);
            return matchesChampionship && matchesHotel;
        });
    }

    function renderHotelFilter(selectedRoomTypeId) {
        var allowedRoomTypes = getAllowedRoomTypes(championshipSelect.value, '');
        var seenHotels = {};
        var selectedHotelId = '';

        if (selectedRoomTypeId) {
            reservationAvailability.forEach(function (roomType) {
                if (String(roomType.id) === String(selectedRoomTypeId)) {
                    selectedHotelId = String(roomType.hotel_id);
                }
            });
        }

        hotelFilter.innerHTML = '<option value="">-- All Available Hotels --</option>';
        allowedRoomTypes.forEach(function (roomType) {
            if (seenHotels[roomType.hotel_id]) {
                return;
            }

            seenHotels[roomType.hotel_id] = true;
            var option = document.createElement('option');
            option.value = String(roomType.hotel_id);
            option.textContent = roomType.hotel_name;
            if (selectedHotelId && selectedHotelId === String(roomType.hotel_id)) {
                option.selected = true;
            }
            hotelFilter.appendChild(option);
        });

        hotelFilter.disabled = hotelFilter.options.length === 1;
    }

    function renderRoomTypeOptions(selectedRoomTypeId) {
        var allowedRoomTypes = getAllowedRoomTypes(championshipSelect.value, hotelFilter.value);
        roomTypeSelect.innerHTML = '<option value="">-- Choose Room Type --</option>';

        allowedRoomTypes.forEach(function (roomType) {
            var option = document.createElement('option');
            option.value = String(roomType.id);
            option.textContent = roomType.hotel_name + ' / ' + roomType.room_type_name + ' - ' + roomType.available_rooms + ' room(s) available';
            if (selectedRoomTypeId && String(selectedRoomTypeId) === String(roomType.id)) {
                option.selected = true;
            }
            roomTypeSelect.appendChild(option);
        });

        roomTypeSelect.disabled = roomTypeSelect.options.length === 1;
    }

    function renderSummary() {
        var selectedRoomType = null;
        reservationAvailability.forEach(function (roomType) {
            if (String(roomType.id) === String(roomTypeSelect.value)) {
                selectedRoomType = roomType;
            }
        });

        if (!championshipSelect.value || !selectedRoomType) {
            summary.className = 'border rounded p-3 bg-light-subtle js-reservation-summary text-muted small';
            summary.textContent = 'Select a championship and room type to review reservation availability.';
            submitButton.disabled = true;
            return;
        }

        var reservationKey = String(championshipSelect.value) + '|' + String(roomTypeSelect.value);
        var existingReservation = reservationMap[reservationKey] || null;
        var currentReserved = existingReservation ? existingReservation.rooms_reserved : 0;
        var assignedAthletes = existingReservation ? existingReservation.assigned_athletes : 0;
        var minimumRoomsRequired = assignedAthletes > 0 ? Math.ceil(assignedAthletes / Math.max(1, selectedRoomType.capacity)) : 0;
        var maximumReservable = selectedRoomType.available_rooms + currentReserved;

        roomsInput.min = String(Math.max(1, minimumRoomsRequired));

        summary.className = 'border rounded p-3 bg-light-subtle js-reservation-summary';
        summary.innerHTML =
            '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
                '<div>' +
                    '<div class="fw-semibold">' + selectedRoomType.hotel_name + ' / ' + selectedRoomType.room_type_name + '</div>' +
                    '<div class="small text-muted">Capacity: ' + selectedRoomType.capacity + ' pax per room</div>' +
                '</div>' +
                '<span class="badge text-bg-primary">Max ' + maximumReservable + ' room(s)</span>' +
            '</div>' +
            '<div class="row row-cols-2 g-2 small">' +
                '<div class="col"><div class="border rounded p-2 bg-white">Rooms Left<br><strong>' + selectedRoomType.available_rooms + '</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Locked Globally<br><strong>' + selectedRoomType.reserved_rooms + '</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Your Current Hold<br><strong>' + currentReserved + '</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Min Needed For Assigned Athletes<br><strong>' + minimumRoomsRequired + '</strong></div></div>' +
            '</div>' +
            '<div class="small text-muted mt-2">Assignments happen later on the room grouping page. This step only locks room inventory.</div>';

        submitButton.disabled = false;
    }

    function syncReservationModal(selectedRoomTypeId) {
        renderHotelFilter(selectedRoomTypeId);
        renderRoomTypeOptions(selectedRoomTypeId);
        renderSummary();
    }

    championshipSelect.addEventListener('change', function () {
        if (!form.dataset.editingReservation) {
            roomsInput.value = '';
        }
        syncReservationModal(roomTypeSelect.value);
    });

    hotelFilter.addEventListener('change', function () {
        if (!form.dataset.editingReservation) {
            roomsInput.value = '';
        }
        renderRoomTypeOptions(roomTypeSelect.value);
        renderSummary();
    });

    roomTypeSelect.addEventListener('change', function () {
        if (!form.dataset.editingReservation) {
            roomsInput.value = '';
        }
        renderSummary();
    });

    document.querySelectorAll('.js-edit-reservation').forEach(function (button) {
        button.addEventListener('click', function () {
            form.dataset.editingReservation = 'true';
            championshipSelect.value = button.getAttribute('data-championship-id') || '';
            roomsInput.value = button.getAttribute('data-rooms-reserved') || '';
            syncReservationModal(button.getAttribute('data-room-type-id') || '');
            roomTypeSelect.value = button.getAttribute('data-room-type-id') || '';
            renderSummary();
        });
    });

    document.getElementById('reservationModal').addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger || !trigger.classList.contains('js-edit-reservation')) {
            form.dataset.editingReservation = '';
            form.reset();
            hotelFilter.innerHTML = '<option value="">-- All Available Hotels --</option>';
            roomTypeSelect.innerHTML = '<option value="">-- Choose Room Type --</option>';
            renderSummary();
        }
    });

    renderSummary();
});
</script>

<?php
require_once 'includes/footer.php';
?>