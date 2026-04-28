<?php
// setup_database.php
require_once __DIR__ . '/includes/db_config.php';
$sqlFile = __DIR__ . '/database.sql';

if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

try {
    $dbConfig = getDatabaseConfig();
    $host = $dbConfig['host'];
    $port = $dbConfig['port'] ?? null;
    $dbname = $dbConfig['dbname'];
    $username = $dbConfig['username'];
    $password = $dbConfig['password'];

    $serverDsn = 'mysql:host=' . $host . ';charset=utf8mb4';
    if (!empty($port)) {
        $serverDsn .= ';port=' . $port;
    }

    try {
        $serverPdo = new PDO($serverDsn, $username, $password);
        $serverPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '``', $dbname) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'access denied') === false && stripos($e->getMessage(), 'command denied') === false) {
            throw $e;
        }
    }

    $pdo = new PDO(buildDatabaseDsn($dbConfig), $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException("Failed to read SQL file: $sqlFile");
    }

    $pdo->exec($sql);
    echo "Database setup completed successfully.\n";
} catch (PDOException $e) {
    echo "Database setup failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Setup error: " . $e->getMessage() . "\n";
    exit(1);
}
?>