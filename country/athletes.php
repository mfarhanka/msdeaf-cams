<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];

// Handle POST actions for athletes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_athlete') {
        $firstName = trim($_POST['first_name']);
        $lastName = trim($_POST['last_name']);
        $gender = $_POST['gender'];

        if (!empty($firstName) && !empty($lastName) && !empty($gender)) {
            $stmt = $pdo->prepare("INSERT INTO athletes (country_id, first_name, last_name, gender, sport_category, passport_number) VALUES (?, ?, ?, ?, '', '')");
            if ($stmt->execute([$countryId, $firstName, $lastName, $gender])) {
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-plus'></i> Athlete added successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $msg = "<div class='alert alert-danger'>Failed to add athlete.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please fill in all required fields.</div>";
        }
    } elseif ($_POST['action'] === 'edit_athlete') {
        $id = $_POST['id'];
        $firstName = trim($_POST['first_name']);
        $lastName = trim($_POST['last_name']);
        $gender = $_POST['gender'];

        if (!empty($firstName) && !empty($lastName) && !empty($gender)) {
            $stmt = $pdo->prepare("UPDATE athletes SET first_name=?, last_name=?, gender=? WHERE id=? AND country_id=?");
            if ($stmt->execute([$firstName, $lastName, $gender, $id, $countryId])) {
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-edit'></i> Athlete updated successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $msg = "<div class='alert alert-danger'>Failed to update athlete.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please fill in all required fields.</div>";
        }
    } elseif ($_POST['action'] === 'delete_athlete') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM athletes WHERE id=? AND country_id=?");
        if ($stmt->execute([$id, $countryId])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Athlete removed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// Fetch athletes for this country
$athletesStmt = $pdo->prepare("
    SELECT a.*, 
        CASE WHEN ra.room_number IS NOT NULL THEN CONCAT('Room ', ra.room_number) ELSE 'Unassigned' END AS room_assignment
    FROM athletes a 
    LEFT JOIN room_assignments ra ON a.id = ra.athlete_id 
    WHERE a.country_id = ? 
    ORDER BY a.last_name ASC, a.first_name ASC
");
$athletesStmt->execute([$countryId]);
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Athlete & Official Roster</h1>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAthleteModal">
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
                        <th>Room Assignment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($athletes) > 0): ?>
                        <?php foreach ($athletes as $athlete): ?>
                        <tr class="athlete-row">
                            <td><?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($athlete['gender']); ?></td>
                            <td>
                                <?php if ($athlete['room_assignment'] === 'Unassigned'): ?>
                                    <span class="badge bg-warning text-dark">Unassigned</span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($athlete['room_assignment']); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-link btn-sm p-0" data-bs-toggle="modal" data-bs-target="#editAthleteModal<?php echo $athlete['id']; ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this athlete?');">
                                    <input type="hidden" name="action" value="delete_athlete">
                                    <input type="hidden" name="id" value="<?php echo $athlete['id']; ?>">
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">No athletes registered yet. Click "Add Athlete" to get started.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Athlete Modal -->
<div class="modal fade" id="addAthleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Athlete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_athlete">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Gender</label>
                        <select name="gender" class="form-select" required>
                            <option value="">-- Select Gender --</option>
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Athlete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($athletes as $athlete): ?>
<div class="modal fade" id="editAthleteModal<?php echo $athlete['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title">Edit Athlete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_athlete">
                    <input type="hidden" name="id" value="<?php echo $athlete['id']; ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">First Name</label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($athlete['first_name']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">Last Name</label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($athlete['last_name']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Gender</label>
                        <select name="gender" class="form-select" required>
                            <option value="M" <?php echo $athlete['gender'] === 'M' ? 'selected' : ''; ?>>Male</option>
                            <option value="F" <?php echo $athlete['gender'] === 'F' ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo $athlete['gender'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
require_once 'includes/footer.php';
?>