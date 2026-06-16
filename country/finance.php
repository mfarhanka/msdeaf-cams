<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];

$roomStmt = $pdo->prepare("SELECT COALESCE(SUM(rooms_reserved), 0) FROM bookings WHERE country_id = ? AND status <> 'Cancelled'");
$roomStmt->execute([$countryId]);
$roomsBooked = $roomStmt->fetchColumn();

$financeStmt = $pdo->prepare("SELECT
    b.id,
    b.status,
    b.rooms_reserved,
    c.title AS championship_title,
    c.start_date,
    c.end_date,
    b.booking_start_date,
    b.booking_end_date,
    h.name AS hotel_name,
    rt.name AS room_type_name,
    rt.capacity,
    rt.price_per_night,
    COALESCE(assignment_totals.assigned_athletes, 0) AS assigned_athletes,
    (DATEDIFF(b.booking_end_date, b.booking_start_date) + 1) AS booking_days
    FROM bookings b
    JOIN championships c ON b.championship_id = c.id
    JOIN hotels h ON b.hotel_id = h.id
    JOIN room_types rt ON b.room_type_id = rt.id
    LEFT JOIN (
        SELECT booking_id, COUNT(*) AS assigned_athletes
        FROM room_assignments
        GROUP BY booking_id
    ) assignment_totals ON assignment_totals.booking_id = b.id
    WHERE b.country_id = ?
        AND b.status <> 'Cancelled'
    ORDER BY c.start_date ASC, h.name ASC, rt.name ASC");
$financeStmt->execute([$countryId]);
$financeRows = $financeStmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = 0;
foreach ($financeRows as &$financeRow) {
    $chargedPax = max(0, intval($financeRow['rooms_reserved'])) * max(1, intval($financeRow['capacity']));
    $lineTotal = $chargedPax * floatval($financeRow['price_per_night']) * max(1, intval($financeRow['booking_days']));
    $financeRow['charged_pax'] = $chargedPax;
    $financeRow['line_total'] = $lineTotal;
    $grandTotal += $lineTotal;
}
unset($financeRow);

$participantsStmt = $pdo->prepare("SELECT a.first_name, a.last_name, a.gender,
    ra.room_number,
    c.title AS championship_title,
    h.name AS hotel_name,
    rt.name AS room_type_name,
    b.rooms_reserved
    FROM athletes a
    LEFT JOIN room_assignments ra ON ra.athlete_id = a.id
    LEFT JOIN bookings b ON b.id = ra.booking_id AND b.status <> 'Cancelled'
    LEFT JOIN championships c ON c.id = b.championship_id
    LEFT JOIN hotels h ON h.id = b.hotel_id
    LEFT JOIN room_types rt ON rt.id = b.room_type_id
    WHERE a.country_id = ?
    ORDER BY a.last_name ASC, a.first_name ASC");
$participantsStmt->execute([$countryId]);
$participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Financial Summary</h1>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-success">
            <div class="card-body">
                <div class="text-muted small mb-1">Total Amount</div>
                <div class="fs-4 fw-bold text-success">$<?php echo number_format($grandTotal, 2); ?></div>
                <div class="small text-muted">Calculated as reserved rooms x room capacity x rate per pax per day x selected stay days</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-primary">
            <div class="card-body">
                <div class="text-muted small mb-1">Total Rooms Booked</div>
                <div class="fs-4 fw-bold text-primary"><?php echo (int) $roomsBooked; ?></div>
                <div class="small text-muted">All active room reservations for this delegation</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (count($financeRows) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Championship</th>
                            <th>Stay Dates</th>
                            <th>Hotel / Room Type</th>
                            <th>Charged Pax</th>
                            <th>Assigned Pax</th>
                            <th>Days</th>
                            <th>Rate / Pax / Day</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($financeRows as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['championship_title']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars(date('M d, Y', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date']))); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(date('M d, Y', strtotime($row['booking_start_date'])) . ' - ' . date('M d, Y', strtotime($row['booking_end_date']))); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['hotel_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['room_type_name'] . ' (' . $row['capacity'] . ' pax/room)'); ?></div>
                                </td>
                                <td><?php echo intval($row['charged_pax']); ?></td>
                                <td><?php echo intval($row['assigned_athletes']); ?></td>
                                <td><?php echo max(1, intval($row['booking_days'])); ?></td>
                                <td>$<?php echo number_format($row['price_per_night'], 2); ?></td>
                                <td class="fw-bold text-success">$<?php echo number_format($row['line_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted text-center py-5 mb-0">No active reservations yet, so there is no charge to calculate.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="card-title mb-1">Participating Athletes</h5>
                <p class="text-muted small mb-0">Delegation participants with their current room booking status.</p>
            </div>
            <a href="rooming.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-door-open me-1"></i>Manage Rooming
            </a>
        </div>

        <?php if (count($participants) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Participant</th>
                            <th>Gender</th>
                            <th>Room Booked</th>
                            <th>Total Rooms Booked</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?></td>
                                <td><?php echo htmlspecialchars($participant['gender']); ?></td>
                                <td>
                                    <?php if (!empty($participant['room_number'])): ?>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($participant['room_number']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($participant['championship_title'] ?? ''); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars(trim(($participant['hotel_name'] ?? '') . ' / ' . ($participant['room_type_name'] ?? ''))); ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($participant['rooms_reserved']) ? (int) $participant['rooms_reserved'] : 0; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No participants registered yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>