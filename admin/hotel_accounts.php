<?php
require_once __DIR__ . '/includes/auth.php';

$actor = getActorDetailsFromSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $accountId = (int) ($_POST['id'] ?? 0);

    if ($action === 'save_hotel_account') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $hotelId = (int) ($_POST['hotel_id'] ?? 0);
        $hotelStmt = $pdo->prepare('SELECT name FROM hotels WHERE id = ?');
        $hotelStmt->execute([$hotelId]);
        $hotelName = $hotelStmt->fetchColumn();

        if ($username === '' || $hotelId <= 0 || !$hotelName || ($accountId === 0 && $password === '')) {
            $msg = '<div class="alert alert-warning">Hotel, username, and a password for new accounts are required.</div>';
        } else {
            try {
                if ($accountId > 0) {
                    if ($password !== '') {
                        $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, hotel_id = ? WHERE id = ? AND role = 'hotel'");
                        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $hotelId, $accountId]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET username = ?, hotel_id = ? WHERE id = ? AND role = 'hotel'");
                        $stmt->execute([$username, $hotelId, $accountId]);
                    }
                    $activityAction = 'hotel_account_updated';
                    $message = 'Hotel account updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, role, status, hotel_id) VALUES (?, ?, 'hotel', 'active', ?)");
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $hotelId]);
                    $accountId = (int) $pdo->lastInsertId();
                    $activityAction = 'hotel_account_created';
                    $message = 'Hotel account created.';
                }
                recordActivity($pdo, $activityAction, 'user', $accountId, $message,
                    ['username' => $username, 'hotel_id' => $hotelId, 'hotel_name' => $hotelName, 'password_changed' => $password !== ''],
                    $actor['id'], $actor['role'], $actor['username']);
                $msg = '<div class="alert alert-success">' . htmlspecialchars($message) . '</div>';
            } catch (PDOException $exception) {
                $msg = $exception->getCode() === '23000'
                    ? '<div class="alert alert-warning">That username is already in use.</div>'
                    : '<div class="alert alert-danger">Unable to save the hotel account.</div>';
            }
        }
    } elseif ($action === 'toggle_hotel_account') {
        $stmt = $pdo->prepare("SELECT username, status, hotel_id FROM users WHERE id = ? AND role = 'hotel'");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account) {
            $newStatus = $account['status'] === 'active' ? 'suspended' : 'active';
            $pdo->prepare("UPDATE users SET status = ?, suspended_at = ? WHERE id = ? AND role = 'hotel'")
                ->execute([$newStatus, $newStatus === 'suspended' ? date('Y-m-d H:i:s') : null, $accountId]);
            recordActivity($pdo, 'hotel_account_' . ($newStatus === 'active' ? 'reactivated' : 'suspended'), 'user', $accountId,
                'Hotel account status updated.', ['username' => $account['username'], 'status' => $newStatus],
                $actor['id'], $actor['role'], $actor['username']);
            $msg = '<div class="alert alert-success">Hotel account ' . htmlspecialchars($newStatus) . '.</div>';
        }
    }
}

$hotels = $pdo->query('SELECT id, name FROM hotels ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$accounts = $pdo->query("SELECT u.id, u.username, u.status, u.hotel_id, u.created_at, h.name AS hotel_name
    FROM users u LEFT JOIN hotels h ON h.id = u.hotel_id WHERE u.role = 'hotel' ORDER BY h.name, u.username")
    ->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
    <div><h1 class="h2 mb-1">Hotel Accounts</h1><p class="text-muted mb-0">Create restricted logins that can only enter room numbers for one hotel.</p></div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#accountModal"><i class="bi bi-plus-lg me-1"></i>Add Account</button>
</div>

<?php if ($accounts === []): ?>
    <div class="alert alert-info">No hotel login accounts have been created yet.</div>
<?php else: ?>
<div class="card shadow-sm"><div class="card-body"><div class="table-responsive">
    <table class="table align-middle mb-0"><thead><tr><th>Hotel</th><th>Username</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($accounts as $account): ?>
        <tr>
            <td class="fw-semibold"><?php echo htmlspecialchars($account['hotel_name'] ?? 'Hotel removed'); ?></td>
            <td><?php echo htmlspecialchars($account['username']); ?></td>
            <td><span class="badge <?php echo $account['status'] === 'active' ? 'text-bg-success' : 'text-bg-warning'; ?>"><?php echo htmlspecialchars(ucfirst($account['status'])); ?></span></td>
            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($account['created_at']))); ?></td>
            <td class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAccount<?php echo (int) $account['id']; ?>"><i class="bi bi-pencil"></i></button>
                <form method="post"><input type="hidden" name="action" value="toggle_hotel_account"><input type="hidden" name="id" value="<?php echo (int) $account['id']; ?>"><button class="btn btn-sm btn-outline-<?php echo $account['status'] === 'active' ? 'warning' : 'success'; ?>"><?php echo $account['status'] === 'active' ? 'Suspend' : 'Activate'; ?></button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
</div></div></div>
<?php endif; ?>

<?php
$modalAccounts = array_merge([['id' => 0, 'username' => '', 'hotel_id' => 0]], $accounts);
foreach ($modalAccounts as $account):
    $isNew = (int) $account['id'] === 0;
    $modalId = $isNew ? 'accountModal' : 'editAccount' . (int) $account['id'];
?>
<div class="modal fade" id="<?php echo $modalId; ?>" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $isNew ? 'Add Hotel Account' : 'Edit Hotel Account'; ?></h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="action" value="save_hotel_account"><input type="hidden" name="id" value="<?php echo (int) $account['id']; ?>">
        <div class="mb-3"><label class="form-label">Hotel</label><select class="form-select" name="hotel_id" required><option value="">Choose hotel</option><?php foreach ($hotels as $hotel): ?><option value="<?php echo (int) $hotel['id']; ?>" <?php echo (int) $account['hotel_id'] === (int) $hotel['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($hotel['name']); ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" maxlength="50" value="<?php echo htmlspecialchars($account['username']); ?>" required></div>
        <div><label class="form-label">Password</label><input class="form-control" type="password" name="password" <?php echo $isNew ? 'required' : ''; ?>><div class="form-text"><?php echo $isNew ? 'Set the initial password.' : 'Leave blank to keep the current password.'; ?></div></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Account</button></div>
</form></div></div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
