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

$client = new TelegramClient();
$result = $client->setMyCommands();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
