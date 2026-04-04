<?php
require_once 'includes/auth.php';
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
                <p class="card-text fs-5 mb-0">24</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center bg-white border-primary border">
            <div class="card-body text-primary">
                <h4><i class="bi bi-hospital"></i></h4>
                <h6 class="card-title mb-1">Rooms Booked</h6>
                <p class="card-text fs-5 mb-0">12</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-center text-white bg-success">
            <div class="card-body">
                <h4><i class="bi bi-check-circle"></i></h4>
                <h6 class="card-title mb-1">Balance Due</h6>
                <p class="card-text fs-5 mb-0">$0.00</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>