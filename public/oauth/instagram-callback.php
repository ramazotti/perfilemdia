<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PerfilEmDia\Channel\TelegramChannel;
use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramOAuth;
use PerfilEmDia\Messages;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Telegram\TelegramClient;

Config::load();

$users = new UserRepository(Db::pdo(), Crypto::fromConfig());
$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
$state = isset($_GET['state']) ? (string) $_GET['state'] : '';
$error = isset($_GET['error']) ? (string) $_GET['error'] : '';

if ($error !== '' || $code === '') {
    $known = $state !== '' ? $users->peekOauthState($state) : null;
    if ($known !== null) {
        $user = $users->find($known);
        if ($user !== null && !empty($user['telegram_chat_id'])) {
            $newState = $users->createOauthState($known);
            $connectUrl = rtrim(Config::get('APP_URL'), '/') . '/conectar.php?t=' . rawurlencode($newState);
            $buttons = [[['text' => 'Conectar Instagram', 'url' => $connectUrl]]];
            try {
                $telegram = new TelegramChannel(new TelegramClient());
                $telegram->sendText(
                    (int) $user['telegram_chat_id'],
                    Messages::instagramDenied(),
                    $buttons,
                );
            } catch (Throwable) {
            }
        }
    }
    $motivo = $known === null && $state !== '' ? 'expirado' : 'negado';
    header('Location: ' . perfilemdia_public() . '/erro-conexao?motivo=' . $motivo);
    exit;
}

try {
    $oauth = new InstagramOAuth(new InstagramClient(), $users);
    $result = $oauth->handleCallback($code, $state);
    $username = $result['username'];
    $user = $users->find($result['user_id']);
    if ($user !== null && !empty($user['telegram_chat_id'])) {
        try {
            $telegram = new TelegramChannel(new TelegramClient());
            $telegram->sendText(
                (int) $user['telegram_chat_id'],
                Messages::instagramConnected($username),
            );
        } catch (Throwable) {
        }
    }

    header('Location: ' . perfilemdia_public() . '/conectado?conta=' . rawurlencode(ltrim((string) $username, '@')));
    exit;
} catch (Throwable) {
    header('Location: ' . perfilemdia_public() . '/erro-conexao?motivo=negado');
    exit;
}

function perfilemdia_public(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return rtrim(dirname($script, 2), '/');
}
