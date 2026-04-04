<?php
require_once 'includes/auth.php';

// Fetch total stats
$champ_count = $pdo->query("SELECT COUNT(*) FROM championships")->fetchColumn();
$hotel_count = $pdo->query("SELECT COUNT(*) FROM hotels")->fetchColumn();

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">System Administrator Overview</h1>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-2">
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
                <p class="card-text fs-5 mb-0">0</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center bg-white border-info border">
            <div class="card-body text-info">
                <h4><i class="fas fa-file-invoice-dollar"></i></h4>
                <h6 class="card-title mb-1">Total Bookings</h6>
                <p class="card-text fs-5 mb-0">0</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>