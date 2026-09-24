<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramOAuth;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Site\Layout;

Config::load();

$state = isset($_GET['t']) ? (string) $_GET['t'] : '';
$users = new UserRepository(Db::pdo(), Crypto::fromConfig());
$userId = $state !== '' ? $users->peekOauthState($state) : null;

if ($userId === null) {
    $html = "<section class=\"center-page\"><div><h1>Este link expirou</h1>"
        . "<p>Esse link não vale mais. No Telegram, envie /conectar para receber outro.</p>"
        . "<a class=\"btn btn-primary\" href=\"https://t.me/PerfilEmDiaBot\">Abrir o bot no Telegram</a></div></section>";
    Layout::page("Link expirado", $html);
    exit;
}

$oauth = new InstagramOAuth(new InstagramClient(), $users);
header('Location: ' . $oauth->authorizationUrl($state), true, 302);
exit;