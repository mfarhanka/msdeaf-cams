<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];

$financeStmt = $pdo->prepare("SELECT
    b.id,
    b.status,
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
        AND COALESCE(assignment_totals.assigned_athletes, 0) > 0
    ORDER BY c.start_date ASC, h.name ASC, rt.name ASC");
$financeStmt->execute([$countryId]);
$financeRows = $financeStmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = 0;
foreach ($financeRows as &$financeRow) {
    $lineTotal = intval($financeRow['assigned_athletes']) * floatval($financeRow['price_per_night']) * max(1, intval($financeRow['championship_days']));
    $financeRow['line_total'] = $lineTotal;
    $grandTotal += $lineTotal;
}
unset($financeRow);

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
                <div class="small text-muted">Calculated as assigned athletes x rate per pax per day x championship days</div>
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
                            <th>Hotel / Room Type</th>
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
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['hotel_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['room_type_name'] . ' (' . $row['capacity'] . ' pax/room)'); ?></div>
                                </td>
                                <td><?php echo intval($row['assigned_athletes']); ?></td>
                                <td><?php echo max(1, intval($row['championship_days'])); ?></td>
                                <td>$<?php echo number_format($row['price_per_night'], 2); ?></td>
                                <td class="fw-bold text-success">$<?php echo number_format($row['line_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted text-center py-5 mb-0">No assigned rooms yet, so there is no charge to calculate.</p>
        <?php endif; ?>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>