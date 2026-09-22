<?php

declare(strict_types=1);

use PerfilEmDia\Channel\TelegramChannel;
use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Logger;
use PerfilEmDia\Messages;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Telegram\Keyboards;
use PerfilEmDia\Telegram\TelegramClient;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

Config::load();

$pdo = Db::pdo();
$users = new UserRepository($pdo, Crypto::fromConfig());
$igClient = new InstagramClient();
$channel = new TelegramChannel(new TelegramClient());

foreach ($users->instagramAccountsExpiringWithinDays(10) as $account) {
    $userId = (int) $account['user_id'];
    try {
        $refreshed = $igClient->refreshLongLivedToken((string) $account['access_token']);
        $expiresIn = (int) ($refreshed['expires_in'] ?? 0);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
            ->modify('+' . max(1, $expiresIn) . ' seconds');
        $users->updateInstagramToken($userId, (string) $refreshed['access_token'], $expiresAt);
    } catch (Throwable $e) {
        Logger::get()->error('Falha ao renovar token Instagram', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);
        $users->markInstagramStatus($userId, 'expired');
        $user = $users->find($userId);
        if ($user === null) {
            continue;
        }
        $state = $users->createOauthState($userId);
        $url = rtrim(Config::get('APP_URL'), '/') . '/conectar.php?t=' . $state;
        $channel->sendText(
            (int) $user['telegram_chat_id'],
            Messages::tokenExpired(),
            Keyboards::openUrl('Reconectar Instagram', $url),
        );
    }
}
