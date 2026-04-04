<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];

$athleteStmt = $pdo->prepare("SELECT COUNT(*) FROM athletes WHERE country_id = ?");
$athleteStmt->execute([$countryId]);
$athleteCount = $athleteStmt->fetchColumn();

$roomStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE country_id = ?");
$roomStmt->execute([$countryId]);
$roomsBooked = $roomStmt->fetchColumn();

$balanceStmt = $pdo->prepare("SELECT COALESCE(SUM(assignment_totals.assigned_athletes * rt.price_per_night), 0)
    FROM bookings b
    JOIN room_types rt ON b.room_type_id = rt.id
    LEFT JOIN (
        SELECT booking_id, COUNT(*) AS assigned_athletes
        FROM room_assignments
        GROUP BY booking_id
    ) assignment_totals ON assignment_totals.booking_id = b.id
    WHERE b.country_id = ? AND b.status <> 'Cancelled'");
$balanceStmt->execute([$countryId]);
$balanceDue = $balanceStmt->fetchColumn();

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Delegation Dashboard</h1>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2 mb-4">
    <div class="col">
        <div class="card text-center text-white bg-primary">
            <div class="card-body">
                <h4><i class="bi bi-people-fill"></i></h4>
                <h6 class="card-title mb-1">Registered Athletes</h6>
                <p class="card-text fs-5 mb-0"><?php echo htmlspecialchars($athleteCount); ?></p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center bg-white border-primary border">
            <div class="card-body text-primary">
                <h4><i class="bi bi-hospital"></i></h4>
                <h6 class="card-title mb-1">Rooms Booked</h6>
                <p class="card-text fs-5 mb-0"><?php echo htmlspecialchars($roomsBooked); ?></p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center text-white bg-success">
            <div class="card-body">
                <h4><i class="bi bi-check-circle"></i></h4>
                <h6 class="card-title mb-1">Daily Balance Due</h6>
                <p class="card-text fs-5 mb-0">$<?php echo number_format($balanceDue, 2); ?></p>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>