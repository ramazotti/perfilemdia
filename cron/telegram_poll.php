<?php

declare(strict_types=1);

use PerfilEmDia\Ai\CaptionGenerator;
use PerfilEmDia\Billing\BillingFactory;
use PerfilEmDia\Channel\TelegramChannel;
use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\OnboardingService;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\TicketService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Image\ImageNormalizer;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramPublisher;
use PerfilEmDia\Logger;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Security\SecretRedactor;
use PerfilEmDia\Telegram\TelegramClient;
use PerfilEmDia\Telegram\UpdateHandler;
use PerfilEmDia\Telegram\UpdateStore;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

Config::load();

$lock = fopen(dirname(__DIR__) . '/storage/telegram-poll.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$client = new TelegramClient();
$info = $client->request('getWebhookInfo');
$url = (string) ($info['url'] ?? '');
$error = (string) ($info['last_error_message'] ?? '');
if ($url !== '') {
    if (!str_contains($error, '409')) {
        exit(0);
    }
    $client->request('deleteWebhook', ['drop_pending_updates' => false]);
    Logger::get()->warning('Webhook do Telegram suspenso por 409. Busca ativa no cron.');
}

$offsetFile = dirname(__DIR__) . '/storage/telegram.offset';
$offset = is_file($offsetFile) ? (int) trim((string) file_get_contents($offsetFile)) : 0;
$updates = $client->request('getUpdates', [
    'offset' => $offset,
    'timeout' => 0,
    'allowed_updates' => ['message', 'callback_query'],
]);
if ($updates === []) {
    exit(0);
}

$pdo = Db::pdo();
$store = new UpdateStore($pdo);
$users = new UserRepository($pdo, Crypto::fromConfig());
$posts = new PostRepository($pdo);
$channel = new TelegramChannel($client);
$onboarding = new OnboardingService($users, $channel);
$postService = new PostService(
    $users,
    $posts,
    $channel,
    new ImageNormalizer(),
    new CaptionGenerator(),
    new InstagramPublisher(new InstagramClient()),
    new \PerfilEmDia\Billing\PlanAccess($pdo),
);
$handler = new UpdateHandler(
    $users,
    $posts,
    $channel,
    $onboarding,
    $postService,
    BillingFactory::service($pdo),
    new TicketService($pdo, $users),
);

foreach ($updates as $update) {
    if (!is_array($update) || !isset($update['update_id'])) {
        continue;
    }
    $updateId = (int) $update['update_id'];
    $payload = json_encode($update, JSON_UNESCAPED_UNICODE) ?: '{}';
    $fresh = $store->remember($updateId, $payload);
    file_put_contents($offsetFile, (string) ($updateId + 1));
    if (!$fresh) {
        continue;
    }
    try {
        $handler->handle($update);
        $store->markProcessed($updateId);
    } catch (Throwable $e) {
        try {
            $store->markError($updateId, $e->getMessage());
        } catch (Throwable) {
        }
        Logger::get()->error('Busca ativa do Telegram falhou', [
            'update_id' => $updateId,
            'error' => SecretRedactor::redact($e->getMessage()),
        ]);
    }
}
