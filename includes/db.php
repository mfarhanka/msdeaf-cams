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

    $stmt = $pdo->query(
        "SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'status'"
    );

    if ((int) $stmt->fetchColumn() === 0) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'suspended') NOT NULL DEFAULT 'active' AFTER role");
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
    die("Database connection failed: " . $e->getMessage());
}
?>