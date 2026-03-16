<?php
require_once 'includes/auth.php';
require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Athlete & Official Roster</h1>
    <button class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus me-1"></i> Add Athlete
    </button>
</div>

<div class="card" id="athletes">
    <div class="card-body">
        <p class="text-muted small mb-3">Manage your delegation. Ensure gender is accurate for rooming assignments.</p>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>Sport / Category</th>
                        <th>Passport ID</th>
                        <th>Room Assignment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="athlete-row">
                        <td>John Doe</td>
                        <td>M</td>
                        <td>Athletics - 100m</td>
                        <td><span class="badge bg-secondary">Encrypted</span></td>
                        <td><span class="badge bg-warning text-dark">Unassigned</span></td>
                        <td>
                            <button class="btn btn-link btn-sm p-0"><i class="bi bi-pencil"></i></button>
                        </td>
                    </tr>
                    <tr class="athlete-row">
                        <td>Jane Smith</td>
                        <td>F</td>
                        <td>Swimming - Freestyle</td>
                        <td><span class="badge bg-secondary">Encrypted</span></td>
                        <td>Room 101 (Hotel A)</td>
                        <td>
                            <button class="btn btn-link btn-sm p-0"><i class="bi bi-pencil"></i></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>