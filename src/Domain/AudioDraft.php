<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PerfilEmDia\Config;

final class AudioDraft
{
    public static function path(int $userId): string
    {
        return Config::root() . '/storage/media/u' . $userId . '_audio.json';
    }

    public static function save(int $userId, string $text): bool
    {
        $path = self::path($userId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return file_put_contents($path, $json) !== false;
    }

    public static function take(int $userId): ?string
    {
        $path = self::path($userId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        self::clear($userId);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $text = trim((string) ($data['text'] ?? ''));

        return $text === '' ? null : $text;
    }

    public static function clear(int $userId): void
    {
        $path = self::path($userId);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
