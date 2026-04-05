<?php
require_once 'includes/auth.php';

$countryTotalsStmt = $pdo->query("SELECT
    u.id AS country_id,
    u.country_name,
    COUNT(DISTINCT b.id) AS booking_count,
    COALESCE(SUM(COALESCE(assignment_totals.assigned_athletes, 0)), 0) AS assigned_pax,
    COALESCE(SUM(COALESCE(assignment_totals.assigned_athletes, 0) * rt.price_per_night * (DATEDIFF(c.end_date, c.start_date) + 1)), 0) AS total_amount
    FROM users u
    LEFT JOIN bookings b ON b.country_id = u.id AND b.status <> 'Cancelled'
    LEFT JOIN championships c ON b.championship_id = c.id
    LEFT JOIN room_types rt ON b.room_type_id = rt.id
    LEFT JOIN (
        SELECT booking_id, COUNT(*) AS assigned_athletes
        FROM room_assignments
        GROUP BY booking_id
    ) assignment_totals ON assignment_totals.booking_id = b.id
    WHERE u.role = 'country_manager'
    GROUP BY u.id, u.country_name
    ORDER BY u.country_name ASC");
$countryTotals = $countryTotalsStmt->fetchAll(PDO::FETCH_ASSOC);

$detailStmt = $pdo->query("SELECT
    b.id,
    b.status,
    u.country_name,
    c.title AS championship_title,
    c.start_date,
    c.end_date,
    h.name AS hotel_name,
    rt.name AS room_type_name,
    rt.capacity,
    rt.price_per_night,
    COALESCE(assignment_totals.assigned_athletes, 0) AS assigned_athletes,
    (DATEDIFF(c.end_date, c.start_date) + 1) AS championship_days
    FROM bookings b
    JOIN users u ON u.id = b.country_id
    JOIN championships c ON c.id = b.championship_id
    JOIN hotels h ON h.id = b.hotel_id
    JOIN room_types rt ON rt.id = b.room_type_id
    LEFT JOIN (
        SELECT booking_id, COUNT(*) AS assigned_athletes
        FROM room_assignments
        GROUP BY booking_id
    ) assignment_totals ON assignment_totals.booking_id = b.id
    WHERE b.status <> 'Cancelled'
    ORDER BY c.start_date ASC, u.country_name ASC, h.name ASC, rt.name ASC");
$detailRows = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = 0;
$totalAssignedPax = 0;
$totalBookings = 0;

foreach ($detailRows as &$detailRow) {
    $detailRow['championship_days'] = max(1, intval($detailRow['championship_days']));
    $detailRow['line_total'] = intval($detailRow['assigned_athletes']) * floatval($detailRow['price_per_night']) * $detailRow['championship_days'];
    $grandTotal += $detailRow['line_total'];
    $totalAssignedPax += intval($detailRow['assigned_athletes']);
    $totalBookings++;
}
unset($detailRow);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Financial Report</h1>
</div>

<div class="row row-cols-1 row-cols-md-3 g-3 mb-3">
    <div class="col">
        <div class="card shadow-sm border-success">
            <div class="card-body">
                <div class="text-muted small mb-1">Grand Total</div>
                <div class="fs-4 fw-bold text-success">$<?php echo number_format($grandTotal, 2); ?></div>
                <div class="small text-muted">Assigned pax x rate per pax per day x championship days</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card shadow-sm border-primary">
            <div class="card-body">
                <div class="text-muted small mb-1">Active Bookings</div>
                <div class="fs-4 fw-bold text-primary"><?php echo $totalBookings; ?></div>
                <div class="small text-muted">Cancelled bookings excluded</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card shadow-sm border-info">
            <div class="card-body">
                <div class="text-muted small mb-1">Assigned Pax</div>
                <div class="fs-4 fw-bold text-info"><?php echo $totalAssignedPax; ?></div>
                <div class="small text-muted">Across all delegations and championships</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header">Country Totals</div>
    <div class="card-body">
        <?php if (count($countryTotals) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Country</th>
                            <th>Bookings</th>
                            <th>Assigned Pax</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($countryTotals as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($row['country_name']); ?></td>
                                <td><?php echo intval($row['booking_count']); ?></td>
                                <td><?php echo intval($row['assigned_pax']); ?></td>
                                <td class="fw-bold text-success">$<?php echo number_format($row['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No delegation financial data available yet.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header">Detailed Booking Charges</div>
    <div class="card-body">
        <?php if (count($detailRows) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Country</th>
                            <th>Championship</th>
                            <th>Hotel / Room Type</th>
                            <th>Assigned Pax</th>
                            <th>Days</th>
                            <th>Rate / Pax / Day</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailRows as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($row['country_name']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['championship_title']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars(date('M d, Y', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date']))); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['hotel_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['room_type_name'] . ' (' . $row['capacity'] . ' pax/room)'); ?></div>
                                </td>
                                <td><?php echo intval($row['assigned_athletes']); ?></td>
                                <td><?php echo intval($row['championship_days']); ?></td>
                                <td>$<?php echo number_format($row['price_per_night'], 2); ?></td>
                                <td class="fw-bold text-success">$<?php echo number_format($row['line_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No booking charges available yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>