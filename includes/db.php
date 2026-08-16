<?php
// includes/db.php
require_once __DIR__ . '/db_config.php';

function ensureActivityLogTable(PDO $pdo): void
{
    static $activityLogChecked = false;

    if ($activityLogChecked) {
        return;
    }

    $activityLogChecked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT NULL,
            actor_role VARCHAR(50) NULL,
            actor_username VARCHAR(100) NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id INT NULL,
            description TEXT NULL,
            metadata_json LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_logs_created_at (created_at),
            INDEX idx_activity_logs_actor_user_id (actor_user_id),
            INDEX idx_activity_logs_entity (entity_type, entity_id),
            CONSTRAINT fk_activity_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureAppSettingsTable(PDO $pdo): void
{
    static $appSettingsChecked = false;

    if ($appSettingsChecked) {
        return;
    }

    $appSettingsChecked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value)
        VALUES ('delegate_menu_visible', '1')
        ON DUPLICATE KEY UPDATE setting_value = setting_value"
    );
    $stmt->execute();
}

function ensureAnnouncementsTable(PDO $pdo): void
{
    static $announcementsChecked = false;

    if ($announcementsChecked) {
        return;
    }

    $announcementsChecked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            body TEXT NOT NULL,
            image_path VARCHAR(255) NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            display_on_login TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_announcements_display (display_on_login, is_enabled),
            CONSTRAINT fk_announcements_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function getAppSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    ensureAppSettingsTable($pdo);

    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function setAppSetting(PDO $pdo, string $key, string $value): void
{
    ensureAppSettingsTable($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value]);
}

function isAppSettingEnabled(PDO $pdo, string $key, bool $default = false): bool
{
    $defaultValue = $default ? '1' : '0';
    $value = getAppSetting($pdo, $key, $defaultValue);

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function ensureUserStatusColumn(PDO $pdo): void
{
    static $statusChecked = false;

    if ($statusChecked) {
        return;
    }

    $statusChecked = true;

    $columnStmt = $pdo->query(
        "SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'"
    );
    $columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('status', $columns, true)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'suspended') NOT NULL DEFAULT 'active' AFTER role");
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') {
                throw $e;
            }
        }
    }

    if (!in_array('suspended_at', $columns, true)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN suspended_at TIMESTAMP NULL DEFAULT NULL AFTER status");
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') {
                throw $e;
            }
        }
    }

    if (!in_array('updated_at', $columns, true)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') {
                throw $e;
            }
        }
    }
}

function ensureHotelStarRatingColumn(PDO $pdo): void
{
    static $starRatingChecked = false;

    if ($starRatingChecked) {
        return;
    }

    $starRatingChecked = true;

    $tableExistsStmt = $pdo->query(
        "SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'hotels'"
    );

    if ((int) $tableExistsStmt->fetchColumn() === 0) {
        return;
    }

    $columnStmt = $pdo->query(
        "SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'hotels'"
    );
    $columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('star_rating', $columns, true)) {
        try {
            $pdo->exec("ALTER TABLE hotels ADD COLUMN star_rating TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER address");
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') {
                throw $e;
            }
        }
    }
}

function ensureBookingScheduleColumns(PDO $pdo): void
{
    static $bookingScheduleChecked = false;

    if ($bookingScheduleChecked) {
        return;
    }

    $bookingScheduleChecked = true;

    $tableExistsStmt = $pdo->query(
        "SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings'"
    );

    if ((int) $tableExistsStmt->fetchColumn() === 0) {
        return;
    }

    $columnStmt = $pdo->query(
        "SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'bookings'"
    );
    $columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('booking_start_date', $columns, true)) {
        try {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN booking_start_date DATE NULL AFTER rooms_reserved");
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') {
                throw $e;
            }
        }
    }

    if (!in_array('booking_end_date', $columns, true)) {
        try {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN booking_end_date DATE NULL AFTER booking_start_date");
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') {
                throw $e;
            }
        }
    }

    // Existing rows created before this migration inherit championship dates.
    $pdo->exec(
        "UPDATE bookings b
        JOIN championships c ON c.id = b.championship_id
        SET
            b.booking_start_date = COALESCE(b.booking_start_date, c.start_date),
            b.booking_end_date = COALESCE(b.booking_end_date, c.end_date)
        WHERE b.booking_start_date IS NULL OR b.booking_end_date IS NULL"
    );
}

try {
    $dbConfig = getDatabaseConfig();
    $host = $dbConfig['host'];
    $port = $dbConfig['port'] ?? null;
    $dbname = $dbConfig['dbname'];
    $username = $dbConfig['username'];
    $password = $dbConfig['password'];

    $pdo = new PDO(buildDatabaseDsn($dbConfig), $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureHotelStarRatingColumn($pdo);
    ensureBookingScheduleColumns($pdo);
    if (shouldAutoManageDatabaseSchema()) {
        ensureUserStatusColumn($pdo);
        ensureActivityLogTable($pdo);
        ensureAppSettingsTable($pdo);
        ensureAnnouncementsTable($pdo);
        require_once __DIR__ . '/invoices.php';
        ensureInvoiceSchema($pdo);
    }
} catch(Exception $e) {
    error_log('Database connection failed: ' . json_encode([
        'environment' => function_exists('detectDatabaseEnvironment') ? detectDatabaseEnvironment() : null,
        'host' => $host ?? null,
        'port' => $port ?? null,
        'dbname' => $dbname ?? null,
        'username' => $username ?? null,
        'error' => $e->getMessage(),
    ]));

    if (!empty($suppressDbErrors)) {
        $pdo = null;
        $db_error = "Database connection failed. Please contact the administrator.";
    } else {
        die("Database connection failed: " . $e->getMessage());
    }
}
?>
