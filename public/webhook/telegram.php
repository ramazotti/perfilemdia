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
use PerfilEmDia\Http;
use PerfilEmDia\Image\ImageNormalizer;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramPublisher;
use PerfilEmDia\Logger;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Telegram\TelegramClient;
use PerfilEmDia\Telegram\UpdateHandler;
use PerfilEmDia\Telegram\UpdateStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Config::load();

$secret = Config::get('TELEGRAM_WEBHOOK_SECRET', '');
$header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secret === '' || !is_string($header) || !hash_equals($secret, $header)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);
if (!is_array($update) || !isset($update['update_id'])) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

$pdo = Db::pdo();
$store = new UpdateStore($pdo);
$updateId = (int) $update['update_id'];
$payload = $raw !== '' ? $raw : (json_encode($update, JSON_UNESCAPED_UNICODE) ?: '{}');

if (!$store->remember($updateId, $payload)) {
    http_response_code(200);
    echo 'ok';
    exit;
}

http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
    // flush buffers before finishRequest when possible
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

if (!Http::finishRequest()) {
    exit;
}

try {
    $handler = buildHandler($pdo);
    $handler->handle($update);
    $store->markProcessed($updateId);
} catch (Throwable $e) {
    $store->markError($updateId, $e->getMessage());
    Logger::get()->error('Webhook update falhou', [
        'update_id' => $updateId,
        'error' => $e->getMessage(),
    ]);
}

/**
 * @param PDO $pdo
 */
function buildHandler(PDO $pdo): UpdateHandler
{
    $users = new UserRepository($pdo, Crypto::fromConfig());
    $posts = new PostRepository($pdo);
    $client = new TelegramClient();
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

    return new UpdateHandler($users, $posts, $channel, $onboarding, $postService, BillingFactory::service($pdo), new TicketService($pdo, $users));
}
