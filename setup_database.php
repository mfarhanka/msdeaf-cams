<?php
// setup_database.php
$host = 'localhost';
$username = 'root';
$password = '';
$sqlFile = __DIR__ . '/database.sql';

if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

try {
    $pdo = new PDO("mysql:host=$host", $username, $password);
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