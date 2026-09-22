<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramOAuth;
use PerfilEmDia\Security\Crypto;

Config::load();

$state = isset($_GET['t']) ? (string) $_GET['t'] : '';
$users = new UserRepository(Db::pdo(), Crypto::fromConfig());
$userId = $state !== '' ? $users->peekOauthState($state) : null;

if ($userId === null) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link expirado</title>
</head>
<body>
    <main>
        <h1>Este link expirou</h1>
        <p>Peça um novo link de conexão no Telegram.</p>
        <p><a href="https://t.me/PerfilEmDiaBot">Abrir o bot no Telegram</a></p>
    </main>
</body>
</html>';
    exit;
}

$oauth = new InstagramOAuth(new InstagramClient(), $users);
header('Location: ' . $oauth->authorizationUrl($state), true, 302);
exit;
