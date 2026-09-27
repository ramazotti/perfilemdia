<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class StoryScript
{
    public const MAX_PART_CHARS = 270;
    public const MAX_PARTS = 2;

    /**
     * @return list<string>
     */
    public static function parts(string $caption, string $contact = ''): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', self::body($caption, $contact)));
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        $frames = [];
        $current = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $next = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($next) > self::MAX_PART_CHARS && $current !== '') {
                $frames[] = $current;
                $current = $word;
                continue;
            }
            $current = $next;
        }
        if ($current !== '') {
            $frames[] = $current;
        }

        return array_slice($frames, 0, self::MAX_PARTS);
    }

    public static function body(string $caption, string $contact = ''): string
    {
        $caption = str_replace(["\\r\\n", "\\n", "\\r"], "\n", trim($caption));
        $blocks = preg_split("/\n{2,}/", $caption) ?: [];
        $contactFlat = self::compact($contact);
        $kept = [];
        foreach ($blocks as $block) {
            $trim = trim($block);
            if ($trim === '') {
                continue;
            }
            $flat = trim((string) preg_replace('/\s+/u', ' ', str_replace("\n", ' ', $trim)));
            if (preg_match('/^(?:#[\p{L}\p{N}_]+\s*)+$/u', $flat) === 1) {
                continue;
            }
            if ($contactFlat !== '' && str_contains(self::compact($trim), $contactFlat)) {
                continue;
            }
            if (preg_match('/^acesse\b/iu', $flat) === 1) {
                continue;
            }
            $flat = trim((string) preg_replace('/#[\p{L}\p{N}_]+/u', '', $flat));
            $flat = trim((string) preg_replace('/(?:^|\s+)acesse\b.*$/iu', '', $flat));
            $flat = trim((string) preg_replace('/\s+/u', ' ', $flat));
            if ($flat !== '') {
                $kept[] = $flat;
            }
        }

        return implode(' ', $kept);
    }

    private static function compact(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $text));
    }
}
