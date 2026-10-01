<?php
require_once __DIR__ . '/includes/auth.php';

$actor = getActorDetailsFromSession();

if (empty($_SESSION['hotel_impersonation_csrf'])) {
    $_SESSION['hotel_impersonation_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $accountId = (int) ($_POST['id'] ?? 0);

    if ($action === 'impersonate_hotel') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        $stmt = $pdo->prepare("SELECT u.id, u.username, u.status, u.hotel_id, h.name AS hotel_name
            FROM users u LEFT JOIN hotels h ON h.id = u.hotel_id
            WHERE u.id = ? AND u.role = 'hotel' LIMIT 1");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!hash_equals((string) $_SESSION['hotel_impersonation_csrf'], $csrfToken)) {
            $msg = '<div class="alert alert-danger">The login request expired. Please try again.</div>';
        } elseif (!$account || empty($account['hotel_id']) || empty($account['hotel_name'])) {
            $msg = '<div class="alert alert-warning">Hotel account not found or is no longer linked to a hotel.</div>';
        } elseif (($account['status'] ?? 'active') !== 'active') {
            $msg = '<div class="alert alert-warning">Suspended hotel accounts cannot be opened.</div>';
        } else {
            recordActivity($pdo, 'hotel_impersonation_started', 'user', $accountId,
                'Administrator opened the hotel portal as this account.',
                ['hotel_id' => (int) $account['hotel_id'], 'hotel_name' => $account['hotel_name'], 'hotel_username' => $account['username']],
                $actor['id'], $actor['role'], $actor['username']);

            $_SESSION['impersonator_admin'] = [
                'id' => (int) $actor['id'],
                'username' => (string) $actor['username'],
                'role' => 'admin',
            ];
            $_SESSION['impersonation_context'] = [
                'target_role' => 'hotel',
                'target_username' => (string) $account['username'],
                'return_path' => 'admin/hotel_accounts.php',
            ];
            $_SESSION['loggedin'] = true;
            $_SESSION['id'] = (int) $account['id'];
            $_SESSION['username'] = (string) $account['username'];
            $_SESSION['role'] = 'hotel';
            unset($_SESSION['hotel_impersonation_csrf'], $_SESSION['show_login_announcement']);
            $_SESSION['impersonation_return_csrf'] = bin2hex(random_bytes(32));
            session_regenerate_id(true);

            header('location: ../hotel/rooms.php');
            exit;
        }
    } elseif ($action === 'save_hotel_account') {
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
    } elseif ($action === 'remove_hotel_account') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals((string) $_SESSION['hotel_impersonation_csrf'], $csrfToken)) {
            $msg = '<div class="alert alert-danger">The removal request expired. Please try again.</div>';
        } elseif ($accountId <= 0) {
            $msg = '<div class="alert alert-warning">Invalid hotel account.</div>';
        } else {
            $stmt = $pdo->prepare("SELECT u.username, u.hotel_id, h.name AS hotel_name
                FROM users u LEFT JOIN hotels h ON h.id = u.hotel_id
                WHERE u.id = ? AND u.role = 'hotel' LIMIT 1");
            $stmt->execute([$accountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                $msg = '<div class="alert alert-warning">Hotel account not found or already removed.</div>';
            } else {
                $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'hotel'");
                $deleteStmt->execute([$accountId]);

                if ($deleteStmt->rowCount() === 1) {
                    recordActivity($pdo, 'hotel_account_removed', 'user', $accountId,
                        'Hotel portal access removed by administrator.', [
                            'username' => $account['username'],
                            'hotel_id' => (int) ($account['hotel_id'] ?? 0),
                            'hotel_name' => $account['hotel_name'],
                        ], $actor['id'], $actor['role'], $actor['username']);
                    $msg = '<div class="alert alert-success">Hotel access removed for ' . htmlspecialchars($account['username']) . '.</div>';
                } else {
                    $msg = '<div class="alert alert-danger">Unable to remove the hotel account.</div>';
                }
            }
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
            <td class="fw-semibold">
                <?php if ($account['status'] === 'active' && !empty($account['hotel_id']) && !empty($account['hotel_name'])): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Open the hotel portal as <?php echo htmlspecialchars(addslashes($account['hotel_name']), ENT_QUOTES); ?>?');">
                        <input type="hidden" name="action" value="impersonate_hotel">
                        <input type="hidden" name="id" value="<?php echo (int) $account['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['hotel_impersonation_csrf']); ?>">
                        <button class="btn btn-link p-0 fw-semibold text-decoration-none" type="submit" title="Log in as this hotel">
                            <?php echo htmlspecialchars($account['hotel_name']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                        </button>
                    </form>
                <?php else: ?>
                    <?php echo htmlspecialchars($account['hotel_name'] ?? 'Hotel removed'); ?>
                <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($account['username']); ?></td>
            <td><span class="badge <?php echo $account['status'] === 'active' ? 'text-bg-success' : 'text-bg-warning'; ?>"><?php echo htmlspecialchars(ucfirst($account['status'])); ?></span></td>
            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($account['created_at']))); ?></td>
            <td class="d-flex gap-1">
                <?php if ($account['status'] === 'active' && !empty($account['hotel_id']) && !empty($account['hotel_name'])): ?>
                    <form method="post" onsubmit="return confirm('Open the hotel portal as <?php echo htmlspecialchars(addslashes($account['hotel_name']), ENT_QUOTES); ?>?');">
                        <input type="hidden" name="action" value="impersonate_hotel">
                        <input type="hidden" name="id" value="<?php echo (int) $account['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['hotel_impersonation_csrf']); ?>">
                        <button class="btn btn-sm btn-success" type="submit" title="Log in as hotel"><i class="bi bi-box-arrow-in-right"></i></button>
                    </form>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAccount<?php echo (int) $account['id']; ?>"><i class="bi bi-pencil"></i></button>
                <form method="post"><input type="hidden" name="action" value="toggle_hotel_account"><input type="hidden" name="id" value="<?php echo (int) $account['id']; ?>"><button class="btn btn-sm btn-outline-<?php echo $account['status'] === 'active' ? 'warning' : 'success'; ?>"><?php echo $account['status'] === 'active' ? 'Suspend' : 'Activate'; ?></button></form>
                <form method="post" onsubmit="return confirm('Permanently remove hotel access for <?php echo htmlspecialchars(addslashes($account['username']), ENT_QUOTES); ?>? The hotel, bookings, and room data will remain.');">
                    <input type="hidden" name="action" value="remove_hotel_account">
                    <input type="hidden" name="id" value="<?php echo (int) $account['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['hotel_impersonation_csrf']); ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Remove hotel access"><i class="bi bi-trash me-1"></i>Remove</button>
                </form>
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
