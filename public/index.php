<?php

declare(strict_types=1);

use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Site\AdminSite;
use PerfilEmDia\Site\CustomerPortal;
use PerfilEmDia\Site\Layout;
use PerfilEmDia\Site\PublicSite;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load();

header('Content-Type: text/html; charset=utf-8');

$path = Layout::path();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$cacheable = in_array($method, ['GET', 'HEAD'], true) && in_array($path, [
    '/',
    '/planos',
    '/ajuda',
    '/manual',
    '/conta',
    '/privacidade',
    '/termos',
    '/sitemap.xml',
    '/conectado',
    '/erro-conexao',
], true);
if (!$cacheable) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => Layout::base() === '' ? '/' : Layout::base() . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);
    session_start();
}
$pdo = Db::pdo();

if ((new AdminSite($pdo))->dispatch($path)) {
    return;
}
if ((new CustomerPortal($pdo))->dispatch($path)) {
    return;
}
if ((new PublicSite($pdo))->dispatch($path)) {
    return;
}

Layout::page(
    'Página não encontrada',
    '<section class="center-page"><div><h1>Essa página não existe</h1><p>O endereço pode ter mudado.</p><a class="btn btn-primary" href="' . Layout::e(Layout::url('/')) . '">Ir para o início</a></div></section>',
    '',
    404,
);
