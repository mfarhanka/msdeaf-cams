<?php
require_once 'includes/auth.php';
require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Delegation Dashboard</h1>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-center text-white bg-primary">
            <div class="card-body">
                <h3><i class="bi bi-people-fill"></i></h3>
                <h5 class="card-title">Registered Athletes</h5>
                <p class="card-text fs-4">24</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center bg-white border-primary border">
            <div class="card-body text-primary">
                <h3><i class="bi bi-hospital"></i></h3>
                <h5 class="card-title">Rooms Booked</h5>
                <p class="card-text fs-4">12</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center text-white bg-success">
            <div class="card-body">
                <h3><i class="bi bi-check-circle"></i></h3>
                <h5 class="card-title">Balance Due</h5>
                <p class="card-text fs-4">$0.00</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>