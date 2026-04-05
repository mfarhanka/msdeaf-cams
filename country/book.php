<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';

function syncReservedRooms(PDO $pdo, int $bookingId): void
{
    $roomsReservedStmt = $pdo->prepare("SELECT COUNT(DISTINCT room_number) FROM room_assignments WHERE booking_id = ? AND room_number IS NOT NULL AND room_number <> ''");
    $roomsReservedStmt->execute([$bookingId]);
    $roomsReserved = intval($roomsReservedStmt->fetchColumn());

    $updateBookingStmt = $pdo->prepare("UPDATE bookings SET rooms_reserved = ? WHERE id = ?");
    $updateBookingStmt->execute([$roomsReserved, $bookingId]);
}

function cleanupEmptyBookings(PDO $pdo, int $countryId): void
{
    $deleteStmt = $pdo->prepare(
        "DELETE b FROM bookings b
        LEFT JOIN room_assignments ra ON ra.booking_id = b.id
        WHERE b.country_id = ?
            AND b.status <> 'Cancelled'
            AND ra.id IS NULL"
    );
    $deleteStmt->execute([$countryId]);
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
    if ($_POST['action'] === 'assign_room') {
        $athleteId = intval($_POST['athlete_id'] ?? 0);
        $roomTypeId = intval($_POST['room_type_id'] ?? 0);
        $championshipId = intval($_POST['championship_id'] ?? 0);
        $roomGrouping = trim((string) ($_POST['room_grouping'] ?? ''));

        if ($athleteId && $roomTypeId && $championshipId) {
            $athleteCheck = $pdo->prepare("SELECT id FROM athletes WHERE id = ? AND country_id = ?");
            $athleteCheck->execute([$athleteId, $countryId]);
            if ($athleteCheck->fetch()) {
                $roomTypeCheck = $pdo->prepare("SELECT hotel_id, total_allotment, capacity FROM room_types WHERE id = ?");
                $roomTypeCheck->execute([$roomTypeId]);
                $roomTypeInfo = $roomTypeCheck->fetch(PDO::FETCH_ASSOC);
                if ($roomTypeInfo) {
                    if (!isHotelAvailableForChampionship($pdo, $championshipId, intval($roomTypeInfo['hotel_id']))) {
                        $msg = "<div class='alert alert-warning'>The selected hotel room type is not available for this championship.</div>";
                    } else {
                        $assignedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM room_assignments ra JOIN bookings bb ON ra.booking_id = bb.id WHERE bb.room_type_id = ? AND bb.status <> 'Cancelled'");
                        $assignedCountStmt->execute([$roomTypeId]);
                        $assignedAthletes = intval($assignedCountStmt->fetchColumn());
                        $capacity = max(1, intval($roomTypeInfo['capacity']));
                        $totalRooms = intval($roomTypeInfo['total_allotment']);
                        $availableSlots = ($totalRooms * $capacity) - $assignedAthletes;

                        if ($availableSlots <= 0) {
                            $msg = "<div class='alert alert-warning'>No available bed slots left for the selected hotel room type.</div>";
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
                                $bookingId = $bookingInfo['id'];
                            } else {
                                $insertBooking = $pdo->prepare("INSERT INTO bookings (championship_id, country_id, hotel_id, room_type_id, rooms_reserved, status) VALUES (?, ?, ?, ?, 0, 'Pending')");
                                $insertBooking->execute([$championshipId, $countryId, $roomTypeInfo['hotel_id'], $roomTypeId]);
                                $bookingId = $pdo->lastInsertId();
                            }

                            $existing = $pdo->prepare("SELECT id, booking_id, room_number FROM room_assignments WHERE athlete_id = ?");
                            $existing->execute([$athleteId]);
                            $existingAssignment = $existing->fetch(PDO::FETCH_ASSOC);
                            $previousBookingId = $existingAssignment ? intval($existingAssignment['booking_id']) : 0;

                            $occupancyStmt = $pdo->prepare("SELECT room_number, COUNT(*) AS occupants FROM room_assignments WHERE booking_id = ? GROUP BY room_number");
                            $occupancyStmt->execute([$bookingId]);
                            $occupancyRows = $occupancyStmt->fetchAll(PDO::FETCH_ASSOC);
                            $roomOccupancy = [];
                            foreach ($occupancyRows as $occupancyRow) {
                                $roomOccupancy[$occupancyRow['room_number']] = intval($occupancyRow['occupants']);
                            }

                            $currentRoomNumber = $existingAssignment && $previousBookingId === intval($bookingId)
                                ? trim((string) $existingAssignment['room_number'])
                                : '';
                            $roomNumber = '';

                            if ($roomGrouping === '') {
                                $msg = "<div class='alert alert-warning'>Please select a room grouping before assigning.</div>";
                            } elseif ($roomGrouping === '__new__') {
                                for ($i = 1; $i <= $totalRooms; $i++) {
                                    $candidate = 'Room ' . $i;
                                    $occupants = $roomOccupancy[$candidate] ?? 0;
                                    if ($occupants === 0) {
                                        $roomNumber = $candidate;
                                        break;
                                    }
                                }

                                if ($roomNumber === '') {
                                    $msg = "<div class='alert alert-warning'>No empty room group is available for this room type. Please choose an existing room group.</div>";
                                }
                            } else {
                                $selectedOccupants = $roomOccupancy[$roomGrouping] ?? null;
                                $isCurrentRoomSelection = $currentRoomNumber !== '' && $currentRoomNumber === $roomGrouping;

                                if ($selectedOccupants === null) {
                                    $msg = "<div class='alert alert-warning'>The selected room grouping is no longer available. Please choose again.</div>";
                                } elseif ($selectedOccupants >= $capacity && !$isCurrentRoomSelection) {
                                    $msg = "<div class='alert alert-warning'>The selected room grouping is already full.</div>";
                                } else {
                                    $roomNumber = $roomGrouping;
                                }
                            }

                            if ($roomNumber !== '') {
                                if ($existingAssignment) {
                                    $updateStmt = $pdo->prepare("UPDATE room_assignments SET booking_id = ?, room_number = ? WHERE athlete_id = ?");
                                    $updateStmt->execute([$bookingId, $roomNumber, $athleteId]);
                                } else {
                                    $insertStmt = $pdo->prepare("INSERT INTO room_assignments (booking_id, room_number, athlete_id) VALUES (?, ?, ?)");
                                    $insertStmt->execute([$bookingId, $roomNumber, $athleteId]);
                                }

                                syncReservedRooms($pdo, intval($bookingId));
                                if ($previousBookingId && $previousBookingId !== intval($bookingId)) {
                                    syncReservedRooms($pdo, $previousBookingId);
                                }
                                cleanupEmptyBookings($pdo, $countryId);

                                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle'></i> Room assigned successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                            }
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
                $bookingLookupStmt = $pdo->prepare("SELECT booking_id FROM room_assignments WHERE athlete_id = ?");
                $bookingLookupStmt->execute([$athleteId]);
                $bookingId = intval($bookingLookupStmt->fetchColumn() ?: 0);

                $deleteStmt = $pdo->prepare("DELETE FROM room_assignments WHERE athlete_id = ?");
                if ($deleteStmt->execute([$athleteId])) {
                    if ($bookingId) {
                        syncReservedRooms($pdo, $bookingId);
                    }
                    cleanupEmptyBookings($pdo, $countryId);
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

cleanupEmptyBookings($pdo, $countryId);

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
 
// Get admin-registered room types and availability based on athlete slots.
$roomTypesStmt = $pdo->prepare("SELECT rt.*, h.name AS hotel_name,
    COALESCE((SELECT COUNT(*) FROM room_assignments ra
        JOIN bookings bb ON ra.booking_id = bb.id
        WHERE bb.room_type_id = rt.id AND bb.status <> 'Cancelled'), 0) AS assigned_athletes
    FROM room_types rt
    JOIN hotels h ON rt.hotel_id = h.id
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

$roomTypeAvailability = [];
foreach ($roomTypes as $roomType) {
    $capacity = max(1, intval($roomType['capacity']));
    $assignedAthletes = intval($roomType['assigned_athletes']);
    $totalRooms = intval($roomType['total_allotment']);
    $totalSlots = $totalRooms * $capacity;
    $availableSlots = max(0, $totalSlots - $assignedAthletes);
    $roomsInUse = (int) ceil($assignedAthletes / $capacity);
    $roomsLeft = max(0, $totalRooms - $roomsInUse);

    if ($availableSlots <= 0) {
        $availabilityLabel = 'Full';
        $availabilityTone = 'danger';
    } elseif ($assignedAthletes === 0) {
        $availabilityLabel = 'Empty';
        $availabilityTone = 'success';
    } elseif ($availableSlots <= $capacity) {
        $availabilityLabel = 'Nearly Full';
        $availabilityTone = 'warning';
    } else {
        $availabilityLabel = 'Available';
        $availabilityTone = 'primary';
    }

    $roomTypeAvailability[(string) $roomType['id']] = [
        'id' => (int) $roomType['id'],
        'hotel_id' => (int) $roomType['hotel_id'],
        'hotel_name' => $roomType['hotel_name'],
        'room_type_name' => $roomType['name'],
        'capacity' => $capacity,
        'price_per_night' => (float) $roomType['price_per_night'],
        'total_allotment' => $totalRooms,
        'assigned_athletes' => $assignedAthletes,
        'available_slots' => $availableSlots,
        'rooms_left' => $roomsLeft,
        'availability_label' => $availabilityLabel,
        'availability_tone' => $availabilityTone,
    ];
}

$roomGroupRowsStmt = $pdo->prepare("SELECT
    b.championship_id,
    c.title AS championship_title,
    rt.id AS room_type_id,
    ra.room_number,
    a.first_name,
    a.last_name,
    a.gender
    FROM room_assignments ra
    JOIN bookings b ON b.id = ra.booking_id
    JOIN room_types rt ON rt.id = b.room_type_id
    JOIN athletes a ON a.id = ra.athlete_id
    JOIN championships c ON c.id = b.championship_id
    WHERE b.status <> 'Cancelled'
        AND ra.room_number IS NOT NULL
        AND ra.room_number <> ''
    ORDER BY b.championship_id ASC, rt.id ASC, ra.room_number ASC");
$roomGroupRowsStmt->execute();
$roomGroupRows = $roomGroupRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$roomGroupPreview = [];
foreach ($roomGroupRows as $roomGroupRow) {
    $roomGroupKey = $roomGroupRow['championship_id'] . '|' . $roomGroupRow['room_type_id'];
    $roomNumber = $roomGroupRow['room_number'];

    if (!isset($roomGroupPreview[$roomGroupKey])) {
        $roomGroupPreview[$roomGroupKey] = [];
    }

    if (!isset($roomGroupPreview[$roomGroupKey][$roomNumber])) {
        $roomGroupPreview[$roomGroupKey][$roomNumber] = [
            'room_number' => $roomNumber,
            'championship_title' => $roomGroupRow['championship_title'],
            'occupants' => [],
        ];
    }

    $roomGroupPreview[$roomGroupKey][$roomNumber]['occupants'][] = [
        'name' => trim($roomGroupRow['first_name'] . ' ' . $roomGroupRow['last_name']),
        'gender' => $roomGroupRow['gender'],
    ];
}

foreach ($roomGroupPreview as $roomGroupKey => $roomGroupsForType) {
    $roomGroupPreview[$roomGroupKey] = array_values($roomGroupsForType);
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Room Assignments</h1>
    <a href="rooming.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-door-open me-1"></i> View Room Grouping
    </a>
</div>

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
                <p class="text-muted small mb-3">Availability is calculated from current active room assignments.</p>
                <?php if (count($roomTypes) > 0): ?>
                    <?php foreach ($roomTypes as $rt): ?>
                        <?php
                            $totalSlots = intval($rt['total_allotment']) * max(1, intval($rt['capacity']));
                            $availableSlots = $totalSlots - intval($rt['assigned_athletes']);
                            $roomsInUse = intval(ceil(intval($rt['assigned_athletes']) / max(1, intval($rt['capacity']))));
                            $roomsLeft = max(0, intval($rt['total_allotment']) - $roomsInUse);
                        ?>
                        <div class="mb-3 p-3 border rounded">
                            <h6 class="mb-2"><?php echo htmlspecialchars($rt['hotel_name']); ?> - <?php echo htmlspecialchars($rt['name']); ?></h6>
                            <div class="text-muted small mb-2">Capacity: <?php echo htmlspecialchars($rt['capacity']); ?> person(s)</div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small><?php echo $availableSlots; ?> slot(s) left, <?php echo $roomsLeft; ?> room(s) left</small>
                                <small>$<?php echo number_format($rt['price_per_night'], 2); ?>/pax/day</small>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" class="assign-room-form" data-current-room-type-id="<?php echo htmlspecialchars((string) ($athlete['current_room_type_id'] ?? '')); ?>" data-current-room-number="<?php echo htmlspecialchars((string) ($athlete['room_number'] ?? '')); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Assign Room - <?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign_room">
                    <input type="hidden" name="athlete_id" value="<?php echo $athlete['id']; ?>">

                    <div class="mb-3">
                        <label class="form-label">Select Championship</label>
                        <select name="championship_id" class="form-select js-championship-select" required>
                            <option value="">-- Choose Championship --</option>
                            <?php foreach ($championships as $champ): ?>
                                <option value="<?php echo $champ['id']; ?>" <?php echo $athlete['current_championship_id'] === $champ['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($champ['title'] . ' (' . date('M d', strtotime($champ['start_date'])) . ' - ' . date('M d', strtotime($champ['end_date'])) . ')'); ?>
                                </option>
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
                        <label class="form-label">Select Room Grouping</label>
                        <select name="room_grouping" class="form-select js-room-grouping-select" required>
                            <option value="">-- Choose Room Grouping --</option>
                        </select>
                        <div class="form-text">Choose an existing room group to join, or start a new room group if one is available.</div>
                    </div>

                    <div class="border rounded p-3 bg-light-subtle mb-3 js-availability-summary text-muted small">
                        Select a championship and room type to review availability.
                    </div>

                    <div class="border rounded p-3 js-room-group-preview">
                        <div class="fw-semibold mb-2">Current Room Groups</div>
                        <p class="text-muted small mb-0">Select a room type to preview current occupants for this championship.</p>
                    </div>

                    <div class="alert alert-info">
                        Choose the room grouping explicitly. New room groups are available only when an empty room remains in the selected room type.
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var roomTypeAvailability = <?php echo json_encode(array_values($roomTypeAvailability), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var championshipHotelMap = <?php echo json_encode($championshipHotelMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var roomGroupPreview = <?php echo json_encode($roomGroupPreview, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function statusBadgeClass(tone) {
        if (tone === 'danger') return 'text-bg-danger';
        if (tone === 'warning') return 'text-bg-warning';
        if (tone === 'success') return 'text-bg-success';
        return 'text-bg-primary';
    }

    function formatMoney(value) {
        return '$' + Number(value).toFixed(2);
    }

    function getAllowedRoomTypes(championshipId, hotelId) {
        var mappedHotels = championshipHotelMap[String(championshipId)] || [];
        var hasMappedHotels = mappedHotels.length > 0;

        return roomTypeAvailability.filter(function (roomType) {
            var matchesChampionship = !championshipId || !hasMappedHotels || mappedHotels.indexOf(roomType.hotel_id) !== -1;
            var matchesHotel = !hotelId || roomType.hotel_id === Number(hotelId);
            return matchesChampionship && matchesHotel;
        });
    }

    function renderHotelFilter(form, championshipId, selectedRoomTypeId) {
        var hotelFilter = form.querySelector('.js-hotel-filter');
        var allowedRoomTypes = getAllowedRoomTypes(championshipId, '');
        var uniqueHotels = [];
        var seenHotels = {};
        var selectedHotelId = '';

        if (selectedRoomTypeId) {
            roomTypeAvailability.forEach(function (roomType) {
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
            uniqueHotels.push(roomType.hotel_id);

            var option = document.createElement('option');
            option.value = String(roomType.hotel_id);
            option.textContent = roomType.hotel_name;
            if (selectedHotelId && selectedHotelId === String(roomType.hotel_id)) {
                option.selected = true;
            }
            hotelFilter.appendChild(option);
        });

        if (uniqueHotels.length === 0) {
            hotelFilter.disabled = true;
            hotelFilter.innerHTML = '<option value="">No available hotels</option>';
        } else {
            hotelFilter.disabled = false;
        }
    }

    function renderRoomTypeOptions(form, championshipId, preserveRoomTypeId) {
        var hotelFilter = form.querySelector('.js-hotel-filter');
        var roomTypeSelect = form.querySelector('.js-room-type-select');
        var allowedRoomTypes = getAllowedRoomTypes(championshipId, hotelFilter.value);
        var selectedRoomTypeId = preserveRoomTypeId || roomTypeSelect.value;

        roomTypeSelect.innerHTML = '<option value="">-- Choose Room Type --</option>';

        allowedRoomTypes.forEach(function (roomType) {
            var option = document.createElement('option');
            option.value = String(roomType.id);
            option.textContent = roomType.hotel_name + ' / ' + roomType.room_type_name + ' (' + roomType.capacity + 'p) - ' + roomType.available_slots + ' slot(s) available';
            option.disabled = roomType.available_slots <= 0;
            if (selectedRoomTypeId && String(selectedRoomTypeId) === String(roomType.id) && !option.disabled) {
                option.selected = true;
            }
            roomTypeSelect.appendChild(option);
        });

        roomTypeSelect.disabled = roomTypeSelect.options.length === 1;
    }

    function renderAvailabilitySummary(form) {
        var championshipSelect = form.querySelector('.js-championship-select');
        var roomTypeSelect = form.querySelector('.js-room-type-select');
        var roomGroupingSelect = form.querySelector('.js-room-grouping-select');
        var summary = form.querySelector('.js-availability-summary');
        var submitButton = form.querySelector('button[type="submit"]');
        var selectedRoomType = null;

        roomTypeAvailability.forEach(function (roomType) {
            if (String(roomType.id) === String(roomTypeSelect.value)) {
                selectedRoomType = roomType;
            }
        });

        if (!championshipSelect.value || !selectedRoomType) {
            summary.className = 'border rounded p-3 bg-light-subtle mb-3 js-availability-summary text-muted small';
            summary.textContent = 'Select a championship and room type to review availability.';
            submitButton.disabled = true;
            return;
        }

        summary.className = 'border rounded p-3 bg-light-subtle mb-3 js-availability-summary';
        summary.innerHTML =
            '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
                '<div>' +
                    '<div class="fw-semibold">' + selectedRoomType.hotel_name + ' / ' + selectedRoomType.room_type_name + '</div>' +
                    '<div class="small text-muted">Capacity: ' + selectedRoomType.capacity + ' pax per room</div>' +
                '</div>' +
                '<span class="badge ' + statusBadgeClass(selectedRoomType.availability_tone) + '">' + selectedRoomType.availability_label + '</span>' +
            '</div>' +
            '<div class="row row-cols-2 g-2 small">' +
                '<div class="col"><div class="border rounded p-2 bg-white">Slots Left<br><strong>' + selectedRoomType.available_slots + '</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Rooms Left<br><strong>' + selectedRoomType.rooms_left + '</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Assigned Pax<br><strong>' + selectedRoomType.assigned_athletes + '</strong></div></div>' +
                '<div class="col"><div class="border rounded p-2 bg-white">Rate / Pax / Day<br><strong>' + formatMoney(selectedRoomType.price_per_night) + '</strong></div></div>' +
            '</div>' +
            '<div class="small text-muted mt-2">Availability is based on all active assignments. Occupants below are shown for the selected championship.</div>';

        submitButton.disabled = selectedRoomType.available_slots <= 0 || !roomGroupingSelect.value;
    }

    function renderRoomGroupingOptions(form) {
        var championshipSelect = form.querySelector('.js-championship-select');
        var roomTypeSelect = form.querySelector('.js-room-type-select');
        var roomGroupingSelect = form.querySelector('.js-room-grouping-select');
        var currentRoomNumber = form.getAttribute('data-current-room-number') || '';
        var groups = roomGroupPreview[String(championshipSelect.value) + '|' + String(roomTypeSelect.value)] || [];
        var selectedRoomType = null;

        roomTypeAvailability.forEach(function (roomType) {
            if (String(roomType.id) === String(roomTypeSelect.value)) {
                selectedRoomType = roomType;
            }
        });

        roomGroupingSelect.innerHTML = '<option value="">-- Choose Room Grouping --</option>';

        if (!championshipSelect.value || !selectedRoomType) {
            roomGroupingSelect.disabled = true;
            return;
        }

        groups.forEach(function (group) {
            var option = document.createElement('option');
            option.value = group.room_number;
            option.textContent = group.room_number + ' - ' + group.occupants.length + '/' + selectedRoomType.capacity + ' occupied';
            if (currentRoomNumber && currentRoomNumber === group.room_number) {
                option.selected = true;
            }
            roomGroupingSelect.appendChild(option);
        });

        if (selectedRoomType.rooms_left > 0) {
            var newOption = document.createElement('option');
            newOption.value = '__new__';
            newOption.textContent = 'Start New Room Group (' + selectedRoomType.rooms_left + ' room(s) left)';
            roomGroupingSelect.appendChild(newOption);
        }

        roomGroupingSelect.disabled = roomGroupingSelect.options.length === 1;
    }

    function renderRoomPreview(form) {
        var championshipSelect = form.querySelector('.js-championship-select');
        var roomTypeSelect = form.querySelector('.js-room-type-select');
        var roomGroupingSelect = form.querySelector('.js-room-grouping-select');
        var preview = form.querySelector('.js-room-group-preview');
        var groups = roomGroupPreview[String(championshipSelect.value) + '|' + String(roomTypeSelect.value)] || [];

        if (!championshipSelect.value || !roomTypeSelect.value) {
            preview.innerHTML = '<div class="fw-semibold mb-2">Current Room Groups</div><p class="text-muted small mb-0">Select a room type to preview current occupants for this championship.</p>';
            return;
        }

        if (groups.length === 0) {
            preview.innerHTML = '<div class="fw-semibold mb-2">Current Room Groups</div><p class="text-muted small mb-0">No athletes are assigned to this room type for the selected championship yet. This assignment will start a new room or join the first available room automatically.</p>';
            return;
        }

        var selectedGrouping = roomGroupingSelect.value;
        var html = '<div class="fw-semibold mb-2">Current Room Groups</div><div class="d-grid gap-2">';
        groups.forEach(function (group) {
            var isSelected = selectedGrouping && selectedGrouping === group.room_number;
            html += '<div class="border rounded p-2 ' + (isSelected ? 'border-primary bg-primary-subtle' : 'bg-light-subtle') + '">';
            html += '<div class="d-flex justify-content-between align-items-center mb-1"><span class="fw-semibold">' + group.room_number + '</span><span class="badge text-bg-secondary">' + group.occupants.length + ' occupant(s)</span></div>';
            html += '<ul class="list-unstyled small mb-0">';
            group.occupants.forEach(function (occupant) {
                html += '<li class="d-flex justify-content-between py-1 border-top"><span>' + occupant.name + '</span><span class="text-muted">' + occupant.gender + '</span></li>';
            });
            html += '</ul></div>';
        });
        if (selectedGrouping === '__new__') {
            html += '<div class="border rounded p-2 border-success bg-success-subtle"><div class="fw-semibold text-success">New Room Group</div><div class="small text-muted">A new empty room will be created for this athlete within the selected room type.</div></div>';
        }
        html += '</div>';
        preview.innerHTML = html;
    }

    function syncModalState(form, preserveRoomTypeId) {
        var championshipSelect = form.querySelector('.js-championship-select');
        renderHotelFilter(form, championshipSelect.value, preserveRoomTypeId);
        renderRoomTypeOptions(form, championshipSelect.value, preserveRoomTypeId);
        renderRoomGroupingOptions(form);
        renderAvailabilitySummary(form);
        renderRoomPreview(form);
    }

    document.querySelectorAll('.assign-room-form').forEach(function (form) {
        var championshipSelect = form.querySelector('.js-championship-select');
        var hotelFilter = form.querySelector('.js-hotel-filter');
        var roomTypeSelect = form.querySelector('.js-room-type-select');
        var roomGroupingSelect = form.querySelector('.js-room-grouping-select');
        var preserveRoomTypeId = form.getAttribute('data-current-room-type-id') || '';

        syncModalState(form, preserveRoomTypeId);

        championshipSelect.addEventListener('change', function () {
            syncModalState(form, roomTypeSelect.value || preserveRoomTypeId);
        });

        hotelFilter.addEventListener('change', function () {
            renderRoomTypeOptions(form, championshipSelect.value, roomTypeSelect.value);
            renderRoomGroupingOptions(form);
            renderAvailabilitySummary(form);
            renderRoomPreview(form);
        });

        roomTypeSelect.addEventListener('change', function () {
            renderRoomGroupingOptions(form);
            renderAvailabilitySummary(form);
            renderRoomPreview(form);
        });

        roomGroupingSelect.addEventListener('change', function () {
            renderAvailabilitySummary(form);
            renderRoomPreview(form);
        });
    });
});
</script>

<?php
require_once 'includes/footer.php';
?>