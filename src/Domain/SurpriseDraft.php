<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PerfilEmDia\Config;

/**
 * Rascunho do Surpreenda-me antes de chamar a IA (ideia + frase na foto).
 */
final class SurpriseDraft
{
    public static function path(int $userId): string
    {
        return Config::root() . '/storage/media/u' . $userId . '_surprise.json';
    }

    public static function clear(int $userId): void
    {
        $path = self::path($userId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return array{idea:string, phrase:string}|null
     */
    public static function load(int $userId): ?array
    {
        $path = self::path($userId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $idea = trim((string) ($data['idea'] ?? ''));
        if ($idea === '') {
            return null;
        }

        return [
            'idea' => $idea,
            'phrase' => trim((string) ($data['phrase'] ?? '')),
        ];
    }

    public static function save(int $userId, string $idea, string $phrase): bool
    {
        $idea = trim($idea);
        if ($idea === '') {
            return false;
        }
        $payload = json_encode([
            'idea' => $idea,
            'phrase' => trim($phrase),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }
        $path = self::path($userId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($path, $payload) !== false;
    }

    public static function appendComplement(int $userId, string $complement): bool
    {
        $draft = self::load($userId);
        if ($draft === null) {
            return false;
        }
        $complement = trim($complement);
        if ($complement === '') {
            return false;
        }
        $draft['idea'] = trim($draft['idea'] . "\n\nComplemento: " . $complement);

        return self::save($userId, $draft['idea'], $draft['phrase']);
    }
}
