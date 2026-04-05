<?php
// includes/db.php
$host = 'localhost';
$dbname = 'msdeaf_cams';
$username = 'root';
$password = '';

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
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureUserStatusColumn($pdo);
} catch(PDOException $e) {
    if (!empty($suppressDbErrors)) {
        $pdo = null;
        $db_error = "Database connection failed. Please contact the administrator.";
    } else {
        die("Database connection failed: " . $e->getMessage());
    }
}
?>