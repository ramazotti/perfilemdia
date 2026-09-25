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
use PerfilEmDia\Telegram\TelegramClient;
use PerfilEmDia\Telegram\UpdateHandler;
use PerfilEmDia\Telegram\UpdateStore;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

Config::load();

$lock = fopen(dirname(__DIR__) . '/storage/worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$pdo = Db::pdo();
BillingFactory::service($pdo)->renewDue();
$store = new UpdateStore($pdo);
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
$handler = new UpdateHandler($users, $posts, $channel, $onboarding, $postService, BillingFactory::service($pdo), new TicketService($pdo, $users));

foreach ($store->stuck() as $row) {
    $updateId = (int) $row['update_id'];
    $payload = $row['payload'];
    if (is_string($payload)) {
        $update = json_decode($payload, true);
    } else {
        $update = $payload;
    }
    if (!is_array($update)) {
        $store->markError($updateId, 'payload invalido');
        continue;
    }
    try {
        $handler->handle($update);
        $store->markProcessed($updateId);
    } catch (Throwable $e) {
        $store->markError($updateId, $e->getMessage());
        Logger::get()->error('Worker update falhou', [
            'update_id' => $updateId,
            'error' => $e->getMessage(),
        ]);
    }
}

foreach ($posts->collectingOlderThanSeconds(30) as $post) {
    try {
        $postService->finalizeCollecting((int) $post['id']);
    } catch (Throwable $e) {
        Logger::get()->error('Worker collecting falhou', [
            'post_id' => $post['id'],
            'error' => $e->getMessage(),
        ]);
    }
}

foreach ($posts->imageEditingOlderThanMinutes(2) as $post) {
    try {
        $postService->abandonImageEdit((int) $post['id']);
    } catch (Throwable $e) {
        Logger::get()->error('Worker image edit falhou', [
            'post_id' => $post['id'],
            'error' => $e->getMessage(),
        ]);
    }
}

try {
    $postService->publishDue();
} catch (Throwable $e) {
    Logger::get()->error('Worker agendamento falhou', ['error' => $e->getMessage()]);
}

try {
    $postService->finishAiVideos();
} catch (Throwable $e) {
    Logger::get()->error('Worker video IA falhou', ['error' => $e->getMessage()]);
}

try {
    $postService->sendDailyIdeas();
} catch (Throwable $e) {
    Logger::get()->error('Worker ideia do dia falhou', ['error' => $e->getMessage()]);
}

foreach ($posts->publishingOlderThanMinutes(2) as $post) {
    try {
        $postService->resumePublishing((int) $post['id']);
    } catch (Throwable $e) {
        Logger::get()->error('Worker publishing falhou', [
            'post_id' => $post['id'],
            'error' => $e->getMessage(),
        ]);
    }
}
