<?php

require_once __DIR__ . '/db_config.php';

function getTelegramNotificationConfig(): array
{
    $botToken = getFirstEnvironmentValue(['TELEGRAM_BOT_TOKEN', 'TG_BOT_TOKEN']);
    $chatId = getFirstEnvironmentValue(['TELEGRAM_CHAT_ID', 'TG_CHAT_ID']);

    return [
        'enabled' => $botToken !== null && $chatId !== null,
        'bot_token' => $botToken,
        'chat_id' => $chatId,
    ];
}

function sendTelegramNotification(string $message): bool
{
    $config = getTelegramNotificationConfig();
    if (!$config['enabled']) {
        return false;
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode($config['bot_token']) . '/sendMessage';
    $payload = http_build_query([
        'chat_id' => $config['chat_id'],
        'text' => $message,
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);

        curl_exec($ch);
        $hasError = curl_errno($ch) !== 0;
        curl_close($ch);

        return !$hasError;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);

    return $result !== false;
}
?>