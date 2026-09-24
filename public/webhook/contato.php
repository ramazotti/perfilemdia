<?php

declare(strict_types=1);

use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Logger;
use PerfilEmDia\Telegram\ContactInbox;
use PerfilEmDia\Telegram\ContactRelay;
use PerfilEmDia\Telegram\DbContactDirectory;
use PerfilEmDia\Telegram\TelegramClient;
use PerfilEmDia\Telegram\TelegramContactTransport;
use PerfilEmDia\Telegram\UpdateStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Config::load();

$secret = Config::get('TELEGRAM_CONTACT_WEBHOOK_SECRET', '');
$header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secret === '' || !is_string($header) || !hash_equals($secret, $header)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$token = Config::get('TELEGRAM_CONTACT_BOT_TOKEN', '');
if ($token === '') {
    http_response_code(500);
    echo 'error';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);
if (!is_array($update) || !isset($update['update_id'])) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

$updateId = (int) $update['update_id'];
$payload = $raw !== '' ? $raw : (json_encode($update, JSON_UNESCAPED_UNICODE) ?: '{}');
$pdo = Db::pdo();
$store = new UpdateStore($pdo, 'contact_updates');

if (!$store->remember($updateId, $payload)) {
    http_response_code(200);
    echo 'ok';
    exit;
}

try {
    $inbox = new ContactInbox(
        new ContactRelay(),
        new TelegramContactTransport(new TelegramClient(null, $token)),
        new DbContactDirectory($pdo, Config::get('TELEGRAM_CONTACT_LINK_CODE', '')),
        Config::get('TELEGRAM_BOT_USERNAME', 'PerfilEmDiaBot'),
    );
    $inbox->handle($update);
    $store->markProcessed($updateId);
    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    $store->forget($updateId);
    $error = $e->getMessage();
    if (str_contains($error, 'api.telegram.org') || str_contains($error, $token)) {
        $error = 'falha na API do Telegram';
    }
    Logger::get()->error('Contato falhou', [
        'update_id' => $updateId,
        'error' => $error,
    ]);
    http_response_code(500);
    echo 'error';
}
