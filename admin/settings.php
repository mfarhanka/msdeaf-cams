<?php
require_once 'includes/auth.php';
require_once '../includes/database_backup.php';
require_once '../includes/telegram.php';
require_once '../includes/invoices.php';

$telegramConfig = getTelegramNotificationConfig();

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_sql_telegram'])) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['settings_csrf_token'], $submittedToken)) {
        $msg = '<div class="alert alert-danger">The request expired. Please try again.</div>';
    } elseif (!$telegramConfig['enabled']) {
        $msg = '<div class="alert alert-warning">Configure TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID before exporting a backup.</div>';
    } else {
        $dbConfig = getDatabaseConfig();
        $safeDatabaseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $dbConfig['dbname']);
        $backupName = $safeDatabaseName . '_' . date('Y-m-d_H-i-s') . '.sql';
        $temporaryPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $backupName;

        try {
            createDatabaseSqlBackup($pdo, $temporaryPath);
            $sent = sendTelegramDocument(
                $temporaryPath,
                'CAMS SQL backup • ' . $dbConfig['dbname'] . ' • ' . date('Y-m-d H:i:s T')
            );

            if (!$sent) {
                throw new RuntimeException('Telegram did not accept the backup. Check the bot token, chat ID, file size, and PHP cURL extension.');
            }

            recordActivity(
                $pdo,
                'export_sql_backup',
                'database',
                null,
                'Exported an SQL database backup to Telegram',
                ['filename' => $backupName, 'size_bytes' => filesize($temporaryPath)],
                (int) $_SESSION['id'],
                (string) $_SESSION['role'],
                (string) $_SESSION['username']
            );
            $msg = '<div class="alert alert-success">SQL backup sent to Telegram successfully.</div>';
        } catch (Throwable $exception) {
            $msg = '<div class="alert alert-danger">Backup failed: ' . htmlspecialchars($exception->getMessage()) . '</div>';
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice_settings'])) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['settings_csrf_token'], $submittedToken)) {
        $msg = '<div class="alert alert-danger">The request expired. Please try again.</div>';
    } else {
        try {
            $defaults = invoiceSettingDefaults();
            foreach ($defaults as $key => $default) {
                if ($key === 'invoice_logo_path') continue;
                $value = trim((string)($_POST[$key] ?? $default));
                if ($key === 'invoice_currency') $value = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $value), 0, 3));
                if ($key === 'invoice_participation_fee' && (!is_numeric($value) || (float)$value < 0)) throw new RuntimeException('Participation fee must be zero or greater.');
                if ($key === 'invoice_deposit_percent' && (!is_numeric($value) || (float)$value < 0 || (float)$value > 100)) throw new RuntimeException('Deposit percentage must be between 0 and 100.');
                setAppSetting($pdo, $key, $value);
            }
            if (isset($_FILES['invoice_logo']) && ($_FILES['invoice_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['invoice_logo']['error'] !== UPLOAD_ERR_OK || $_FILES['invoice_logo']['size'] > 2 * 1024 * 1024) throw new RuntimeException('Logo upload failed or exceeds 2 MB.');
                $info = getimagesize($_FILES['invoice_logo']['tmp_name']);
                if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) throw new RuntimeException('Logo must be a JPEG or PNG image.');
                $image = $info[2] === IMAGETYPE_PNG ? imagecreatefrompng($_FILES['invoice_logo']['tmp_name']) : imagecreatefromjpeg($_FILES['invoice_logo']['tmp_name']);
                if (!$image) throw new RuntimeException('Could not process the uploaded logo.');
                $dir = '../uploads/invoices'; if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('Could not create the invoice upload folder.');
                $path = $dir . '/association-logo.jpg'; imagejpeg($image, $path, 90); imagedestroy($image); setAppSetting($pdo, 'invoice_logo_path', 'uploads/invoices/association-logo.jpg');
            }
            $actor=getActorDetailsFromSession();recordActivity($pdo,'update_invoice_settings','settings',null,'Updated invoice configuration',[],$actor['id'],$actor['role'],$actor['username']);
            $msg = '<div class="alert alert-success">Invoice settings saved.</div>';
        } catch (Throwable $e) { $msg = '<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>'; }
    }
}

$invoiceSettings = getInvoiceSettings($pdo);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Settings</h1>
        <p class="text-muted mb-0">System integrations and database backups.</p>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header"><i class="bi bi-telegram me-2"></i>Telegram SQL Backup</div>
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3">
            <span class="fw-semibold">Telegram connection:</span>
            <?php if ($telegramConfig['enabled']): ?>
                <span class="badge text-bg-success">Configured</span>
            <?php else: ?>
                <span class="badge text-bg-warning">Not configured</span>
            <?php endif; ?>
        </div>
        <p class="text-muted">Creates a full SQL backup of the current database and sends it as a document to the configured Telegram chat. The temporary file is deleted after the attempt.</p>
        <form method="post" onsubmit="return confirm('Create and send a full database backup to Telegram?');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['settings_csrf_token']); ?>">
            <button type="submit" name="export_sql_telegram" class="btn btn-primary" <?php echo !$telegramConfig['enabled'] ? 'disabled' : ''; ?>>
                <i class="bi bi-database-down me-1"></i> Export SQL Backup to Telegram
            </button>
        </form>
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-header"><i class="bi bi-receipt me-2"></i>Proforma Invoice Settings</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['settings_csrf_token']); ?>">
            <div class="row g-3">
                <?php foreach ([
                    'invoice_org_name'=>'Association Name','invoice_org_name_en'=>'English Name','invoice_address'=>'Address','invoice_phone'=>'Phone','invoice_fax'=>'Fax','invoice_email'=>'Email','invoice_website'=>'Website',
                    'invoice_prefix'=>'Invoice Prefix','invoice_terms'=>'Payment Terms','invoice_currency'=>'Currency','invoice_participation_fee'=>'Participation Fee','invoice_deposit_percent'=>'Deposit Percentage',
                    'invoice_bank_account'=>'Bank Account','invoice_bank_name'=>'Bank Name','invoice_account_no'=>'Account Number','invoice_branch_name'=>'Branch Name','invoice_swift_code'=>'SWIFT Code','invoice_branch_code'=>'Branch Code','invoice_payment_email'=>'Payment Slip Email'
                ] as $key=>$label): ?>
                <div class="col-md-6"><label class="form-label"><?php echo htmlspecialchars($label); ?></label><input class="form-control" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($invoiceSettings[$key]); ?>" <?php echo in_array($key,['invoice_participation_fee','invoice_deposit_percent'],true)?'type="number" min="0" step="0.01"':'type="text"'; ?> required></div>
                <?php endforeach; ?>
                <div class="col-md-6"><label class="form-label">Association Logo</label><input class="form-control" type="file" name="invoice_logo" accept="image/jpeg,image/png"><div class="form-text">JPEG or PNG, maximum 2 MB. The image is converted to a PDF-compatible JPEG.</div></div>
            </div>
            <button type="submit" name="save_invoice_settings" class="btn btn-success mt-3"><i class="bi bi-save me-1"></i>Save Invoice Settings</button>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
