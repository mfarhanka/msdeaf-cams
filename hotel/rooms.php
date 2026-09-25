<?php
require_once __DIR__ . '/includes/auth.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_room_number') {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $oldRoomNumber = trim((string) ($_POST['old_room_number'] ?? ''));
    $newRoomNumber = trim((string) ($_POST['room_number'] ?? ''));

    if ($bookingId <= 0 || $oldRoomNumber === '' || $newRoomNumber === '') {
        $msg = '<div class="alert alert-warning">Please enter a room number.</div>';
    } elseif (strlen($newRoomNumber) > 20) {
        $msg = '<div class="alert alert-warning">Room number must be 20 characters or fewer.</div>';
    } else {
        $groupStmt = $pdo->prepare("SELECT COUNT(*) FROM room_assignments ra
            JOIN bookings b ON b.id = ra.booking_id
            WHERE b.id = ? AND b.hotel_id = ? AND b.status <> 'Cancelled' AND ra.room_number = ?");
        $groupStmt->execute([$bookingId, $hotelId, $oldRoomNumber]);
        $groupCount = (int) $groupStmt->fetchColumn();

        $duplicateStmt = $pdo->prepare("SELECT COUNT(*) FROM room_assignments ra
            JOIN bookings b ON b.id = ra.booking_id
            JOIN bookings target ON target.id = ?
            WHERE b.hotel_id = ? AND b.status <> 'Cancelled' AND ra.room_number = ?
                AND NOT (b.id = ? AND ra.room_number = ?)
                AND b.booking_start_date < target.booking_end_date
                AND b.booking_end_date > target.booking_start_date");
        $duplicateStmt->execute([$bookingId, $hotelId, $newRoomNumber, $bookingId, $oldRoomNumber]);

        if ($groupCount === 0) {
            $msg = '<div class="alert alert-danger">That room group is no longer available.</div>';
        } elseif ((int) $duplicateStmt->fetchColumn() > 0) {
            $msg = '<div class="alert alert-warning">That room number is already assigned during an overlapping stay.</div>';
        } else {
            $updateStmt = $pdo->prepare("UPDATE room_assignments ra
                JOIN bookings b ON b.id = ra.booking_id
                SET ra.room_number = ?
                WHERE b.id = ? AND b.hotel_id = ? AND b.status <> 'Cancelled' AND ra.room_number = ?");
            $updateStmt->execute([$newRoomNumber, $bookingId, $hotelId, $oldRoomNumber]);

            $actor = getActorDetailsFromSession();
            recordActivity($pdo, 'hotel_room_number_updated', 'booking', $bookingId,
                'Hotel assigned a physical room number.',
                ['hotel_id' => $hotelId, 'old_room_number' => $oldRoomNumber, 'new_room_number' => $newRoomNumber, 'guest_count' => $groupCount],
                $actor['id'], $actor['role'], $actor['username']);
            $msg = '<div class="alert alert-success">Room number saved for the whole room group.</div>';
        }
    }
}

$roomGroups = [];
$roomLoadError = '';
try {
    $rowsStmt = $pdo->prepare("SELECT b.id AS booking_id, c.title AS championship_title,
            u.country_name, u.username AS delegation_username, rt.name AS room_type_name, rt.capacity,
            ra.room_number, a.first_name, a.last_name
        FROM room_assignments ra
        JOIN bookings b ON b.id = ra.booking_id
        JOIN athletes a ON a.id = ra.athlete_id
        JOIN users u ON u.id = b.country_id
        JOIN championships c ON c.id = b.championship_id
        JOIN room_types rt ON rt.id = b.room_type_id
        WHERE b.hotel_id = ? AND b.status <> 'Cancelled'
            AND ra.room_number IS NOT NULL AND ra.room_number <> ''
        ORDER BY c.start_date, u.country_name, u.username, b.id, ra.room_number, a.last_name, a.first_name");
    $rowsStmt->execute([$hotelId]);

    foreach ($rowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $groupKey = (string) $row['booking_id'] . '|' . (string) $row['room_number'];
        if (!isset($roomGroups[$groupKey])) {
            $roomGroups[$groupKey] = [
                'booking_id' => (int) $row['booking_id'],
                'championship_title' => $row['championship_title'],
                'country_name' => $row['country_name'],
                'delegation_username' => $row['delegation_username'],
                'room_type_name' => $row['room_type_name'],
                'capacity' => (int) $row['capacity'],
                'room_number' => $row['room_number'],
                'guest_count' => 0,
                'guest_names' => [],
            ];
        }
        $roomGroups[$groupKey]['guest_count']++;
        $roomGroups[$groupKey]['guest_names'][] = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    }

    foreach ($roomGroups as &$roomGroup) {
        $roomGroup['guest_names'] = implode(', ', $roomGroup['guest_names']);
    }
    unset($roomGroup);
    $roomGroups = array_values($roomGroups);
} catch (PDOException $exception) {
    error_log('Hotel room list failed: ' . $exception->getMessage());
    $roomLoadError = 'Room assignments cannot be loaded right now. Please contact the administrator.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hotel Room Numbers - CAMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark" style="background:#004a99">
    <div class="container-fluid px-3 px-md-4">
        <span class="navbar-brand"><i class="bi bi-building-check me-2"></i><?php echo htmlspecialchars($currentUser['hotel_name']); ?></span>
        <div class="d-flex align-items-center gap-3 text-white"><span class="d-none d-md-inline"><?php echo htmlspecialchars($currentUser['username']); ?></span><a class="btn btn-outline-light btn-sm" href="../logout.php">Logout</a></div>
    </div>
</nav>
<main class="container py-4">
    <div class="mb-4"><h1 class="h3 mb-1">Room Number Entry</h1><p class="text-muted mb-0">Enter only the hotel's physical room number for each prepared guest group.</p></div>
    <?php echo $msg; ?>
    <?php if ($roomLoadError !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($roomLoadError); ?></div>
    <?php elseif ($roomGroups === []): ?>
        <div class="alert alert-info">No guest room groups are ready for this hotel yet.</div>
    <?php else: ?>
        <div class="row g-3">
        <?php foreach ($roomGroups as $group): ?>
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100"><div class="card-body">
                    <div class="d-flex justify-content-between gap-2 mb-2"><div><h2 class="h5 mb-1"><?php echo htmlspecialchars($group['country_name'] ?: $group['delegation_username']); ?></h2><div class="text-muted small"><?php echo htmlspecialchars($group['championship_title']); ?> · <?php echo htmlspecialchars($group['room_type_name']); ?></div></div><span class="badge text-bg-secondary align-self-start"><?php echo (int) $group['guest_count']; ?>/<?php echo (int) $group['capacity']; ?> guests</span></div>
                    <div class="small mb-3"><strong>Guests:</strong> <?php echo htmlspecialchars($group['guest_names']); ?></div>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="update_room_number">
                        <input type="hidden" name="booking_id" value="<?php echo (int) $group['booking_id']; ?>">
                        <input type="hidden" name="old_room_number" value="<?php echo htmlspecialchars($group['room_number']); ?>">
                        <div class="col"><label class="form-label fw-semibold">Room number</label><input class="form-control" name="room_number" maxlength="20" value="<?php echo htmlspecialchars($group['room_number']); ?>" required></div>
                        <div class="col-auto"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button></div>
                    </form>
                </div></div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
