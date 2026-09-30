<?php
require_once 'includes/auth.php';
require_once '../includes/delegate_menu.php';

function fetchDelegationById(PDO $pdo, int $delegationId): ?array
{
    $stmt = $pdo->prepare("SELECT id, username, country_name, status, suspended_at FROM users WHERE id = ? AND role = 'country_manager' LIMIT 1");
    $stmt->execute([$delegationId]);
    $delegation = $stmt->fetch(PDO::FETCH_ASSOC);

    return $delegation ?: null;
}

function delegationRoomingLockSettingKey(int $delegationId): string
{
    return 'delegation_rooming_locked_' . $delegationId;
}

if (empty($_SESSION['delegation_impersonation_csrf'])) {
    $_SESSION['delegation_impersonation_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $actor = getActorDetailsFromSession();
    $delegateMenuItems = getDelegateMenuItems();

    if ($_POST['action'] === 'impersonate_delegation') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        $delegationId = (int) ($_POST['id'] ?? 0);
        $delegation = fetchDelegationById($pdo, $delegationId);

        if (!hash_equals((string) $_SESSION['delegation_impersonation_csrf'], $csrfToken)) {
            $msg = "<div class='alert alert-danger alert-dismissible fade show'>The login request expired. Please try again.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif (!$delegation) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Delegation account not found.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif (($delegation['status'] ?? 'active') !== 'active') {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Suspended delegations cannot be opened.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            recordActivity(
                $pdo,
                'delegation_impersonation_started',
                'user',
                $delegationId,
                'Administrator opened the delegation portal as this country.',
                ['delegation_username' => $delegation['username'], 'country_name' => $delegation['country_name']],
                $actor['id'],
                $actor['role'],
                $actor['username']
            );

            $_SESSION['impersonator_admin'] = [
                'id' => (int) $actor['id'],
                'username' => (string) $actor['username'],
                'role' => 'admin',
            ];
            $_SESSION['impersonation_context'] = [
                'target_role' => 'country_manager',
                'target_username' => (string) $delegation['username'],
                'return_path' => 'admin/delegations.php',
            ];
            $_SESSION['id'] = $delegationId;
            $_SESSION['username'] = $delegation['username'];
            $_SESSION['role'] = 'country_manager';
            $_SESSION['loggedin'] = true;
            unset($_SESSION['show_login_announcement'], $_SESSION['delegation_impersonation_csrf']);
            $_SESSION['impersonation_return_csrf'] = bin2hex(random_bytes(32));
            session_regenerate_id(true);

            header('location: ../country/dashboard.php');
            exit;
        }
    } elseif ($_POST['action'] === 'set_all_rooming_locks') {
        $lockAll = (int) ($_POST['id'] ?? 0) === 1;
        $delegationIds = $pdo->query("SELECT id FROM users WHERE role = 'country_manager'")->fetchAll(PDO::FETCH_COLUMN);

        try {
            $pdo->beginTransaction();
            foreach ($delegationIds as $delegationId) {
                setAppSetting($pdo, delegationRoomingLockSettingKey((int) $delegationId), $lockAll ? '1' : '0');
            }
            $pdo->commit();

            recordActivity(
                $pdo,
                $lockAll ? 'all_delegation_rooming_locked' : 'all_delegation_rooming_unlocked',
                'app_setting',
                null,
                'Accommodation editing access updated for all delegations.',
                ['rooming_locked' => $lockAll, 'delegation_count' => count($delegationIds)],
                $actor['id'],
                $actor['role'],
                $actor['username'],
                formatTelegramActivityMessage('CAMS accommodation editing access', ['Action: ' . ($lockAll ? 'lock all delegations' : 'unlock all delegations'), 'By: ' . $actor['username'], 'Delegations: ' . count($delegationIds)])
            );

            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi " . ($lockAll ? 'bi-lock-fill' : 'bi-unlock') . " me-1'></i> Accommodation editing was " . ($lockAll ? 'locked' : 'unlocked') . " for " . count($delegationIds) . " delegation(s).<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = "<div class='alert alert-danger alert-dismissible fade show'>Unable to update all delegations right now.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'toggle_rooming_lock') {
        $id = (int) ($_POST['id'] ?? 0);
        $delegation = fetchDelegationById($pdo, $id);

        if (!$delegation) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Delegation account not found.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $settingKey = delegationRoomingLockSettingKey($id);
            $isLocked = isAppSettingEnabled($pdo, $settingKey, false);
            $newValue = $isLocked ? '0' : '1';
            setAppSetting($pdo, $settingKey, $newValue);

            recordActivity(
                $pdo,
                $newValue === '1' ? 'delegation_rooming_locked' : 'delegation_rooming_unlocked',
                'user',
                $id,
                'Delegation accommodation editing access updated.',
                ['username' => $delegation['username'], 'country_name' => $delegation['country_name'], 'rooming_locked' => $newValue === '1'],
                $actor['id'],
                $actor['role'],
                $actor['username'],
                formatTelegramActivityMessage('CAMS accommodation editing access', ['Action: ' . ($newValue === '1' ? 'lock booking and room editing' : 'unlock booking and room editing'), 'By: ' . $actor['username'], 'Country: ' . $delegation['country_name']])
            );

            $stateLabel = $newValue === '1' ? 'locked' : 'unlocked';
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-lock me-1'></i> Booking and room editing for " . htmlspecialchars($delegation['country_name']) . " is now {$stateLabel}.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'toggle_delegate_menu_item') {
        $menuItemKey = (string) ($_POST['menu_item_key'] ?? '');
        $menuItem = $delegateMenuItems[$menuItemKey] ?? null;

        if ($menuItem === null) {
            $msg = "<div class='alert alert-warning alert-dismissible fade show'>Invalid delegate menu item.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $settingKey = $menuItem['setting_key'];
            $isVisible = isAppSettingEnabled($pdo, $settingKey, true);
            $newValue = $isVisible ? '0' : '1';
            setAppSetting($pdo, $settingKey, $newValue);

            recordActivity(
                $pdo,
                $newValue === '1' ? 'delegate_menu_item_shown' : 'delegate_menu_item_hidden',
                'app_setting',
                null,
                'Delegate menu item visibility updated.',
                ['setting_key' => $settingKey, 'menu_item' => $menuItemKey, 'setting_value' => $newValue],
                $actor['id'],
                $actor['role'],
                $actor['username'],
                formatTelegramActivityMessage('CAMS delegate menu', ['Action: ' . ($newValue === '1' ? 'show menu item' : 'hide menu item'), 'By: ' . $actor['username'], 'Menu: ' . $menuItem['label']])
            );

            $msg = "<div class='alert alert-success alert-dismissible fade show'>" . htmlspecialchars($menuItem['label']) . " is now " . ($newValue === '1' ? 'visible' : 'hidden') . ".<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'add_delegation') {
        $username = trim($_POST['username'] ?? '');
        $country_name = trim($_POST['country_name'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($country_name) && !empty($password)) {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, status, country_name) VALUES (?, ?, 'country_manager', 'active', ?)");
                $stmt->execute([$username, $hash, $country_name]);
                $delegationId = (int) $pdo->lastInsertId();
                recordActivity(
                    $pdo,
                    'delegation_created',
                    'user',
                    $delegationId,
                    'Delegation account created.',
                    ['username' => $username, 'country_name' => $country_name],
                    $actor['id'],
                    $actor['role'],
                    $actor['username'],
                    formatTelegramActivityMessage('CAMS delegation change', ['Action: create delegation', 'By: ' . $actor['username'], 'Country: ' . $country_name, 'Username: ' . $username])
                );
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
                recordActivity(
                    $pdo,
                    'delegation_updated',
                    'user',
                    $id,
                    'Delegation account updated.',
                    ['old_username' => $delegation['username'], 'new_username' => $username, 'country_name' => $country_name, 'password_changed' => $password !== ''],
                    $actor['id'],
                    $actor['role'],
                    $actor['username'],
                    formatTelegramActivityMessage('CAMS delegation change', ['Action: update delegation', 'By: ' . $actor['username'], 'Country: ' . $country_name, 'Username: ' . $username])
                );
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

            recordActivity(
                $pdo,
                $newStatus === 'active' ? 'delegation_reactivated' : 'delegation_suspended',
                'user',
                $id,
                'Delegation status updated.',
                ['username' => $delegation['username'], 'country_name' => $delegation['country_name'], 'status' => $newStatus],
                $actor['id'],
                $actor['role'],
                $actor['username'],
                formatTelegramActivityMessage('CAMS delegation change', ['Action: ' . ($newStatus === 'active' ? 'reactivate delegation' : 'suspend delegation'), 'By: ' . $actor['username'], 'Country: ' . $delegation['country_name'], 'Username: ' . $delegation['username']])
            );

            $label = $newStatus === 'active' ? 'reactivated' : 'suspended';
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-user-lock'></i> Delegation {$label} successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } elseif ($_POST['action'] === 'delete_delegation') {
        $id = (int) ($_POST['id'] ?? 0);
        $delegation = fetchDelegationById($pdo, $id);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND role='country_manager'");
        if ($stmt->execute([$id])) {
            if ($delegation) {
                recordActivity(
                    $pdo,
                    'delegation_deleted',
                    'user',
                    $id,
                    'Delegation account deleted.',
                    ['username' => $delegation['username'], 'country_name' => $delegation['country_name']],
                    $actor['id'],
                    $actor['role'],
                    $actor['username'],
                    formatTelegramActivityMessage('CAMS delegation change', ['Action: delete delegation', 'By: ' . $actor['username'], 'Country: ' . $delegation['country_name'], 'Username: ' . $delegation['username']])
                );
            }
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash'></i> Delegation removed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

$delegateMenuItems = getDelegateMenuItems();
$delegateMenuStates = [];

foreach ($delegateMenuItems as $menuItemKey => $menuItem) {
    $delegateMenuStates[$menuItemKey] = isAppSettingEnabled($pdo, $menuItem['setting_key'], true);
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

foreach ($delegations as &$delegation) {
    $delegation['rooming_locked'] = isAppSettingEnabled($pdo, delegationRoomingLockSettingKey((int) $delegation['id']), false);
    if (($delegation['status'] ?? 'active') === 'active') {
        $activeDelegationCount++;
    }
}
unset($delegation);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Delegation Management</h1>
        <p class="text-muted mb-0">Manage country accounts, credentials, and access status.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button
            type="button"
            class="btn btn-outline-danger btn-sm"
            data-bs-toggle="modal"
            data-bs-target="#delegationActionModal"
            data-action="set_all_rooming_locks"
            data-id="1"
            data-title="Lock All Accommodation Editing"
            data-message="All countries will be unable to create bookings or change room assignments. Existing booking edits and removals remain admin-only."
            data-button-class="btn-danger"
            data-button-label="Lock All"
        >
            <i class="bi bi-lock-fill me-1"></i> Lock All
        </button>
        <button
            type="button"
            class="btn btn-outline-success btn-sm"
            data-bs-toggle="modal"
            data-bs-target="#delegationActionModal"
            data-action="set_all_rooming_locks"
            data-id="0"
            data-title="Unlock All Accommodation Editing"
            data-message="All countries will regain permission to create bookings and manage room assignments. Existing booking edits and removals remain admin-only."
            data-button-class="btn-success"
            data-button-label="Unlock All"
        >
            <i class="bi bi-unlock me-1"></i> Unlock All
        </button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDelegationModal">
            <i class="bi bi-plus-lg me-1"></i> Add Delegation
        </button>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div>
                    <div class="text-muted small text-uppercase fw-bold mb-2">Delegate Menu Items</div>
                    <p class="text-muted small mb-3">Show or hide specific links in the country delegation sidebar without removing the sidebar itself.</p>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Menu Item</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($delegateMenuItems as $menuItemKey => $menuItem): ?>
                                <?php $isVisible = $delegateMenuStates[$menuItemKey]; ?>
                                <tr>
                                    <td>
                                        <i class="bi <?php echo htmlspecialchars($menuItem['icon']); ?> me-2"></i>
                                        <?php echo htmlspecialchars($menuItem['label']); ?>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill <?php echo $isVisible ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                            <?php echo $isVisible ? 'Visible' : 'Hidden'; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="toggle_delegate_menu_item">
                                            <input type="hidden" name="menu_item_key" value="<?php echo htmlspecialchars($menuItemKey); ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $isVisible ? 'btn-outline-secondary' : 'btn-outline-success'; ?>">
                                                <i class="bi <?php echo $isVisible ? 'bi-eye-slash' : 'bi-eye'; ?> me-1"></i>
                                                <?php echo $isVisible ? 'Hide' : 'Show'; ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Total Delegations</div>
                <div class="display-6 fw-semibold"><?php echo count($delegations); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Active Delegations</div>
                <div class="display-6 fw-semibold text-success"><?php echo $activeDelegationCount; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
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
                            <th>Accommodation Editing</th>
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
                            <td>
                                <?php if (($d['status'] ?? 'active') === 'active'): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Open the delegate portal as <?php echo htmlspecialchars(addslashes($d['country_name']), ENT_QUOTES); ?>?');">
                                        <input type="hidden" name="action" value="impersonate_delegation">
                                        <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['delegation_impersonation_csrf']); ?>">
                                        <button type="submit" class="btn btn-link p-0 fw-semibold text-decoration-none" title="Log in as this delegate">
                                            <?php echo htmlspecialchars($d['country_name']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($d['country_name']); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($d['status'] ?? 'active') === 'active'): ?>
                                    <span class="badge rounded-pill text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill text-bg-warning">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge rounded-pill <?php echo !empty($d['rooming_locked']) ? 'text-bg-danger' : 'text-bg-success'; ?>">
                                    <i class="bi <?php echo !empty($d['rooming_locked']) ? 'bi-lock-fill' : 'bi-unlock'; ?> me-1"></i><?php echo !empty($d['rooming_locked']) ? 'Locked' : 'Open'; ?>
                                </span>
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
                                <?php if (($d['status'] ?? 'active') === 'active'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Open the delegate portal as <?php echo htmlspecialchars(addslashes($d['country_name']), ENT_QUOTES); ?>?');">
                                    <input type="hidden" name="action" value="impersonate_delegation">
                                    <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['delegation_impersonation_csrf']); ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Log in as delegate">
                                        <i class="bi bi-box-arrow-in-right"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editDelegationModal<?php echo $d['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm <?php echo !empty($d['rooming_locked']) ? 'btn-outline-success' : 'btn-outline-danger'; ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#delegationActionModal"
                                    data-action="toggle_rooming_lock"
                                    data-id="<?php echo $d['id']; ?>"
                                    data-title="<?php echo !empty($d['rooming_locked']) ? 'Unlock Accommodation Editing' : 'Lock Accommodation Editing'; ?>"
                                    data-message="<?php echo htmlspecialchars(!empty($d['rooming_locked']) ? 'This country will be able to create bookings and manage room assignments again. Existing booking edits and removals remain admin-only.' : 'This country can still view bookings and room assignments, but cannot create bookings or change assignments.', ENT_QUOTES); ?>"
                                    data-button-class="<?php echo !empty($d['rooming_locked']) ? 'btn-success' : 'btn-danger'; ?>"
                                    data-button-label="<?php echo !empty($d['rooming_locked']) ? 'Unlock Editing' : 'Lock Editing'; ?>"
                                    title="<?php echo !empty($d['rooming_locked']) ? 'Unlock accommodation editing' : 'Lock accommodation editing'; ?>"
                                >
                                    <i class="bi <?php echo !empty($d['rooming_locked']) ? 'bi-unlock' : 'bi-lock'; ?>"></i>
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
