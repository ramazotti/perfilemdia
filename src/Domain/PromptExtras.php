<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PerfilEmDia\Config;

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
