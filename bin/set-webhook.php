<?php

declare(strict_types=1);

use PerfilEmDia\Config;
use PerfilEmDia\Telegram\TelegramClient;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

Config::load();

$url = rtrim(Config::get('APP_URL'), '/') . '/webhook/telegram.php';
$secret = Config::get('TELEGRAM_WEBHOOK_SECRET');
$client = new TelegramClient();
$result = $client->setWebhook($url, $secret);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
