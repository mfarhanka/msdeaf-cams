<?php
require_once 'includes/auth.php';

// Handle POST actions for Championships
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_championship') {
        $title = trim($_POST['title']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $location = trim($_POST['location']);
        
        if (!empty($title) && !empty($start_date) && !empty($end_date)) {
            $stmt = $pdo->prepare("INSERT INTO championships (title, start_date, end_date, location) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$title, $start_date, $end_date, $location])) {
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle'></i> Championship added successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $msg = "<div class='alert alert-danger'>Failed to add championship.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>Please fill in all required fields.</div>";
        }
    } elseif ($_POST['action'] === 'edit_championship') {
        $id = $_POST['id'];
        $title = trim($_POST['title']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $location = trim($_POST['location']);
        
        $stmt = $pdo->prepare("UPDATE championships SET title=?, start_date=?, end_date=?, location=? WHERE id=?");
        if ($stmt->execute([$title, $start_date, $end_date, $location, $id])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle'></i> Championship updated!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'delete_championship') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM championships WHERE id=?");
        if ($stmt->execute([$id])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Championship deleted!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// Fetch all championships
$champs_stmt = $pdo->query("SELECT * FROM championships ORDER BY start_date DESC");
$championships = $champs_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h2 class="h2 text-primary">Manage Championships</h2>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addChampionshipModal">
        <i class="fas fa-plus"></i> Add New
    </button>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (count($championships) > 0): ?>
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Dates</th>
                        <th>Location</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($championships as $c): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($c['id']); ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($c['title']); ?></td>
                        <td>
                            <?php echo date('M d, Y', strtotime($c['start_date'])) . ' - ' . date('M d, Y', strtotime($c['end_date'])); ?>
                        </td>
                        <td><?php echo htmlspecialchars($c['location']); ?></td>
                        <td>
                            <!-- Edit Button -->
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $c['id']; ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            
                            <!-- Delete Button / Form -->
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this championship?');">
                                <input type="hidden" name="action" value="delete_championship">
                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>

                            <!-- Edit Modal specific to this record -->
                            <div class="modal fade" id="editModal<?php echo $c['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title">Edit Championship</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit_championship">
                                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                                
                                                <div class="mb-3 text-start">
                                                    <label class="form-label d-block">Title</label>
                                                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($c['title']); ?>" required>
                                                </div>
                                                <div class="row text-start">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Start Date</label>
                                                        <input type="date" name="start_date" class="form-control" value="<?php echo $c['start_date']; ?>" required>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">End Date</label>
                                                        <input type="date" name="end_date" class="form-control" value="<?php echo $c['end_date']; ?>" required>
                                                    </div>
                                                </div>
                                                <div class="mb-3 text-start">
                                                    <label class="form-label">Location</label>
                                                    <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($c['location']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-info text-center">No championships have been created yet. Click "Add New" to start.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ADD New Championship Modal -->
<div class="modal fade" id="addChampionshipModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Championship</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_championship">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Championship Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g., World Deaf Athletics 2024" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">Start Date</label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold">End Date</label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Location</label>
                        <input type="text" name="location" class="form-control" placeholder="City, Country" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Championship</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>