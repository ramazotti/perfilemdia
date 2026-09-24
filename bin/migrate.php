<?php

declare(strict_types=1);

use PerfilEmDia\Config;
use PerfilEmDia\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load();

$pdo = Db::pdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$dir = Config::root() . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "Nenhuma migração em migrations/.\n");
    exit(1);
}

$applied = 0;
foreach ($files as $file) {
    $filename = basename($file);
    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE filename = ?');
    $check->execute([$filename]);
    if ($check->fetchColumn()) {
        echo "ok  {$filename} (já aplicada)\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Falha ao ler {$filename}.\n");
        exit(1);
    }

    foreach (statements($sql) as $statement) {
        $pdo->exec($statement);
    }

    $insert = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
    $insert->execute([$filename]);
    echo "aplicada  {$filename}\n";
    $applied++;
}

echo $applied === 0 ? "Nada novo para aplicar.\n" : "Migrações novas: {$applied}.\n";

/**
 * @return list<string>
 */
function statements(string $sql): array
{
    $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = preg_split('/;\s*(?:\r?\n|$)/', $withoutComments) ?: [];
    $statements = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $statements[] = $part;
        }
    }

    return $statements;
}
