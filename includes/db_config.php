<?php

function loadEnvironmentFile(?string $filePath = null): void
{
    static $loadedFiles = [];

    $resolvedPath = $filePath ?: dirname(__DIR__) . '/.env';
    if (isset($loadedFiles[$resolvedPath]) || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
        return;
    }

    $lines = file($resolvedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separatorPosition = strpos($line, '=');
        if ($separatorPosition === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separatorPosition));
        $value = trim(substr($line, $separatorPosition + 1));

        if ($key === '' || preg_match('/^[A-Z0-9_]+$/i', $key) !== 1) {
            continue;
        }

        $firstCharacter = $value !== '' ? $value[0] : '';
        $lastCharacter = $value !== '' ? substr($value, -1) : '';
        if (($firstCharacter === '"' && $lastCharacter === '"') || ($firstCharacter === "'" && $lastCharacter === "'")) {
            $value = substr($value, 1, -1);
        } else {
            $commentPosition = strpos($value, ' #');
            if ($commentPosition !== false) {
                $value = rtrim(substr($value, 0, $commentPosition));
            }
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $loadedFiles[$resolvedPath] = true;
}

loadEnvironmentFile();

function isTruthyEnvironmentValue(?string $value): bool
{
    if ($value === null) {
        return false;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function shouldAutoManageDatabaseSchema(): bool
{
    $configuredValue = getFirstEnvironmentValue(['APP_AUTO_MIGRATE', 'DB_AUTO_SETUP']);
    if ($configuredValue !== null) {
        return isTruthyEnvironmentValue($configuredValue);
    }

    return detectDatabaseEnvironment() === 'local';
}

function getFirstEnvironmentValue(array $keys): ?string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
    }

    return null;
}

function detectDatabaseEnvironment(): string
{
    $forcedEnvironment = getFirstEnvironmentValue(['APP_ENV', 'DB_ENV']);
    if (is_string($forcedEnvironment) && $forcedEnvironment !== '') {
        $normalizedEnvironment = strtolower(trim($forcedEnvironment));
        if ($normalizedEnvironment === 'local') {
            return 'local';
        }

        if ($normalizedEnvironment === 'server' || $normalizedEnvironment === 'production') {
            return 'server';
        }
    }

    $hostCandidates = [
        $_SERVER['HTTP_HOST'] ?? null,
        $_SERVER['SERVER_NAME'] ?? null,
        $_SERVER['SERVER_ADDR'] ?? null,
    ];

    foreach ($hostCandidates as $hostCandidate) {
        if (!is_string($hostCandidate) || $hostCandidate === '') {
            continue;
        }

        $normalizedHost = strtolower(trim(explode(':', $hostCandidate)[0]));
        $isLocalHost = $normalizedHost === 'localhost'
            || $normalizedHost === '127.0.0.1'
            || $normalizedHost === '::1';
        $isDotLocal = substr($normalizedHost, -6) === '.local';

        if ($isLocalHost || $isDotLocal) {
            return 'local';
        }
    }

    $normalizedPath = str_replace('\\', '/', __DIR__);
    if (strpos(strtolower($normalizedPath), '/xampp/htdocs/') !== false) {
        return 'local';
    }

    return 'server';
}

function getDatabaseConfig(): array
{
    $environment = detectDatabaseEnvironment();

    if ($environment === 'local') {
        return [
            'host' => getFirstEnvironmentValue(['DB_LOCAL_HOST', 'DB_HOST']) ?: 'localhost',
            'port' => getFirstEnvironmentValue(['DB_LOCAL_PORT', 'DB_PORT']),
            'dbname' => getFirstEnvironmentValue(['DB_LOCAL_NAME', 'DB_NAME']) ?: 'msdeaf_cams',
            'username' => getFirstEnvironmentValue(['DB_LOCAL_USER', 'DB_USER']) ?: 'root',
            'password' => getFirstEnvironmentValue(['DB_LOCAL_PASS', 'DB_PASSWORD']) ?: '',
        ];
    }

    $host = getFirstEnvironmentValue(['DB_SERVER_HOST', 'DB_HOST']);
    $port = getFirstEnvironmentValue(['DB_SERVER_PORT', 'DB_PORT']);
    $username = getFirstEnvironmentValue(['DB_SERVER_USER', 'DB_USER']);
    $password = getFirstEnvironmentValue(['DB_SERVER_PASS', 'DB_PASSWORD']);
    $dbname = getFirstEnvironmentValue(['DB_SERVER_NAME', 'DB_NAME']);

    $missingKeys = [];
    if ($host === null) {
        $missingKeys[] = 'DB_SERVER_HOST or DB_HOST';
    }
    if ($username === null) {
        $missingKeys[] = 'DB_SERVER_USER or DB_USER';
    }
    if ($password === null) {
        $missingKeys[] = 'DB_SERVER_PASS or DB_PASSWORD';
    }
    if ($dbname === null) {
        $missingKeys[] = 'DB_SERVER_NAME or DB_NAME';
    }

    if ($missingKeys !== []) {
        throw new RuntimeException(
            'Missing production database configuration: ' . implode(', ', $missingKeys)
        );
    }

    return [
        'host' => $host,
        'port' => $port,
        'dbname' => $dbname,
        'username' => $username,
        'password' => $password,
    ];
}

function buildDatabaseDsn(array $dbConfig): string
{
    $dsn = 'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['dbname'] . ';charset=utf8mb4';
    if (!empty($dbConfig['port'])) {
        $dsn .= ';port=' . $dbConfig['port'];
    }

    return $dsn;
}

function getDatabaseDebugSummary(): array
{
    $dbConfig = getDatabaseConfig();

    return [
        'app_env' => getFirstEnvironmentValue(['APP_ENV', 'DB_ENV']) ?: 'auto',
        'detected_environment' => detectDatabaseEnvironment(),
        'env_file' => [
            'path' => dirname(__DIR__) . '/.env',
            'exists' => is_file(dirname(__DIR__) . '/.env'),
            'readable' => is_readable(dirname(__DIR__) . '/.env'),
        ],
        'database' => [
            'host' => $dbConfig['host'],
            'port' => $dbConfig['port'] ?? null,
            'dbname' => $dbConfig['dbname'],
            'username' => $dbConfig['username'],
            'password_set' => ($dbConfig['password'] ?? '') !== '',
        ],
        'dsn' => buildDatabaseDsn($dbConfig),
    ];
}

function getRequiredDatabaseTables(): array
{
    return [
        'users',
        'championships',
        'hotels',
        'championship_hotels',
        'room_types',
        'athletes',
        'bookings',
        'activity_logs',
        'room_assignments',
    ];
}
?>