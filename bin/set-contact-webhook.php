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

$token = Config::get('TELEGRAM_CONTACT_BOT_TOKEN', '');
$secret = Config::get('TELEGRAM_CONTACT_WEBHOOK_SECRET', '');
if ($token === '' || $secret === '') {
    fwrite(STDERR, "Preencha TELEGRAM_CONTACT_BOT_TOKEN e TELEGRAM_CONTACT_WEBHOOK_SECRET no .env.\n");
    exit(1);
}

$public = Config::get('TELEGRAM_CONTACT_PUBLIC_URL', '');
if ($public === '') {
    $public = Config::get('APP_URL');
}
$public = rtrim($public, '/');
if (str_contains($public, 'localhost') || str_contains($public, '127.0.0.1')) {
    fwrite(STDERR, "Defina TELEGRAM_CONTACT_PUBLIC_URL com o endereço público, sem localhost.\n");
    exit(1);
}

$url = $public . '/webhook/contato.php';
$client = new TelegramClient(null, $token);
$webhook = $client->setWebhook($url, $secret);
$commands = $client->request('setMyCommands', [
    'commands' => [
        ['command' => 'start', 'description' => 'Falar com o Perfil em Dia'],
    ],
]);

echo json_encode([
    'webhook' => $webhook,
    'commands' => $commands,
    'url' => $url,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
