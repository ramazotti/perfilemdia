<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

/**
 * Visual direction for a generated photo, from the profile the person already chose.
 */
final class IdeaLook
{
    /**
     * @param array<string, mixed> $user
     */
    public static function brief(array $user): string
    {
        $bits = [self::tone((string) ($user['tone'] ?? ''))];
        $who = self::plain((string) ($user['profession'] ?? ''));
        if ($who !== '') {
            $bits[] = 'This image is for a ' . $who . '. Show that real work, not a generic stand-in.';
        }
        $brand = self::plain((string) ($user['brand_style'] ?? ''));
        if ($brand !== '') {
            $bits[] = 'Brand look: ' . $brand . '.';
        }
        $where = self::plain((string) ($user['city'] ?? ''));
        if ($where !== '' && mb_strtolower($where) !== 'online') {
            $bits[] = 'Setting should feel like a real, well kept place in ' . $where . ', not a generic stock location.';
        }
        $about = self::plain((string) ($user['about'] ?? ''));
        if ($about !== '') {
            $bits[] = 'Context from the profile: ' . $about . '.';
        }

        return implode(' ', $bits);
    }

    private static function tone(string $tone): string
    {
        return match ($tone) {
            'profissional' => 'Tone: professional. Polished, calm, well kept, and credible.',
            'descontraido' => 'Tone: relaxed. Candid, warm, lively, and still cared for.',
            'tecnico' => 'Tone: precise. Clear light, orderly, and focused on the craft.',
            'acolhedor' => 'Tone: welcoming. Soft natural light, close, human, and gentle.',
            default => 'Tone: natural and cared for, the kind of place someone is proud to show.',
        };
    }

    private static function plain(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', trim($value)));

        return mb_substr($value, 0, 160);
    }
}
