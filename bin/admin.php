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

$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? '';

$pdo = Db::pdo();

if ($command === 'users') {
    $rows = $pdo->query(
        'SELECT id, telegram_user_id, display_name, status FROM users ORDER BY id ASC'
    )->fetchAll();
    foreach ($rows as $row) {
        echo implode("\t", [
            $row['id'],
            $row['telegram_user_id'],
            $row['display_name'] ?? '',
            $row['status'],
        ]) . PHP_EOL;
    }
    exit(0);
}

if ($command === 'posts') {
    $status = 'FAILED';
    foreach (array_slice($argv, 2) as $arg) {
        if (str_starts_with($arg, '--status=')) {
            $status = substr($arg, strlen('--status='));
        }
    }
    $stmt = $pdo->prepare(
        'SELECT id, user_id, status, error_code, error_message, created_at
         FROM posts WHERE status = ? ORDER BY id DESC'
    );
    $stmt->execute([$status]);
    foreach ($stmt->fetchAll() as $row) {
        echo implode("\t", [
            $row['id'],
            $row['user_id'],
            $row['status'],
            $row['error_code'] ?? '',
            $row['created_at'],
        ]) . PHP_EOL;
    }
    exit(0);
}

if ($command === 'usage') {
    $month = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m');
    foreach (array_slice($argv, 2) as $arg) {
        if (str_starts_with($arg, '--month=')) {
            $month = substr($arg, strlen('--month='));
        }
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        fwrite(STDERR, "Mes invalido. Use YYYY-MM.\n");
        exit(1);
    }
    $start = $month . '-01 00:00:00';
    $end = (new DateTimeImmutable($month . '-01', new DateTimeZone('America/Sao_Paulo')))
        ->modify('first day of next month')
        ->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(input_tokens), 0) AS input_tokens,
                COALESCE(SUM(output_tokens), 0) AS output_tokens
         FROM ai_usage
         WHERE created_at >= ? AND created_at < ?'
    );
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: ['input_tokens' => 0, 'output_tokens' => 0];
    echo "month={$month}\n";
    echo 'input_tokens=' . $row['input_tokens'] . "\n";
    echo 'output_tokens=' . $row['output_tokens'] . "\n";
    echo "estimated_cost=0\n";
    exit(0);
}

if ($command === 'create-admin') {
    $email = strtolower(trim((string) ($argv[2] ?? '')));
    $password = (string) ($argv[3] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        fwrite(STDERR, "Uso: php bin/admin.php create-admin email senha\n");
        exit(1);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO admins (email, password_hash, created_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
    );
    $stmt->execute([$email, $hash]);
    echo "admin={$email}\n";
    exit(0);
}

fwrite(STDERR, "Uso: php bin/admin.php users|posts [--status=FAILED]|usage [--month=YYYY-MM]|create-admin email senha\n");
exit(1);
