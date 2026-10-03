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

set_time_limit(0);
Config::load();

$lock = fopen(dirname(__DIR__) . '/storage/telegram-poll.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$client = new TelegramClient();
if (telegramWebhookOwnsUpdates($client)) {
    exit(0);
}

$offsetFile = dirname(__DIR__) . '/storage/telegram.offset';
$offset = is_file($offsetFile) ? (int) trim((string) file_get_contents($offsetFile)) : 0;

[$pdo, $store, $handler] = telegramPollStack($client);

while (true) {
    try {
        if (telegramWebhookOwnsUpdates($client)) {
            exit(0);
        }
        $updates = $client->request('getUpdates', [
            'offset' => $offset,
            'timeout' => 20,
            'allowed_updates' => ['message', 'callback_query'],
        ], 30);
    } catch (Throwable $e) {
        Logger::get()->error('Busca ativa do Telegram falhou', [
            'error' => SecretRedactor::redact($e->getMessage()),
        ]);
        sleep(2);
        continue;
    }

    if (!Db::alive($pdo)) {
        [$pdo, $store, $handler] = telegramPollStack($client);
    }

    foreach ($updates as $update) {
        if (!is_array($update) || !isset($update['update_id'])) {
            continue;
        }
        $updateId = (int) $update['update_id'];
        $payload = json_encode($update, JSON_UNESCAPED_UNICODE) ?: '{}';
        $fresh = $store->remember($updateId, $payload);
        $offset = $updateId + 1;
        file_put_contents($offsetFile, (string) $offset);
        if (!$fresh) {
            continue;
        }
        $attempt = 0;
        while (true) {
            try {
                $handler->handle($update);
                $store->markProcessed($updateId);
                break;
            } catch (Throwable $e) {
                $attempt++;
                $message = $e->getMessage();
                $gone = str_contains($message, '2006') || str_contains($message, 'gone away');
                if ($gone && $attempt === 1) {
                    [$pdo, $store, $handler] = telegramPollStack($client);
                    continue;
                }
                try {
                    $store->markError($updateId, $message);
                } catch (Throwable) {
                }
                Logger::get()->error('Busca ativa do Telegram falhou', [
                    'update_id' => $updateId,
                    'error' => SecretRedactor::redact($message),
                ]);
                break;
            }
        }
    }
}

/**
 * @return array{0: PDO, 1: UpdateStore, 2: UpdateHandler}
 */
function telegramPollStack(TelegramClient $client): array
{
    $pdo = Db::reconnect();
    try {
        $pdo->exec('SET SESSION wait_timeout = 28800');
    } catch (Throwable) {
    }
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

    return [$pdo, $store, $handler];
}

function telegramWebhookOwnsUpdates(TelegramClient $client): bool
{
    $info = $client->request('getWebhookInfo');
    $url = (string) ($info['url'] ?? '');
    $error = (string) ($info['last_error_message'] ?? '');
    $pending = (int) ($info['pending_update_count'] ?? 0);
    $errorAt = (int) ($info['last_error_date'] ?? 0);
    $recentError = $error !== '' && $errorAt >= time() - 900;
    if ($url !== '' && ($pending > 0 || $recentError)) {
        $client->request('deleteWebhook', ['drop_pending_updates' => false]);
        Logger::get()->warning('Webhook do Telegram travado. Busca ativa no cron.', [
            'erro' => $error,
            'pendentes' => $pending,
        ]);

        return false;
    }

    return $url !== '';
}
