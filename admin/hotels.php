<?php
require_once 'includes/auth.php';

function formatHotelStarRatingLabel(int $starRating): string
{
    return $starRating > 0 ? str_repeat('⭐', $starRating) : 'Unrated';
}

function getSelectedChampionshipIds(PDO $pdo, array $submittedIds): array
{
    $submittedIds = array_values(array_unique(array_filter(array_map('intval', $submittedIds), static function (int $id): bool {
        return $id > 0;
    })));

    if ($submittedIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM championships WHERE id IN ($placeholders)");
    $stmt->execute($submittedIds);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function syncHotelChampionships(PDO $pdo, int $hotelId, array $championshipIds): void
{
    $pdo->prepare("DELETE FROM championship_hotels WHERE hotel_id = ?")->execute([$hotelId]);
    $insertStmt = $pdo->prepare("INSERT INTO championship_hotels (championship_id, hotel_id) VALUES (?, ?)");
    foreach ($championshipIds as $championshipId) {
        $insertStmt->execute([$championshipId, $hotelId]);
    }
}

$championships = $pdo->query("SELECT id, title, start_date, end_date FROM championships ORDER BY start_date ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);

// Handle POST actions for Hotels & Room Types
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_hotel') {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $starRating = max(0, min(5, (int) ($_POST['star_rating'] ?? 0)));
        $championshipIds = getSelectedChampionshipIds($pdo, (array) ($_POST['championship_ids'] ?? []));

        if ($name !== '' && $address !== '' && ($championships === [] || $championshipIds !== [])) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO hotels (name, address, star_rating, total_rooms) VALUES (?, ?, ?, 0)");
                $stmt->execute([$name, $address, $starRating]);
                syncHotelChampionships($pdo, (int) $pdo->lastInsertId(), $championshipIds);
                $pdo->commit();
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-hotel'></i> Hotel added and linked to the selected championships!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msg = "<div class='alert alert-danger'>Unable to add the hotel right now. Please try again.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please complete the hotel details and select at least one championship.</div>";
        }
    } elseif ($_POST['action'] === 'update_hotel_details') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $starRating = max(0, min(5, (int) ($_POST['star_rating'] ?? 0)));
        $championshipIds = getSelectedChampionshipIds($pdo, (array) ($_POST['championship_ids'] ?? []));

        if ($id > 0 && $name !== '' && $address !== '' && ($championships === [] || $championshipIds !== [])) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE hotels SET name = ?, address = ?, star_rating = ? WHERE id = ?");
                $stmt->execute([$name, $address, $starRating, $id]);
                syncHotelChampionships($pdo, $id, $championshipIds);
                $pdo->commit();
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-pen'></i> Hotel details and championship links updated!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msg = "<div class='alert alert-danger'>Unable to update the hotel right now. Please try again.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please complete the hotel details and select at least one championship.</div>";
        }
    } elseif ($_POST['action'] === 'delete_hotel') {
        $id = (int) ($_POST['id'] ?? 0);

        $bookingRefStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE hotel_id = ?");
        $bookingRefStmt->execute([$id]);
        $bookingRefCount = (int) $bookingRefStmt->fetchColumn();

        if ($bookingRefCount > 0) {
            $viewBookingsUrl = 'finance.php?hotel_id=' . urlencode((string) $id);
            $msg = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-exclamation-triangle'></i> Cannot delete hotel because it has existing booking records. <a href='" . htmlspecialchars($viewBookingsUrl) . "' class='alert-link'>View related bookings</a>.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $stmt = $pdo->prepare("DELETE FROM hotels WHERE id=?");
            if ($stmt->execute([$id])) {
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Hotel deleted!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            }
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
    } elseif ($_POST['action'] === 'update_room_price') {
        $id = (int) ($_POST['id'] ?? 0);
        $pricePerNight = number_format((float) ($_POST['price_per_night'] ?? 0), 2, '.', '');

        if ($id > 0 && (float) $pricePerNight >= 0) {
            $stmt = $pdo->prepare("UPDATE room_types SET price_per_night = ? WHERE id = ?");
            if ($stmt->execute([$pricePerNight, $id])) {
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-dollar-sign'></i> Room price updated!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            }
        }
    } elseif ($_POST['action'] === 'update_room_type_details') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $capacity = (int) ($_POST['capacity'] ?? 0);
        $pricePerNight = number_format((float) ($_POST['price_per_night'] ?? 0), 2, '.', '');
        $totalAllotment = (int) ($_POST['total_allotment'] ?? 0);

        if ($id > 0 && $name !== '' && $capacity > 0 && (float) $pricePerNight >= 0 && $totalAllotment > 0) {
            $hotelStmt = $pdo->prepare("SELECT hotel_id FROM room_types WHERE id = ? LIMIT 1");
            $hotelStmt->execute([$id]);
            $hotelId = (int) $hotelStmt->fetchColumn();

            if ($hotelId > 0) {
                $stmt = $pdo->prepare("UPDATE room_types SET name = ?, capacity = ?, price_per_night = ?, total_allotment = ? WHERE id = ?");
                if ($stmt->execute([$name, $capacity, $pricePerNight, $totalAllotment, $id])) {
                    $pdo->prepare("UPDATE hotels SET total_rooms = (SELECT COALESCE(SUM(total_allotment), 0) FROM room_types WHERE hotel_id = ?) WHERE id = ?")->execute([$hotelId, $hotelId]);
                    $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-pen'></i> Room type details updated!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
            }
        }
    } elseif ($_POST['action'] === 'delete_room_type') {
        $id = (int) ($_POST['id'] ?? 0);
        // get hotel_id before deleting
        $h_stmt = $pdo->prepare("SELECT hotel_id FROM room_types WHERE id=?");
        $h_stmt->execute([$id]);
        $hotel_id = $h_stmt->fetchColumn();

        $bookingRefStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE room_type_id = ?");
        $bookingRefStmt->execute([$id]);
        $bookingRefCount = (int) $bookingRefStmt->fetchColumn();

        if ($bookingRefCount > 0) {
            $viewBookingsUrl = 'finance.php?room_type_id=' . urlencode((string) $id);
            $msg = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-exclamation-triangle'></i> Cannot delete room type because it is already used in bookings. <a href='" . htmlspecialchars($viewBookingsUrl) . "' class='alert-link'>View related bookings</a>.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $stmt = $pdo->prepare("DELETE FROM room_types WHERE id=?");
            if ($stmt->execute([$id])) {
                // Update actual hotel total_rooms column
                if ($hotel_id) {
                    $pdo->prepare("UPDATE hotels SET total_rooms = (SELECT COALESCE(SUM(total_allotment), 0) FROM room_types WHERE hotel_id = ?) WHERE id = ?")->execute([$hotel_id, $hotel_id]);
                }
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Room type deleted!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            }
        }
    }
}

// Fetch all hotels and dynamically calculate total rooms from room types
$hotels_stmt = $pdo->query("
    SELECT h.id, h.name, h.address, h.star_rating, COALESCE(SUM(rt.total_allotment), 0) AS calculated_total_rooms 
    FROM hotels h 
    LEFT JOIN room_types rt ON h.id = rt.hotel_id 
    GROUP BY h.id, h.name, h.address, h.star_rating 
    ORDER BY h.id DESC
");
$hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

$hotelChampionshipMap = [];
foreach ($pdo->query("SELECT hotel_id, championship_id FROM championship_hotels ORDER BY championship_id")->fetchAll(PDO::FETCH_ASSOC) as $link) {
    $hotelChampionshipMap[(int) $link['hotel_id']][] = (int) $link['championship_id'];
}

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
                        <th>Star Rate</th>
                        <th>Championships</th>
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
                        <td><?php echo htmlspecialchars(formatHotelStarRatingLabel((int) $h['star_rating'])); ?></td>
                        <td>
                            <?php $linkedChampionshipIds = $hotelChampionshipMap[(int) $h['id']] ?? []; ?>
                            <?php if ($linkedChampionshipIds === []): ?>
                                <span class="badge bg-warning text-dark">Not linked</span>
                            <?php else: ?>
                                <?php echo count($linkedChampionshipIds); ?> linked
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($h['calculated_total_rooms']); ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editHotelModal<?php echo (int) $h['id']; ?>">
                                <i class="fas fa-pen"></i>
                            </button>
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

            <?php foreach ($hotels as $h): ?>
            <div class="modal fade" id="editHotelModal<?php echo (int) $h['id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">Edit Hotel</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="action" value="update_hotel_details">
                                <input type="hidden" name="id" value="<?php echo (int) $h['id']; ?>">

                                <div class="mb-3">
                                    <label class="form-label text-muted fw-bold">Hotel Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($h['name']); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-muted fw-bold">Complete Address</label>
                                    <textarea name="address" class="form-control" rows="3" required><?php echo htmlspecialchars($h['address']); ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-muted fw-bold">Star Rate</label>
                                    <select name="star_rating" class="form-select">
                                        <?php for ($starOption = 0; $starOption <= 5; $starOption++): ?>
                                            <option value="<?php echo $starOption; ?>" <?php echo (int) $h['star_rating'] === $starOption ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(formatHotelStarRatingLabel($starOption)); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <?php if ($championships !== []): ?>
                                    <div class="mb-3">
                                        <label class="form-label text-muted fw-bold d-block">Available for Championships</label>
                                        <div class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
                                            <?php $linkedIds = $hotelChampionshipMap[(int) $h['id']] ?? []; ?>
                                            <?php foreach ($championships as $championship): ?>
                                                <?php $isChecked = in_array((int) $championship['id'], $linkedIds, true); ?>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="championship_ids[]" value="<?php echo (int) $championship['id']; ?>" id="editHotel<?php echo (int) $h['id']; ?>Championship<?php echo (int) $championship['id']; ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="editHotel<?php echo (int) $h['id']; ?>Championship<?php echo (int) $championship['id']; ?>">
                                                        <?php echo htmlspecialchars($championship['title']); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="form-text">Select at least one championship.</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Hotel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
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
                        <td class="text-success fw-bold">$<?php echo htmlspecialchars(number_format((float) $rt['price_per_night'], 2, '.', '')); ?></td>
                        <td><?php echo htmlspecialchars($rt['total_allotment']); ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editRoomTypeModal<?php echo (int) $rt['id']; ?>">
                                <i class="fas fa-pen"></i>
                            </button>
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

            <?php foreach ($room_types as $rt): ?>
            <div class="modal fade" id="editRoomTypeModal<?php echo (int) $rt['id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title">Edit Room Type</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="action" value="update_room_type_details">
                                <input type="hidden" name="id" value="<?php echo (int) $rt['id']; ?>">

                                <div class="mb-3">
                                    <label class="form-label text-muted fw-bold">Hotel</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($rt['hotel_name']); ?>" disabled>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-muted fw-bold">Room Type Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($rt['name']); ?>" required>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted fw-bold">Capacity</label>
                                        <input type="number" name="capacity" class="form-control" min="1" value="<?php echo (int) $rt['capacity']; ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-muted fw-bold">Allotment</label>
                                        <input type="number" name="total_allotment" class="form-control" min="1" value="<?php echo (int) $rt['total_allotment']; ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-muted fw-bold">Price per Pax per Day ($)</label>
                                    <input type="number" step="0.01" min="0" name="price_per_night" class="form-control" value="<?php echo htmlspecialchars(number_format((float) $rt['price_per_night'], 2, '.', '')); ?>" required>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update Room Type</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
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

                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Star Rate</label>
                        <select name="star_rating" class="form-select">
                            <?php for ($starOption = 0; $starOption <= 5; $starOption++): ?>
                                <option value="<?php echo $starOption; ?>"><?php echo htmlspecialchars(formatHotelStarRatingLabel($starOption)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <?php if ($championships !== []): ?>
                        <div class="mb-3">
                            <label class="form-label text-muted fw-bold d-block">Available for Championships</label>
                            <div class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
                                <?php foreach ($championships as $championship): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="championship_ids[]" value="<?php echo (int) $championship['id']; ?>" id="addHotelChampionship<?php echo (int) $championship['id']; ?>" checked>
                                        <label class="form-check-label" for="addHotelChampionship<?php echo (int) $championship['id']; ?>">
                                            <?php echo htmlspecialchars($championship['title']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">The hotel will appear in room booking only for the selected championships.</div>
                        </div>
                    <?php endif; ?>
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
