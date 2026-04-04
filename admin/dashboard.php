<?php
require_once 'includes/auth.php';

// Fetch total stats
$champ_count = $pdo->query("SELECT COUNT(*) FROM championships")->fetchColumn();
$hotel_count = $pdo->query("SELECT COUNT(*) FROM hotels")->fetchColumn();
$athlete_count = $pdo->query("SELECT COUNT(*) FROM athletes")->fetchColumn();
$booking_count = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status <> 'Cancelled'")->fetchColumn();

$genderStmt = $pdo->query("SELECT gender, COUNT(*) AS total FROM athletes GROUP BY gender");
$genderTotals = [
    'M' => 0,
    'F' => 0,
    'Other' => 0,
];

foreach ($genderStmt->fetchAll(PDO::FETCH_ASSOC) as $genderRow) {
    $genderKey = $genderRow['gender'];
    if (array_key_exists($genderKey, $genderTotals)) {
        $genderTotals[$genderKey] = intval($genderRow['total']);
    }
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">System Administrator Overview</h1>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-2 mb-3">
    <!-- Stat Cards -->
    <div class="col">
        <div class="card text-center text-white bg-primary">
            <div class="card-body">
                <h4><i class="fas fa-medal"></i></h4>
                <h6 class="card-title mb-1">Championships</h6>
                <p class="card-text fs-5 mb-0"><?php echo $champ_count; ?> Active</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center bg-white border-primary border">
            <div class="card-body text-primary">
                <h4><i class="fas fa-hotel"></i></h4>
                <h6 class="card-title mb-1">Hotels</h6>
                <p class="card-text fs-5 mb-0"><?php echo $hotel_count; ?> Listed</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center text-white bg-info">
            <div class="card-body">
                <h4><i class="fas fa-users"></i></h4>
                <h6 class="card-title mb-1">Total Athletes</h6>
                <p class="card-text fs-5 mb-0"><?php echo $athlete_count; ?></p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center bg-white border-info border">
            <div class="card-body text-info">
                <h4><i class="fas fa-file-invoice-dollar"></i></h4>
                <h6 class="card-title mb-1">Total Bookings</h6>
                <p class="card-text fs-5 mb-0"><?php echo $booking_count; ?></p>
            </div>
        </div>
    </div>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-2">
    <div class="col">
        <div class="card text-center bg-white border-success border">
            <div class="card-body text-success">
                <h4><i class="fas fa-mars"></i></h4>
                <h6 class="card-title mb-1">Male Athletes</h6>
                <p class="card-text fs-5 mb-0"><?php echo $genderTotals['M']; ?></p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center bg-white border-danger border">
            <div class="card-body text-danger">
                <h4><i class="fas fa-venus"></i></h4>
                <h6 class="card-title mb-1">Female Athletes</h6>
                <p class="card-text fs-5 mb-0"><?php echo $genderTotals['F']; ?></p>
            </div>
        </div>
    </div>
    <?php if ($genderTotals['Other'] > 0): ?>
    <div class="col">
        <div class="card text-center bg-white border-secondary border">
            <div class="card-body text-secondary">
                <h4><i class="fas fa-user"></i></h4>
                <h6 class="card-title mb-1">Other Gender</h6>
                <p class="card-text fs-5 mb-0"><?php echo $genderTotals['Other']; ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
require_once 'includes/footer.php';
?>