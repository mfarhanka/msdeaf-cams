<?php

require_once __DIR__ . '/telegram.php';

function getActivityIpAddress(): ?string
{
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwardedFor) && $forwardedFor !== '') {
        $parts = explode(',', $forwardedFor);
        $ipAddress = trim($parts[0]);
        if ($ipAddress !== '') {
            return substr($ipAddress, 0, 45);
        }
    }

    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($remoteAddress) || $remoteAddress === '') {
        return null;
    }

    return substr($remoteAddress, 0, 45);
}

function getActivityUserAgent(): ?string
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if (!is_string($userAgent) || $userAgent === '') {
        return null;
    }

    return substr($userAgent, 0, 255);
}

function recordActivity(
    PDO $pdo,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $description = null,
    array $metadata = [],
    ?int $actorUserId = null,
    ?string $actorRole = null,
    ?string $actorUsername = null,
    ?string $telegramMessage = null
): void {
    try {
        $metadataJson = $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs
                (actor_user_id, actor_role, actor_username, action, entity_type, entity_id, description, metadata_json, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $actorUserId,
            $actorRole,
            $actorUsername,
            $action,
            $entityType,
            $entityId,
            $description,
            $metadataJson,
            getActivityIpAddress(),
            getActivityUserAgent(),
        ]);
    } catch (Throwable $exception) {
    }

    if ($telegramMessage !== null && $telegramMessage !== '') {
        sendTelegramNotification($telegramMessage);
    }
}

function getActorDetailsFromSession(): array
{
    return [
        'id' => isset($_SESSION['id']) ? (int) $_SESSION['id'] : null,
        'role' => isset($_SESSION['role']) ? (string) $_SESSION['role'] : null,
        'username' => isset($_SESSION['username']) ? (string) $_SESSION['username'] : null,
    ];
}

function formatTelegramActivityMessage(string $title, array $lines = []): string
{
    $messageLines = [$title];

    foreach ($lines as $line) {
        if (is_string($line) && $line !== '') {
            $messageLines[] = $line;
        }
    }

    return implode("\n", $messageLines);
}
?>