<?php
require_once 'includes/auth.php';

$limit = 100;
$activityStmt = $pdo->prepare(
    "SELECT id, actor_username, actor_role, action, entity_type, entity_id, description, metadata_json, ip_address, created_at
    FROM activity_logs
    ORDER BY created_at DESC, id DESC
    LIMIT ?"
);
$activityStmt->bindValue(1, $limit, PDO::PARAM_INT);
$activityStmt->execute();
$activityLogs = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Activity Logs</h1>
        <p class="text-muted mb-0">Recent authentication, booking, and account management events.</p>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="alert alert-info">
            Telegram notifications are only sent when <strong>TELEGRAM_BOT_TOKEN</strong> and <strong>TELEGRAM_CHAT_ID</strong> are configured in the environment.
        </div>

        <?php if ($activityLogs): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Target</th>
                            <th>Description</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activityLogs as $log): ?>
                            <tr>
                                <td class="small text-muted"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($log['created_at']))); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($log['actor_username'] ?: 'System'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($log['actor_role'] ?: '-'); ?></div>
                                </td>
                                <td><span class="badge rounded-pill text-bg-primary"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                <td class="small text-muted"><?php echo htmlspecialchars(($log['entity_type'] ?: '-') . ($log['entity_id'] ? ' #' . $log['entity_id'] : '')); ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars($log['description'] ?: '-'); ?></div>
                                    <?php if (!empty($log['metadata_json'])): ?>
                                        <div class="small text-muted text-break"><?php echo htmlspecialchars($log['metadata_json']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-muted">No activity has been recorded yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>