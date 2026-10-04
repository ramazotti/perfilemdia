<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PerfilEmDia\Config;
use PerfilEmDia\Image\PhotoMark;

/**
 * Palavra-gatilho no tema ou ideia liga texto e imagem extra ao prompt da IA.
 */
final class PromptExtras
{
    public const MAX_PER_ACCOUNT = 8;

    public const MAX_TEXT_CHARS = 600;

    public const MAX_TRIGGER_CHARS = 48;

    public static function normalizeTrigger(string $raw): string
    {
        $clean = mb_strtolower(trim(strip_tags($raw)));
        $clean = (string) preg_replace('/\s+/u', ' ', $clean);

        return mb_substr($clean, 0, self::MAX_TRIGGER_CHARS);
    }

    public static function normalizeText(string $raw): string
    {
        $clean = trim(strip_tags($raw));

        return mb_substr($clean, 0, self::MAX_TEXT_CHARS);
    }

    public static function matches(string $haystack, string $trigger): bool
    {
        $trigger = self::normalizeTrigger($trigger);
        if ($trigger === '') {
            return false;
        }
        $haystack = mb_strtolower($haystack);

        return mb_stripos($haystack, $trigger) !== false;
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @return list<array<string, mixed>>
     */
    public static function matched(array $definitions, string $haystack): array
    {
        if (trim($haystack) === '') {
            return [];
        }
        $rows = [];
        foreach ($definitions as $row) {
            $trigger = (string) ($row['trigger_word'] ?? '');
            if (self::matches($haystack, $trigger)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $matched
     */
    public static function formatForCaption(array $matched): string
    {
        if ($matched === []) {
            return '';
        }
        $blocks = [];
        foreach ($matched as $row) {
            $trigger = (string) ($row['trigger_word'] ?? '');
            $text = trim((string) ($row['prompt_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $label = $trigger !== '' ? $trigger : 'extra';
            $blocks[] = '[' . $label . "]\n" . $text;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param list<array<string, mixed>> $matched
     */
    public static function formatForImageBrief(array $matched): string
    {
        if ($matched === []) {
            return '';
        }
        $bits = [];
        foreach ($matched as $row) {
            $trigger = (string) ($row['trigger_word'] ?? '');
            $text = trim((string) ($row['prompt_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $bits[] = 'When the idea mentions "' . $trigger . '", follow this brand direction: ' . $text;
        }

        return implode(' ', $bits);
    }

    /**
     * @param list<array<string, mixed>> $matched
     */
    public static function firstImageAbsolute(array $matched): ?string
    {
        foreach ($matched as $row) {
            $path = self::absoluteImagePath((string) ($row['image_path'] ?? ''));
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $matched
     * @return list<string>
     */
    public static function allImageAbsolutes(array $matched): array
    {
        $paths = [];
        foreach ($matched as $row) {
            $path = self::absoluteImagePath((string) ($row['image_path'] ?? ''));
            if ($path !== null) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param list<array<string, mixed>> $matched
     */
    public static function applyLogosToJpegBinary(string $jpeg, array $matched): string
    {
        $paths = self::allImageAbsolutes($matched);
        if ($paths === [] || $jpeg === '') {
            return $jpeg;
        }
        $file = tempnam(sys_get_temp_dir(), 'pe-brand-');
        if ($file === false) {
            return $jpeg;
        }
        $jpg = $file . '.jpg';
        if (!@rename($file, $jpg)) {
            $jpg = $file;
        }
        if (file_put_contents($jpg, $jpeg) === false) {
            @unlink($jpg);

            return $jpeg;
        }
        self::stampLogosOnFile($jpg, $paths);
        $out = file_get_contents($jpg);
        @unlink($jpg);

        return is_string($out) && $out !== '' ? $out : $jpeg;
    }

    /**
     * @param list<string> $absolutePaths
     */
    public static function stampLogosOnFile(string $jpegPath, array $absolutePaths): void
    {
        $places = ['tl', 'tr', 'bl', 'br'];
        foreach ($absolutePaths as $index => $path) {
            PhotoMark::stampPlate($jpegPath, $path, $places[$index] ?? 'br');
        }
    }

    public static function isPromptExtraImagePath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        return str_contains($path, '/storage/prompt_extras/');
    }

    public static function designedLogoBriefSuffix(array $matched): string
    {
        if (self::allImageAbsolutes($matched) === []) {
            return '';
        }

        return ' Do not draw any company logo, wordmark, or brand symbol. Leave generous empty space at the top for real logos that are added after generation.';
    }

    public static function absoluteImagePath(string $relative): ?string
    {
        $relative = trim($relative);
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $path = Config::root() . '/' . ltrim($relative, '/');

        return is_file($path) ? $path : null;
    }
}
