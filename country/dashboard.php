<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];

$athleteStmt = $pdo->prepare("SELECT COUNT(*) FROM athletes WHERE country_id = ?");
$athleteStmt->execute([$countryId]);
$athleteCount = $athleteStmt->fetchColumn();

$roomStmt = $pdo->prepare("SELECT COALESCE(SUM(rooms_reserved), 0) FROM bookings WHERE country_id = ? AND status <> 'Cancelled'");
$roomStmt->execute([$countryId]);
$roomsBooked = $roomStmt->fetchColumn();

$balanceStmt = $pdo->prepare("SELECT COALESCE(SUM(assignment_totals.assigned_athletes * rt.price_per_night * (DATEDIFF(c.end_date, c.start_date) + 1)), 0)
    FROM bookings b
    JOIN championships c ON b.championship_id = c.id
    JOIN room_types rt ON b.room_type_id = rt.id
    LEFT JOIN (
        SELECT booking_id, COUNT(*) AS assigned_athletes
        FROM room_assignments
        GROUP BY booking_id
    ) assignment_totals ON assignment_totals.booking_id = b.id
    WHERE b.country_id = ? AND b.status <> 'Cancelled'");
$balanceStmt->execute([$countryId]);
$balanceDue = $balanceStmt->fetchColumn();

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
                <h6 class="card-title mb-1">Total Balance Due</h6>
                <p class="card-text fs-5 mb-0">$<?php echo number_format($balanceDue, 2); ?></p>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="card-title mb-1">Participating Athletes</h5>
                <p class="text-muted small mb-0">Current delegation participants with their room booking status.</p>
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
                                        <div class="small text-muted"><?php echo htmlspecialchars(($participant['championship_title'] ?? '')); ?></div>
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