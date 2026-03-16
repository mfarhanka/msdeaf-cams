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

<div class="row">
    <!-- Stat Cards -->
    <div class="col-md-3">
        <div class="card text-center text-white bg-primary">
            <div class="card-body">
                <h3><i class="fas fa-medal"></i></h3>
                <h5 class="card-title">Championships</h5>
                <p class="card-text fs-4"><?php echo $champ_count; ?> Active</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-white border-primary border">
            <div class="card-body text-primary">
                <h3><i class="fas fa-hotel"></i></h3>
                <h5 class="card-title">Hotels</h5>
                <p class="card-text fs-4"><?php echo $hotel_count; ?> Listed</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center text-white bg-info">
            <div class="card-body">
                <h3><i class="fas fa-users"></i></h3>
                <h5 class="card-title">Total Athletes</h5>
                <p class="card-text fs-4">0</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-white border-info border">
            <div class="card-body text-info">
                <h3><i class="fas fa-file-invoice-dollar"></i></h3>
                <h5 class="card-title">Total Bookings</h5>
                <p class="card-text fs-4">0</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>