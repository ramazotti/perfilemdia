<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

final class SpeechMessage
{
    public const MAX_SECONDS = 180;
    public const MAX_BYTES = 10485760;

    /**
     * @param array<string, mixed> $message
     * @return array{file_id:string, format:string, duration:int, bytes:int}|null
     */
    public static function from(array $message): ?array
    {
        if (!empty($message['photo']) || !empty($message['video']) || !empty($message['video_note'])) {
            return null;
        }

        $node = null;
        $fallback = 'ogg';
        if (!empty($message['voice']) && is_array($message['voice'])) {
            $node = $message['voice'];
            $fallback = 'ogg';
        } elseif (!empty($message['audio']) && is_array($message['audio'])) {
            $node = $message['audio'];
            $fallback = 'mp3';
        } elseif (!empty($message['document']) && is_array($message['document'])) {
            $mime = strtolower((string) ($message['document']['mime_type'] ?? ''));
            if (!str_starts_with($mime, 'audio/')) {
                return null;
            }
            $node = $message['document'];
            $fallback = 'mp3';
        }

        if (!is_array($node) || empty($node['file_id'])) {
            return null;
        }

        return [
            'file_id' => (string) $node['file_id'],
            'format' => self::format($node, $fallback),
            'duration' => (int) ($node['duration'] ?? 0),
            'bytes' => (int) ($node['file_size'] ?? 0),
        ];
    }

    /**
     * @param array{file_id:string, format:string, duration:int, bytes:int} $speech
     */
    public static function tooLong(array $speech): bool
    {
        return $speech['duration'] > self::MAX_SECONDS || $speech['bytes'] > self::MAX_BYTES;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function format(array $node, string $fallback): string
    {
        $mime = strtolower((string) ($node['mime_type'] ?? ''));
        $name = strtolower((string) ($node['file_name'] ?? ''));
        $map = [
            'audio/ogg' => 'ogg',
            'audio/opus' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/m4a' => 'm4a',
            'audio/x-m4a' => 'm4a',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/webm' => 'webm',
            'audio/aac' => 'aac',
            'audio/flac' => 'flac',
        ];
        if (isset($map[$mime])) {
            return $map[$mime];
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (in_array($ext, ['ogg', 'mp3', 'm4a', 'wav', 'webm', 'aac', 'flac'], true)) {
            return $ext;
        }

        return $fallback;
    }
}
