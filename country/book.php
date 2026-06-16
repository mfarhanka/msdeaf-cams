<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';

function formatHotelStarRatingLabel(int $starRating): string
{
    return $starRating > 0 ? str_repeat('⭐', $starRating) : 'Unrated';
}

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
    $actor = getActorDetailsFromSession();

    if ($_POST['action'] === 'save_reservation') {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $championshipId = (int) ($_POST['championship_id'] ?? 0);
        $roomTypeId = (int) ($_POST['room_type_id'] ?? 0);
        $roomsRequested = (int) ($_POST['rooms_reserved'] ?? 0);
        $bookingStartDate = trim((string) ($_POST['booking_start_date'] ?? ''));
        $bookingEndDate = trim((string) ($_POST['booking_end_date'] ?? ''));

        if ($championshipId <= 0 || $roomTypeId <= 0 || $roomsRequested <= 0 || $bookingStartDate === '' || $bookingEndDate === '') {
            $msg = "<div class='alert alert-warning'>Please select a championship, room type, stay date range, and number of rooms to reserve.</div>";
        } else {
            $championshipStmt = $pdo->prepare("SELECT id, start_date, end_date FROM championships WHERE id = ? LIMIT 1");
            $championshipStmt->execute([$championshipId]);
            $championship = $championshipStmt->fetch(PDO::FETCH_ASSOC);

            $roomTypeStmt = $pdo->prepare("SELECT rt.id, rt.hotel_id, rt.name, rt.capacity, rt.total_allotment, h.name AS hotel_name, h.star_rating AS hotel_star_rating
                FROM room_types rt
                JOIN hotels h ON h.id = rt.hotel_id
                WHERE rt.id = ?");
            $roomTypeStmt->execute([$roomTypeId]);
            $roomType = $roomTypeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$championship) {
                $msg = "<div class='alert alert-danger'>Invalid championship selection.</div>";
            } elseif (!$roomType) {
                $msg = "<div class='alert alert-danger'>Invalid room type selection.</div>";
            } elseif (!isHotelAvailableForChampionship($pdo, $championshipId, (int) $roomType['hotel_id'])) {
                $msg = "<div class='alert alert-warning'>The selected hotel is not available for this championship.</div>";
            } elseif ($bookingStartDate > $bookingEndDate) {
                $msg = "<div class='alert alert-warning'>Check-out date must be on or after check-in date.</div>";
            } else {
                $existingBooking = null;
                if ($bookingId > 0) {
                    $bookingStmt = $pdo->prepare("SELECT b.id, b.rooms_reserved,
                        (SELECT COUNT(*) FROM room_assignments ra WHERE ra.booking_id = b.id) AS assigned_athletes
                        FROM bookings b
                        WHERE b.id = ?
                            AND b.country_id = ?
                            AND b.status <> 'Cancelled'
                        LIMIT 1");
                    $bookingStmt->execute([$bookingId, $countryId]);
                    $existingBooking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($bookingId > 0 && !$existingBooking) {
                    $msg = "<div class='alert alert-warning'>Reservation not found for editing.</div>";
                } else {
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
                            AND booking_start_date <= ?
                            AND booking_end_date >= ?
                            AND (? = 0 OR id <> ?)");
                        $reservedByOthersStmt->execute([$roomTypeId, $bookingEndDate, $bookingStartDate, $existingBookingId, $existingBookingId]);
                        $reservedByOthers = (int) $reservedByOthersStmt->fetchColumn();

                        $maximumReservable = max(0, (int) $roomType['total_allotment'] - $reservedByOthers);

                        if ($roomsRequested > $maximumReservable) {
                            $msg = "<div class='alert alert-warning'>Only {$maximumReservable} room(s) are available for this room type on the selected dates.</div>";
                        } else {
                            $bookingAction = $existingBooking ? 'booking_updated' : 'booking_created';

                            if ($existingBooking) {
                                $updateStmt = $pdo->prepare("UPDATE bookings SET championship_id = ?, hotel_id = ?, room_type_id = ?, rooms_reserved = ?, booking_start_date = ?, booking_end_date = ?, status = 'Pending' WHERE id = ? AND country_id = ?");
                                $updateStmt->execute([$championshipId, (int) $roomType['hotel_id'], $roomTypeId, $roomsRequested, $bookingStartDate, $bookingEndDate, $existingBookingId, $countryId]);
                                recordActivity(
                                    $pdo,
                                    $bookingAction,
                                    'booking',
                                    $existingBookingId,
                                    'Accommodation reservation updated.',
                                    ['championship_id' => $championshipId, 'hotel_name' => $roomType['hotel_name'], 'room_type_id' => $roomTypeId, 'room_type_name' => $roomType['name'], 'rooms_reserved' => $roomsRequested, 'booking_start_date' => $bookingStartDate, 'booking_end_date' => $bookingEndDate],
                                    $actor['id'],
                                    $actor['role'],
                                    $actor['username'],
                                    formatTelegramActivityMessage('CAMS booking update', ['Action: update booking', 'Delegation: ' . $actor['username'], 'Hotel: ' . $roomType['hotel_name'], 'Room type: ' . $roomType['name'], 'Rooms: ' . $roomsRequested, 'Stay: ' . $bookingStartDate . ' to ' . $bookingEndDate])
                                );
                                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-calendar-check me-1'></i>Reservation updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                            } else {
                                $insertStmt = $pdo->prepare("INSERT INTO bookings (championship_id, country_id, hotel_id, room_type_id, rooms_reserved, booking_start_date, booking_end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')");
                                $insertStmt->execute([$championshipId, $countryId, (int) $roomType['hotel_id'], $roomTypeId, $roomsRequested, $bookingStartDate, $bookingEndDate]);
                                $newBookingId = (int) $pdo->lastInsertId();
                                recordActivity(
                                    $pdo,
                                    $bookingAction,
                                    'booking',
                                    $newBookingId,
                                    'Accommodation reservation created.',
                                    ['championship_id' => $championshipId, 'hotel_name' => $roomType['hotel_name'], 'room_type_id' => $roomTypeId, 'room_type_name' => $roomType['name'], 'rooms_reserved' => $roomsRequested, 'booking_start_date' => $bookingStartDate, 'booking_end_date' => $bookingEndDate],
                                    $actor['id'],
                                    $actor['role'],
                                    $actor['username'],
                                    formatTelegramActivityMessage('CAMS booking update', ['Action: create booking', 'Delegation: ' . $actor['username'], 'Hotel: ' . $roomType['hotel_name'], 'Room type: ' . $roomType['name'], 'Rooms: ' . $roomsRequested, 'Stay: ' . $bookingStartDate . ' to ' . $bookingEndDate])
                                );
                                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-calendar-check me-1'></i>Reservation locked successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                            }
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
            } else {
                $bookingDetailsStmt = $pdo->prepare("SELECT c.title AS championship_title, h.name AS hotel_name, rt.name AS room_type_name, b.rooms_reserved
                    FROM bookings b
                    JOIN championships c ON c.id = b.championship_id
                    JOIN hotels h ON h.id = b.hotel_id
                    JOIN room_types rt ON rt.id = b.room_type_id
                    WHERE b.id = ? AND b.country_id = ?");
                $bookingDetailsStmt->execute([$bookingId, $countryId]);
                $bookingDetails = $bookingDetailsStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                try {
                    $pdo->beginTransaction();

                    $assignedAthletes = (int) $booking['assigned_athletes'];
                    if ($assignedAthletes > 0) {
                        $clearAssignmentsStmt = $pdo->prepare("DELETE FROM room_assignments WHERE booking_id = ?");
                        $clearAssignmentsStmt->execute([$bookingId]);
                    }

                    $deleteStmt = $pdo->prepare("DELETE FROM bookings WHERE id = ? AND country_id = ?");
                    $deleteStmt->execute([$bookingId, $countryId]);

                    $pdo->commit();

                    $activityMetadata = $bookingDetails ?: [];
                    if ($assignedAthletes > 0) {
                        $activityMetadata['unassigned_athletes'] = $assignedAthletes;
                    }

                    recordActivity(
                        $pdo,
                        'booking_deleted',
                        'booking',
                        $bookingId,
                        'Accommodation reservation deleted.',
                        $activityMetadata,
                        $actor['id'],
                        $actor['role'],
                        $actor['username'],
                        formatTelegramActivityMessage('CAMS booking update', ['Action: delete booking', 'Delegation: ' . $actor['username'], 'Hotel: ' . ($bookingDetails['hotel_name'] ?? 'Unknown'), 'Room type: ' . ($bookingDetails['room_type_name'] ?? 'Unknown')])
                    );
                    $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-trash me-1'></i>Reservation removed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $msg = "<div class='alert alert-danger'>Unable to remove the reservation right now. Please try again.</div>";
                }
            }
        }
    }
}

$championships = $pdo->query("SELECT id, title, start_date, end_date FROM championships ORDER BY start_date ASC")->fetchAll(PDO::FETCH_ASSOC);
$championshipDateMap = [];
foreach ($championships as $championship) {
    $championshipDateMap[(string) $championship['id']] = [
        'start_date' => $championship['start_date'],
        'end_date' => $championship['end_date'],
    ];
}

$roomTypesStmt = $pdo->prepare("SELECT rt.id, rt.hotel_id, rt.name, rt.capacity, rt.price_per_night, rt.total_allotment, h.name AS hotel_name, h.star_rating AS hotel_star_rating
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
    $reservationAvailability[(string) $roomType['id']] = [
        'id' => (int) $roomType['id'],
        'hotel_id' => (int) $roomType['hotel_id'],
        'hotel_name' => $roomType['hotel_name'],
        'hotel_star_rating' => (int) $roomType['hotel_star_rating'],
        'room_type_name' => $roomType['name'],
        'capacity' => (int) $roomType['capacity'],
        'price_per_night' => (float) $roomType['price_per_night'],
        'total_allotment' => (int) $roomType['total_allotment'],
    ];
}

$activeBookingsStmt = $pdo->prepare("SELECT id, room_type_id, rooms_reserved,
    booking_start_date,
    booking_end_date
    FROM bookings
    WHERE status <> 'Cancelled'");
$activeBookingsStmt->execute();
$activeBookings = [];
foreach ($activeBookingsStmt->fetchAll(PDO::FETCH_ASSOC) as $activeBookingRow) {
    $activeBookings[] = [
        'id' => (int) $activeBookingRow['id'],
        'room_type_id' => (int) $activeBookingRow['room_type_id'],
        'rooms_reserved' => (int) $activeBookingRow['rooms_reserved'],
        'booking_start_date' => $activeBookingRow['booking_start_date'],
        'booking_end_date' => $activeBookingRow['booking_end_date'],
    ];
}

$reservationsStmt = $pdo->prepare("SELECT b.id, b.championship_id, b.room_type_id, b.rooms_reserved, b.status,
    c.title AS championship_title, c.start_date AS championship_start_date, c.end_date AS championship_end_date,
    b.booking_start_date, b.booking_end_date,
    h.name AS hotel_name, h.star_rating AS hotel_star_rating,
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

$totalReservedRooms = 0;
foreach ($reservations as $reservation) {
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
    <div class="col-lg-12">
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
                                    <th>Stay Dates</th>
                                    <th>Reserved</th>
                                    <th>Usage</th>
                                    <th>Payable</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservations as $reservation): ?>
                                    <?php
                                        $capacity = max(1, (int) $reservation['capacity']);
                                        $stayDays = max(1, (int) ((strtotime($reservation['booking_end_date']) - strtotime($reservation['booking_start_date'])) / 86400));
                                        $chargeablePax = (int) $reservation['rooms_reserved'] * $capacity;
                                        $lineAmount = $chargeablePax * (float) $reservation['price_per_night'] * $stayDays;
                                        $totalCapacity = (int) $reservation['rooms_reserved'] * $capacity;
                                        $remainingSlots = max(0, $totalCapacity - (int) $reservation['assigned_athletes']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($reservation['championship_title']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars(date('M d, Y', strtotime($reservation['championship_start_date'])) . ' - ' . date('M d, Y', strtotime($reservation['championship_end_date']))); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($reservation['hotel_name']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars(formatHotelStarRatingLabel((int) $reservation['hotel_star_rating']) . ' hotel / ' . $reservation['room_type_name'] . ' (' . $capacity . ' pax/room)'); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars(date('M d, Y', strtotime($reservation['booking_start_date'])) . ' - ' . date('M d, Y', strtotime($reservation['booking_end_date']))); ?></div>
                                            <div class="small text-muted"><?php echo $stayDays; ?> day(s)</div>
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
                                        <td>
                                            <div class="fw-semibold text-success">$<?php echo number_format($lineAmount, 2); ?></div>
                                            <div class="small text-muted">$<?php echo number_format((float) $reservation['price_per_night'], 2); ?>/pax/day x <?php echo $chargeablePax; ?> pax</div>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary js-edit-reservation" data-bs-toggle="modal" data-bs-target="#reservationModal" data-booking-id="<?php echo (int) $reservation['id']; ?>" data-championship-id="<?php echo (int) $reservation['championship_id']; ?>" data-room-type-id="<?php echo (int) $reservation['room_type_id']; ?>" data-booking-start-date="<?php echo htmlspecialchars($reservation['booking_start_date']); ?>" data-booking-end-date="<?php echo htmlspecialchars($reservation['booking_end_date']); ?>" data-rooms-reserved="<?php echo (int) $reservation['rooms_reserved']; ?>" data-assigned-athletes="<?php echo (int) $reservation['assigned_athletes']; ?>">
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
                    <input type="hidden" name="booking_id" class="js-booking-id" value="0">

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
                        <label class="form-label mb-1">Hotel Availability</label>
                        <div class="border rounded p-3 bg-light-subtle small js-hotel-availability-list text-muted">
                            Select championship and dates to view hotel availability.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Check-in Date</label>
                            <input type="date" name="booking_start_date" class="form-control js-booking-start-date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Check-out Date</label>
                            <input type="date" name="booking_end_date" class="form-control js-booking-end-date" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Rooms To Reserve</label>
                        <input type="number" name="rooms_reserved" class="form-control js-rooms-reserved-input" min="1" value="1" required>
                        <div class="form-text">Reserve room groups. Payable amount uses room capacity x selected dates, even before assigning guests.</div>
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
    var championshipDateMap = <?php echo json_encode($championshipDateMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var activeBookings = <?php echo json_encode($activeBookings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var form = document.getElementById('reservationForm');
    var bookingIdInput = form.querySelector('.js-booking-id');
    var championshipSelect = form.querySelector('.js-championship-select');
    var hotelFilter = form.querySelector('.js-hotel-filter');
    var roomTypeSelect = form.querySelector('.js-room-type-select');
    var bookingStartInput = form.querySelector('.js-booking-start-date');
    var bookingEndInput = form.querySelector('.js-booking-end-date');
    var roomsInput = form.querySelector('.js-rooms-reserved-input');
    var hotelAvailabilityList = form.querySelector('.js-hotel-availability-list');
    var summary = form.querySelector('.js-reservation-summary');
    var submitButton = form.querySelector('button[type="submit"]');

    function formatHotelStarRating(starRating) {
        return Number(starRating) > 0 ? '⭐'.repeat(Number(starRating)) : 'Unrated';
    }

    function formatMoney(value) {
        return '$' + Number(value).toFixed(2);
    }

    function getDayCount(startDate, endDate) {
        if (!startDate || !endDate) {
            return 0;
        }

        var start = new Date(startDate + 'T00:00:00');
        var end = new Date(endDate + 'T00:00:00');
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || start > end) {
            return 0;
        }

        return Math.max(1, Math.floor((end - start) / 86400000));
    }

    function isOverlap(startA, endA, startB, endB) {
        if (!startA || !endA || !startB || !endB) {
            return false;
        }

        return startA <= endB && endA >= startB;
    }

    function getOverlappingReservedRooms(roomTypeId, editingBookingId, startDate, endDate) {
        var overlappingReserved = 0;

        if (!startDate || !endDate) {
            return overlappingReserved;
        }

        activeBookings.forEach(function (booking) {
            if (Number(booking.room_type_id) !== Number(roomTypeId)) {
                return;
            }

            if (editingBookingId > 0 && Number(booking.id) === editingBookingId) {
                return;
            }

            if (isOverlap(booking.booking_start_date, booking.booking_end_date, startDate, endDate)) {
                overlappingReserved += Number(booking.rooms_reserved || 0);
            }
        });

        return overlappingReserved;
    }

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
            option.textContent = roomType.hotel_name + ' (' + formatHotelStarRating(roomType.hotel_star_rating) + ')';
            if (selectedHotelId && selectedHotelId === String(roomType.hotel_id)) {
                option.selected = true;
            }
            hotelFilter.appendChild(option);
        });

        hotelFilter.disabled = hotelFilter.options.length === 1;
    }

    function renderRoomTypeOptions(selectedRoomTypeId) {
        var allowedRoomTypes = getAllowedRoomTypes(championshipSelect.value, hotelFilter.value);
        var editingBookingId = Number(bookingIdInput.value || 0);
        var hasValidDateRange = getDayCount(bookingStartInput.value, bookingEndInput.value) > 0;
        var hasSelectedOption = false;
        roomTypeSelect.innerHTML = '<option value="">-- Choose Room Type --</option>';

        allowedRoomTypes.forEach(function (roomType) {
            var overlappingReserved = hasValidDateRange
                ? getOverlappingReservedRooms(roomType.id, editingBookingId, bookingStartInput.value, bookingEndInput.value)
                : 0;
            var availableRooms = Math.max(0, Number(roomType.total_allotment) - overlappingReserved);
            var isFull = hasValidDateRange && availableRooms <= 0;
            var option = document.createElement('option');
            option.value = String(roomType.id);
            option.textContent =
                roomType.hotel_name + ' (' + formatHotelStarRating(roomType.hotel_star_rating) + ') / ' +
                roomType.room_type_name + ' - availability: ' + availableRooms + '/' + roomType.total_allotment + ' room(s)' +
                (isFull ? ' - Unavailable (Full)' : '');
            option.disabled = isFull;
            if (selectedRoomTypeId && String(selectedRoomTypeId) === String(roomType.id) && !isFull) {
                option.selected = true;
                hasSelectedOption = true;
            }
            roomTypeSelect.appendChild(option);
        });

        if (selectedRoomTypeId && !hasSelectedOption) {
            roomTypeSelect.value = '';
        }

        roomTypeSelect.disabled = roomTypeSelect.options.length === 1;
    }

    function renderHotelAvailabilityList() {
        var allowedRoomTypes = getAllowedRoomTypes(championshipSelect.value, hotelFilter.value);
        var editingBookingId = Number(bookingIdInput.value || 0);
        var hasValidDateRange = getDayCount(bookingStartInput.value, bookingEndInput.value) > 0;
        var availabilityByHotel = {};

        if (allowedRoomTypes.length === 0) {
            hotelAvailabilityList.className = 'border rounded p-3 bg-light-subtle small js-hotel-availability-list text-muted';
            hotelAvailabilityList.textContent = 'No hotels available for the selected filter.';
            return;
        }

        allowedRoomTypes.forEach(function (roomType) {
            var overlappingReserved = hasValidDateRange
                ? getOverlappingReservedRooms(roomType.id, editingBookingId, bookingStartInput.value, bookingEndInput.value)
                : 0;
            var totalAllotment = Number(roomType.total_allotment || 0);
            var availableRooms = Math.max(0, totalAllotment - overlappingReserved);

            if (!availabilityByHotel[roomType.hotel_id]) {
                availabilityByHotel[roomType.hotel_id] = {
                    hotel_name: roomType.hotel_name,
                    hotel_star_rating: roomType.hotel_star_rating,
                    available: 0,
                    total: 0
                };
            }

            availabilityByHotel[roomType.hotel_id].available += availableRooms;
            availabilityByHotel[roomType.hotel_id].total += totalAllotment;
        });

        var rows = Object.keys(availabilityByHotel).map(function (hotelId) {
            return availabilityByHotel[hotelId];
        });

        rows.sort(function (a, b) {
            return String(a.hotel_name).localeCompare(String(b.hotel_name));
        });

        hotelAvailabilityList.className = 'border rounded p-3 bg-light-subtle small js-hotel-availability-list';
        hotelAvailabilityList.innerHTML = rows.map(function (hotel) {
            return '<div class="d-flex justify-content-between align-items-center py-1 border-bottom">' +
                '<div class="fw-semibold">' + hotel.hotel_name + ' <span class="text-muted fw-normal">(' + formatHotelStarRating(hotel.hotel_star_rating) + ')</span></div>' +
                '<div class="text-nowrap">' + hotel.available + '/' + hotel.total + ' room(s)</div>' +
            '</div>';
        }).join('');

        if (!hasValidDateRange) {
            hotelAvailabilityList.innerHTML += '<div class="text-muted mt-2">Showing allotment totals. Select check-in/check-out dates for exact availability.</div>';
        }
    }

    function syncDateLimits() {
        var championshipDates = championshipDateMap[String(championshipSelect.value)] || null;

        if (championshipDates) {
            if (!bookingStartInput.value) {
                bookingStartInput.value = championshipDates.start_date;
            }
            if (!bookingEndInput.value) {
                bookingEndInput.value = championshipDates.end_date;
            }
        }
    }

    function renderSummary() {
        var selectedRoomType = null;
        reservationAvailability.forEach(function (roomType) {
            if (String(roomType.id) === String(roomTypeSelect.value)) {
                selectedRoomType = roomType;
            }
        });

        if (!championshipSelect.value || !selectedRoomType || !bookingStartInput.value || !bookingEndInput.value) {
            summary.className = 'border rounded p-3 bg-light-subtle js-reservation-summary text-muted small';
            summary.textContent = 'Select championship, room type, and dates to review reservation availability and payable amount.';
            submitButton.disabled = true;
            return;
        }

        var dayCount = getDayCount(bookingStartInput.value, bookingEndInput.value);
        if (dayCount <= 0) {
            summary.className = 'border rounded p-3 bg-light-subtle js-reservation-summary text-muted small';
            summary.textContent = 'Invalid date range selected.';
            submitButton.disabled = true;
            return;
        }

        var editingBookingId = Number(bookingIdInput.value || 0);
        var assignedAthletes = Number(form.dataset.assignedAthletes || 0);
        var minimumRoomsRequired = assignedAthletes > 0 ? Math.ceil(assignedAthletes / Math.max(1, selectedRoomType.capacity)) : 0;

        var overlappingReserved = 0;
        activeBookings.forEach(function (booking) {
            if (Number(booking.room_type_id) !== Number(selectedRoomType.id)) {
                return;
            }

            if (editingBookingId > 0 && Number(booking.id) === editingBookingId) {
                return;
            }

            if (isOverlap(booking.booking_start_date, booking.booking_end_date, bookingStartInput.value, bookingEndInput.value)) {
                overlappingReserved += Number(booking.rooms_reserved || 0);
            }
        });

        var maximumReservable = Math.max(0, Number(selectedRoomType.total_allotment) - overlappingReserved);
        var minimumBookable = Math.max(1, minimumRoomsRequired);
        var roomValue = Number(roomsInput.value || 0);
        var roomCountForEstimate = roomValue > 0 ? roomValue : 0;
        var chargeablePaxEstimate = roomCountForEstimate * Number(selectedRoomType.capacity);
        var amountPerDayPerPax = Number(selectedRoomType.price_per_night);
        var estimatedAmount = chargeablePaxEstimate * Number(selectedRoomType.price_per_night) * dayCount;

        roomsInput.min = String(minimumBookable);
        if (roomsInput.value && Number(roomsInput.value) > maximumReservable) {
            roomsInput.value = String(maximumReservable);
        }

        summary.className = 'border rounded p-3 bg-light-subtle js-reservation-summary';
        summary.innerHTML =
            '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
                '<div>' +
                    '<div class="fw-semibold">' + selectedRoomType.hotel_name + ' / ' + selectedRoomType.room_type_name + '</div>' +
                    '<div class="small text-muted">' + formatHotelStarRating(selectedRoomType.hotel_star_rating) + ' hotel, capacity: ' + selectedRoomType.capacity + ' pax per room</div>' +
                '</div>' +
                '<span class="badge text-bg-primary">Max ' + maximumReservable + ' room(s)</span>' +
            '</div>' +
            '<div class="row row-cols-2 g-2 small">' +
                '<div class="col"><div class="border rounded p-2 bg-white">Stay Duration<br><strong>' + dayCount + ' day(s)</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Total Pax<br><strong>' + chargeablePaxEstimate + '</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Amount / Day / Pax<br><strong>' + formatMoney(amountPerDayPerPax) + '</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Estimated Payable<br><strong>' + formatMoney(estimatedAmount) + '</strong></div></div>' +
            '</div>' +
            '<div class="small text-muted mt-2">Charging formula: reserved rooms x room capacity x rate per pax/day x selected days. Guest assignment can be done later on Room Grouping.</div>';

        submitButton.disabled = maximumReservable < minimumBookable;
    }

    function syncReservationModal(selectedRoomTypeId) {
        renderHotelFilter(selectedRoomTypeId);
        renderRoomTypeOptions(selectedRoomTypeId);
        renderHotelAvailabilityList();
        renderSummary();
    }

    championshipSelect.addEventListener('change', function () {
        if (!form.dataset.editingReservation) {
            roomsInput.value = '1';
        }
        syncDateLimits();
        syncReservationModal(roomTypeSelect.value);
    });

    hotelFilter.addEventListener('change', function () {
        if (!form.dataset.editingReservation) {
            roomsInput.value = '1';
        }
        syncReservationModal(roomTypeSelect.value);
    });

    roomTypeSelect.addEventListener('change', function () {
        if (!form.dataset.editingReservation) {
            roomsInput.value = '1';
        }
        renderSummary();
    });

    bookingStartInput.addEventListener('change', function () {
        syncReservationModal(roomTypeSelect.value);
    });
    bookingEndInput.addEventListener('change', function () {
        syncReservationModal(roomTypeSelect.value);
    });
    roomsInput.addEventListener('input', renderSummary);

    document.querySelectorAll('.js-edit-reservation').forEach(function (button) {
        button.addEventListener('click', function () {
            form.dataset.editingReservation = 'true';
            bookingIdInput.value = button.getAttribute('data-booking-id') || '0';
            form.dataset.assignedAthletes = button.getAttribute('data-assigned-athletes') || '0';
            championshipSelect.value = button.getAttribute('data-championship-id') || '';
            bookingStartInput.value = button.getAttribute('data-booking-start-date') || '';
            bookingEndInput.value = button.getAttribute('data-booking-end-date') || '';
            roomsInput.value = button.getAttribute('data-rooms-reserved') || '';
            syncDateLimits();
            syncReservationModal(button.getAttribute('data-room-type-id') || '');
            roomTypeSelect.value = button.getAttribute('data-room-type-id') || '';
            renderSummary();
        });
    });

    document.getElementById('reservationModal').addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger || !trigger.classList.contains('js-edit-reservation')) {
            form.dataset.editingReservation = '';
            form.dataset.assignedAthletes = '0';
            form.reset();
            bookingIdInput.value = '0';
            roomsInput.value = '1';
            hotelFilter.innerHTML = '<option value="">-- All Available Hotels --</option>';
            roomTypeSelect.innerHTML = '<option value="">-- Choose Room Type --</option>';
            syncDateLimits();
            syncReservationModal('');
        }
    });

    syncDateLimits();
    syncReservationModal('');
});
</script>

<?php
require_once 'includes/footer.php';
?>