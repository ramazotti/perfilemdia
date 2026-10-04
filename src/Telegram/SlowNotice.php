<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

use PerfilEmDia\Config;

final class SlowNotice
{
    public static function shouldPing(string $status): bool
    {
        return $status === 'GENERATING';
    }

    public static function marker(int $postId): string
    {
        return Config::root() . '/storage/media/p' . $postId . '_slow.lock';
    }

    public static function arm(int $postId, int $chatId): void
    {
        if (getenv('PERFILEMDIA_DISABLE_SLOW_NOTICE') === '1') {
            return;
        }
        if ($postId <= 0 || $chatId <= 0 || !function_exists('exec')) {
            return;
        }
        $script = Config::root() . '/bin/slow-notice.php';
        if (!is_file($script)) {
            return;
        }
        $starter = is_file('/usr/bin/setsid') ? '/usr/bin/setsid' : 'nohup';
        $cmd = $starter . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' ' . $postId . ' ' . $chatId . ' > /dev/null 2>&1 < /dev/null &';
        exec($cmd);
    }
}
