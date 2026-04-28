<?php

require_once __DIR__ . '/includes/db_config.php';

$debugEnabled = detectDatabaseEnvironment() === 'local'
    || isTruthyEnvironmentValue(getFirstEnvironmentValue(['APP_DEBUG_DB']));

if (!$debugEnabled) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'DB diagnostics are disabled.',
    ], JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: application/json');

try {
    $summary = getDatabaseDebugSummary();
    $dbConfig = getDatabaseConfig();
    $pdo = new PDO(buildDatabaseDsn($dbConfig), $dbConfig['username'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $requiredTables = getRequiredDatabaseTables();
    $tableSummary = [];
    foreach ($requiredTables as $tableName) {
        $tableSummary[$tableName] = in_array($tableName, $existingTables, true);
    }

    echo json_encode([
        'ok' => true,
        'summary' => $summary,
        'tables' => $tableSummary,
        'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'summary' => isset($summary) ? $summary : null,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}