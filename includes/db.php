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

try {
    $dbConfig = getDatabaseConfig();
    $host = $dbConfig['host'];
    $port = $dbConfig['port'] ?? null;
    $dbname = $dbConfig['dbname'];
    $username = $dbConfig['username'];
    $password = $dbConfig['password'];

    $pdo = new PDO(buildDatabaseDsn($dbConfig), $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (shouldAutoManageDatabaseSchema()) {
        ensureUserStatusColumn($pdo);
        ensureActivityLogTable($pdo);
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