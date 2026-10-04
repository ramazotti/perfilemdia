<?php

declare(strict_types=1);

use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Messages;
use PerfilEmDia\Telegram\SlowNotice;
use PerfilEmDia\Telegram\TelegramChannel;
use PerfilEmDia\Telegram\TelegramClient;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$postId = (int) ($argv[1] ?? 0);
$chatId = (int) ($argv[2] ?? 0);
if ($postId <= 0 || $chatId <= 0) {
    exit(0);
}

$marker = SlowNotice::marker($postId);
$dir = dirname($marker);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    exit(0);
}
$handle = fopen($marker, 'x');
if ($handle === false) {
    exit(0);
}
fclose($handle);

sleep(60);

try {
    Config::load();
    $post = (new PostRepository(Db::pdo()))->find($postId);
    if ($post !== null && SlowNotice::shouldPing((string) ($post['status'] ?? ''))) {
        (new TelegramChannel(new TelegramClient()))->sendText($chatId, Messages::ideaStillWorking());
    }
} catch (Throwable) {
} finally {
    if (is_file($marker)) {
        unlink($marker);
    }
}
