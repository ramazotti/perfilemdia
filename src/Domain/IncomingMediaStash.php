<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PerfilEmDia\Config;

/**
 * Keeps photo/video sent before the user picks feed/story and post kind (/novo wizard).
 */
final class IncomingMediaStash
{
    public static function path(int $userId): string
    {
        return Config::root() . '/storage/media/u' . $userId . '_incoming.json';
    }

    public static function has(int $userId): bool
    {
        $path = self::path($userId);

        return is_file($path) && filesize($path) > 0;
    }

    public static function clear(int $userId): void
    {
        $path = self::path($userId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $message Telegram message with photo, video or document
     */
    public static function save(int $userId, array $message): bool
    {
        if (!self::canStash($message)) {
            return false;
        }
        $payload = [
            'caption' => $message['caption'] ?? null,
            'message_id' => (int) ($message['message_id'] ?? 0),
            'media_group_id' => $message['media_group_id'] ?? null,
            'photo' => $message['photo'] ?? null,
            'video' => $message['video'] ?? null,
            'video_note' => $message['video_note'] ?? null,
            'document' => $message['document'] ?? null,
        ];
        $path = self::path($userId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return file_put_contents($path, $json) !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function load(int $userId): ?array
    {
        $path = self::path($userId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function toMessage(array $payload): array
    {
        $message = [
            'message_id' => (int) ($payload['message_id'] ?? 0),
        ];
        if (isset($payload['caption']) && is_string($payload['caption']) && $payload['caption'] !== '') {
            $message['caption'] = $payload['caption'];
        }
        if (isset($payload['media_group_id']) && is_string($payload['media_group_id']) && $payload['media_group_id'] !== '') {
            $message['media_group_id'] = $payload['media_group_id'];
        }
        foreach (['photo', 'video', 'video_note', 'document'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $message[$key] = $payload[$key];
            }
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function canStash(array $message): bool
    {
        if (!empty($message['photo']) && is_array($message['photo'])) {
            return true;
        }
        if (!empty($message['video']) && is_array($message['video'])) {
            return true;
        }
        if (!empty($message['video_note']) && is_array($message['video_note'])) {
            return true;
        }
        if (!empty($message['document']) && is_array($message['document'])) {
            $mime = strtolower((string) ($message['document']['mime_type'] ?? ''));

            return str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/');
        }

        return false;
    }
}
