<?php
require_once 'includes/auth.php';

function fetchDelegationById(PDO $pdo, int $delegationId): ?array
{
    $stmt = $pdo->prepare("SELECT id, username, country_name, status, suspended_at FROM users WHERE id = ? AND role = 'country_manager' LIMIT 1");
    $stmt->execute([$delegationId]);
    $delegation = $stmt->fetch(PDO::FETCH_ASSOC);

    return $delegation ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_delegation') {
        $username = trim($_POST['username'] ?? '');
        $country_name = trim($_POST['country_name'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($country_name) && !empty($password)) {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, status, country_name) VALUES (?, ?, 'country_manager', 'active', ?)");
                $stmt->execute([$username, $hash, $country_name]);
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-plus'></i> Delegation added successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $msg = "<div class='alert alert-warning alert-dismissible fade show'>That username is already in use.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $msg = "<div class='alert alert-danger alert-dismissible fade show'>Unable to add delegation.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
            }
        } else {
            $msg = "<div class='alert alert-warning'>All fields are required for delegation creation.</div>";
        }
    } elseif ($_POST['action'] === 'edit_delegation') {
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $country_name = trim($_POST['country_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $delegation = fetchDelegationById($pdo, $id);

        if (!$delegation) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Delegation account not found.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif (!empty($username) && !empty($country_name)) {
            try {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, country_name=? WHERE id=? AND role='country_manager'");
                    $stmt->execute([$username, $hash, $country_name, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=?, country_name=? WHERE id=? AND role='country_manager'");
                    $stmt->execute([$username, $country_name, $id]);
                }
                $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-edit'></i> Delegation updated successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $msg = "<div class='alert alert-warning alert-dismissible fade show'>That username is already in use.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $msg = "<div class='alert alert-danger alert-dismissible fade show'>Unable to update delegation.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
            }
        } else {
            $msg = "<div class='alert alert-warning'>Username and country are required.</div>";
        }
    } elseif ($_POST['action'] === 'toggle_delegation_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $delegation = fetchDelegationById($pdo, $id);

        if (!$delegation) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Delegation account not found.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $newStatus = $delegation['status'] === 'active' ? 'suspended' : 'active';
            $suspendedAt = $newStatus === 'suspended' ? date('Y-m-d H:i:s') : null;
            $stmt = $pdo->prepare("UPDATE users SET status = ?, suspended_at = ? WHERE id = ? AND role = 'country_manager'");
            $stmt->execute([$newStatus, $suspendedAt, $id]);

            $label = $newStatus === 'active' ? 'reactivated' : 'suspended';
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-lock'></i> Delegation {$label} successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'delete_delegation') {
        $id = (int) ($_POST['id'] ?? 0);
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
$activeDelegationCount = 0;

foreach ($delegations as $delegation) {
    if (($delegation['status'] ?? 'active') === 'active') {
        $activeDelegationCount++;
    }
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Delegation Management</h1>
        <p class="text-muted mb-0">Manage country accounts, credentials, and access status.</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDelegationModal">
        <i class="bi bi-plus-lg me-1"></i> Add Delegation
    </button>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Total Delegations</div>
                <div class="display-6 fw-semibold"><?php echo count($delegations); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Active Delegations</div>
                <div class="display-6 fw-semibold text-success"><?php echo $activeDelegationCount; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Suspended Delegations</div>
                <div class="display-6 fw-semibold text-warning"><?php echo count($delegations) - $activeDelegationCount; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="alert alert-info">
            Suspended delegations cannot sign in until reactivated. Removing a delegation also deletes related athletes and bookings through existing foreign-key rules.
        </div>

        <?php if (count($delegations) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Country</th>
                            <th>Status</th>
                            <th>Athletes</th>
                            <th>Bookings</th>
                            <th>Audit</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($delegations as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['id']); ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($d['username']); ?></td>
                            <td><?php echo htmlspecialchars($d['country_name']); ?></td>
                            <td>
                                <?php if (($d['status'] ?? 'active') === 'active'): ?>
                                    <span class="badge rounded-pill text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill text-bg-warning">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($d['athlete_count']); ?></td>
                            <td><?php echo htmlspecialchars($d['booking_count']); ?></td>
                            <td>
                                <div class="small text-muted">Created: <?php echo htmlspecialchars(date('Y-m-d', strtotime($d['created_at']))); ?></div>
                                <?php if (!empty($d['updated_at'])): ?>
                                    <div class="small text-muted">Updated: <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($d['updated_at']))); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($d['suspended_at'])): ?>
                                    <div class="small text-warning">Suspended: <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($d['suspended_at']))); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editDelegationModal<?php echo $d['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm <?php echo ($d['status'] ?? 'active') === 'active' ? 'btn-outline-warning' : 'btn-outline-success'; ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#delegationActionModal"
                                    data-action="toggle_delegation_status"
                                    data-id="<?php echo $d['id']; ?>"
                                    data-title="<?php echo ($d['status'] ?? 'active') === 'active' ? 'Suspend Delegation' : 'Reactivate Delegation'; ?>"
                                    data-message="<?php echo htmlspecialchars(($d['status'] ?? 'active') === 'active' ? 'This delegation will no longer be able to sign in until reactivated.' : 'This delegation will regain access immediately.', ENT_QUOTES); ?>"
                                    data-button-class="<?php echo ($d['status'] ?? 'active') === 'active' ? 'btn-warning' : 'btn-success'; ?>"
                                    data-button-label="<?php echo ($d['status'] ?? 'active') === 'active' ? 'Suspend Delegation' : 'Reactivate Delegation'; ?>"
                                >
                                    <i class="fas <?php echo ($d['status'] ?? 'active') === 'active' ? 'fa-user-lock' : 'fa-user-check'; ?>"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal"
                                    data-bs-target="#delegationActionModal"
                                    data-action="delete_delegation"
                                    data-id="<?php echo $d['id']; ?>"
                                    data-title="Remove Delegation"
                                    data-message="<?php echo htmlspecialchars('This permanently removes the delegation and its related data.', ENT_QUOTES); ?>"
                                    data-button-class="btn-danger"
                                    data-button-label="Remove Delegation"
                                >
                                    <i class="fas fa-trash"></i>
                                </button>
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

<div class="modal fade" id="delegationActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="delegationActionModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="delegationActionInput">
                    <input type="hidden" name="id" id="delegationIdInput">
                    <p class="mb-0" id="delegationActionMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="delegationActionSubmit">Confirm</button>
                </div>
            </form>
        </div>
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
                        <label class="form-label text-muted fw-bold">Delegation Username</label>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var delegationActionModal = document.getElementById('delegationActionModal');
    if (!delegationActionModal) {
        return;
    }

    delegationActionModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) {
            return;
        }

        document.getElementById('delegationActionModalTitle').textContent = button.getAttribute('data-title') || 'Confirm Action';
        document.getElementById('delegationActionMessage').textContent = button.getAttribute('data-message') || '';
        document.getElementById('delegationActionInput').value = button.getAttribute('data-action') || '';
        document.getElementById('delegationIdInput').value = button.getAttribute('data-id') || '';

        var submitButton = document.getElementById('delegationActionSubmit');
        submitButton.className = 'btn ' + (button.getAttribute('data-button-class') || 'btn-danger');
        submitButton.textContent = button.getAttribute('data-button-label') || 'Confirm';
    });
});
</script>

<?php
require_once 'includes/footer.php';
?>