<?php
require_once 'includes/auth.php';

function fetchAdminById(PDO $pdo, int $adminId): ?array
{
    $stmt = $pdo->prepare("SELECT id, username, status FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    return $admin ?: null;
}

function countActiveAdmins(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_admin') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username !== '' && $password !== '') {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, 'admin', 'active')");
                $stmt->execute([$username, $hash]);
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-shield'></i> Admin added successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $msg = "<div class='alert alert-warning alert-dismissible fade show'>That username is already in use.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $msg = "<div class='alert alert-danger alert-dismissible fade show'>Unable to add admin right now.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
            }
        } else {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Username and password are required.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($action === 'edit_admin') {
        $adminId = (int) ($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $admin = fetchAdminById($pdo, $adminId);

        if (!$admin) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Admin account not found.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif ($username === '') {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Username is required.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            try {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ? WHERE id = ? AND role = 'admin'");
                    $stmt->execute([$username, $hash, $adminId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ? AND role = 'admin'");
                    $stmt->execute([$username, $adminId]);
                }

                if ($adminId === (int) $_SESSION['id']) {
                    $_SESSION['username'] = $username;
                }

                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-edit'></i> Admin updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $msg = "<div class='alert alert-warning alert-dismissible fade show'>That username is already in use.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $msg = "<div class='alert alert-danger alert-dismissible fade show'>Unable to update admin right now.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
            }
        }
    } elseif ($action === 'toggle_admin_status') {
        $adminId = (int) ($_POST['id'] ?? 0);
        $admin = fetchAdminById($pdo, $adminId);

        if (!$admin) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Admin account not found.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif ($adminId === (int) $_SESSION['id']) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>You cannot suspend your own account.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif ($admin['status'] === 'active' && countActiveAdmins($pdo) <= 1) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>At least one active admin must remain.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $newStatus = $admin['status'] === 'active' ? 'suspended' : 'active';
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'admin'");
            $stmt->execute([$newStatus, $adminId]);

            $label = $newStatus === 'active' ? 'reactivated' : 'suspended';
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-lock'></i> Admin {$label} successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($action === 'delete_admin') {
        $adminId = (int) ($_POST['id'] ?? 0);
        $admin = fetchAdminById($pdo, $adminId);

        if (!$admin) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Admin account not found.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif ($adminId === (int) $_SESSION['id']) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>You cannot remove your own account.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif ($admin['status'] === 'active' && countActiveAdmins($pdo) <= 1) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>At least one active admin must remain.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
            $stmt->execute([$adminId]);
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Admin removed successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

$adminsStmt = $pdo->query(
    "SELECT id, username, status, created_at
    FROM users
    WHERE role = 'admin'
    ORDER BY username ASC"
);
$admins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC);
$activeAdminCount = countActiveAdmins($pdo);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Admin Management</h1>
        <p class="text-muted mb-0">Create, update, suspend, or remove administrator accounts.</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAdminModal">
        <i class="bi bi-plus-lg me-1"></i> Add Admin
    </button>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Total Admins</div>
                <div class="display-6 fw-semibold"><?php echo count($admins); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Active Admins</div>
                <div class="display-6 fw-semibold text-success"><?php echo $activeAdminCount; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Suspended Admins</div>
                <div class="display-6 fw-semibold text-warning"><?php echo count($admins) - $activeAdminCount; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="alert alert-info">
            Suspended admins cannot sign in. The current admin account cannot be suspended or removed, and at least one active admin must remain.
        </div>

        <?php if (count($admins) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($admin['id']); ?></td>
                                <td class="fw-bold">
                                    <?php echo htmlspecialchars($admin['username']); ?>
                                    <?php if ((int) $admin['id'] === (int) $_SESSION['id']): ?>
                                        <span class="badge bg-secondary ms-2">You</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($admin['status'] === 'active'): ?>
                                        <span class="badge rounded-pill text-bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill text-bg-warning">Suspended</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($admin['created_at']))); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAdminModal<?php echo $admin['id']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Change this admin status?');">
                                        <input type="hidden" name="action" value="toggle_admin_status">
                                        <input type="hidden" name="id" value="<?php echo $admin['id']; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $admin['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success'; ?>" <?php echo (int) $admin['id'] === (int) $_SESSION['id'] ? 'disabled' : ''; ?>>
                                            <i class="fas <?php echo $admin['status'] === 'active' ? 'fa-user-lock' : 'fa-user-check'; ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this admin account?');">
                                        <input type="hidden" name="action" value="delete_admin">
                                        <input type="hidden" name="id" value="<?php echo $admin['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" <?php echo (int) $admin['id'] === (int) $_SESSION['id'] ? 'disabled' : ''; ?>>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center mb-0">No admin accounts found.</div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addAdminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Admin</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_admin">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label text-muted fw-bold">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Admin</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($admins as $admin): ?>
    <div class="modal fade" id="editAdminModal<?php echo $admin['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-secondary text-white">
                        <h5 class="modal-title">Edit Admin</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_admin">
                        <input type="hidden" name="id" value="<?php echo $admin['id']; ?>">
                        <div class="mb-3">
                            <label class="form-label text-muted fw-bold">Username</label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($admin['username']); ?>" required>
                        </div>
                        <div class="mb-0">
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

<?php require_once 'includes/footer.php'; ?>