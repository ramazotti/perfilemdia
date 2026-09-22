<?php

declare(strict_types=1);

use PerfilEmDia\Config;
use PerfilEmDia\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

Config::load();

$hours = (int) Config::get('MEDIA_PUBLIC_TTL_HOURS', '48');
$pdo = Db::pdo();
$stmt = $pdo->prepare(
    "SELECT pm.id AS media_id, pm.public_name
     FROM post_media pm
     INNER JOIN posts p ON p.id = pm.post_id
     WHERE pm.public_name IS NOT NULL
       AND pm.public_name <> ''
       AND p.status IN ('PUBLISHED', 'FAILED', 'CANCELLED')
       AND p.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
);
$stmt->execute([$hours]);
$rows = $stmt->fetchAll();

$clear = $pdo->prepare('UPDATE post_media SET public_name = NULL WHERE id = ?');
$root = Config::root();

foreach ($rows as $row) {
    $name = (string) $row['public_name'];
    $path = $root . '/public/m/' . $name . '.jpg';
    if (is_file($path)) {
        @unlink($path);
    }
    $clear->execute([(int) $row['media_id']]);
}

echo 'cleanup_media: ' . count($rows) . " arquivo(s)\n";
