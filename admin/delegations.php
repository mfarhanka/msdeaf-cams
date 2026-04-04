<?php
require_once 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_delegation') {
        $username = trim($_POST['username']);
        $country_name = trim($_POST['country_name']);
        $password = $_POST['password'];

        if (!empty($username) && !empty($country_name) && !empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, country_name) VALUES (?, ?, 'country_manager', ?)");
            if ($stmt->execute([$username, $hash, $country_name])) {
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-plus'></i> Delegation added successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $msg = "<div class='alert alert-danger'>Unable to add delegation.</div>";
            }
        } else {
            $msg = "<div class='alert alert-warning'>All fields are required for delegation creation.</div>";
        }
    } elseif ($_POST['action'] === 'edit_delegation') {
        $id = $_POST['id'];
        $username = trim($_POST['username']);
        $country_name = trim($_POST['country_name']);
        $password = $_POST['password'];

        if (!empty($username) && !empty($country_name)) {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, country_name=? WHERE id=? AND role='country_manager'");
                $stmt->execute([$username, $hash, $country_name, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username=?, country_name=? WHERE id=? AND role='country_manager'");
                $stmt->execute([$username, $country_name, $id]);
            }
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-edit'></i> Delegation updated successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $msg = "<div class='alert alert-warning'>Username and country are required.</div>";
        }
    } elseif ($_POST['action'] === 'delete_delegation') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND role='country_manager'");
        if ($stmt->execute([$id])) {
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Delegation removed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

$delegations_stmt = $pdo->query(
    "SELECT u.*, 
        (SELECT COUNT(*) FROM athletes WHERE country_id = u.id) AS athlete_count,
        (SELECT COUNT(*) FROM bookings WHERE country_id = u.id) AS booking_count
    FROM users u
    WHERE role = 'country_manager'
    ORDER BY u.username ASC"
);
$delegations = $delegations_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Delegation Management</h1>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDelegationModal">
        <i class="bi bi-plus-lg me-1"></i> Add Delegation
    </button>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (count($delegations) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Country</th>
                            <th>Athletes</th>
                            <th>Bookings</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($delegations as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['id']); ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($d['username']); ?></td>
                            <td><?php echo htmlspecialchars($d['country_name']); ?></td>
                            <td><?php echo htmlspecialchars($d['athlete_count']); ?></td>
                            <td><?php echo htmlspecialchars($d['booking_count']); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editDelegationModal<?php echo $d['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this delegation?');">
                                    <input type="hidden" name="action" value="delete_delegation">
                                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center">No delegations found yet. Add one to get started.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Delegation Modal -->
<div class="modal fade" id="addDelegationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Delegation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_delegation">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Delegation Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Country Name</label>
                        <input type="text" name="country_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Delegation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($delegations as $d): ?>
<div class="modal fade" id="editDelegationModal<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title">Edit Delegation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_delegation">
                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Username</label>
                        <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($d['username']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Country Name</label>
                        <input type="text" name="country_name" class="form-control" value="<?php echo htmlspecialchars($d['country_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Password (leave blank to keep current)</label>
                        <input type="password" name="password" class="form-control">
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