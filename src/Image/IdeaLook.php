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
    public static function brief(array $user, string $promptExtras = ''): string
    {
        $tone = (string) ($user['tone'] ?? '');
        $bits = [self::tone($tone), self::composition($tone)];
        $setting = self::professionSetting((string) ($user['profession'] ?? ''));
        if ($setting !== '') {
            $bits[] = $setting;
        }
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
        $extra = trim($promptExtras);
        if ($extra !== '') {
            $bits[] = $extra;
        }

        return implode(' ', $bits);
    }

    private static function tone(string $tone): string
    {
        return match ($tone) {
            'profissional' => 'Tone: professional. Polished, calm, credible, and quiet.',
            'descontraido' => 'Tone: relaxed. Warm and candid, but still tidy and believable.',
            'tecnico' => 'Tone: precise. Clear light, orderly, focused on one craft detail.',
            'acolhedor' => 'Tone: welcoming. Soft natural light, gentle, human, and calm.',
            default => 'Tone: natural and cared for, the kind of place someone is proud to show.',
        };
    }

    private static function composition(string $tone): string
    {
        return match ($tone) {
            'acolhedor' => 'Composition: very simple and uncluttered. One clear subject, lots of calm empty space, plain walls, few objects. '
                . 'No crowded rooms, no poster walls, no neon, no confetti, no busy patterns, no dramatic stock props. '
                . 'Think a quiet room someone trusts, not a marketing montage.',
            'profissional' => 'Composition: clean and restrained. Few elements, straight lines, nothing flashy or chaotic in the background.',
            'tecnico' => 'Composition: one tool, material, or step in focus. Background stays plain and orderly, not decorative.',
            'descontraido' => 'Composition: friendly and lived-in, but not messy. Avoid visual noise and clutter; keep the frame easy to read.',
            default => 'Composition: simple frame, real materials, no visual clutter or fake stock chaos.',
        };
    }

    private static function professionSetting(string $profession): string
    {
        $who = mb_strtolower(trim($profession));
        if ($who === '') {
            return '';
        }

        if (preg_match('/\b(professor|professora|docente|teacher|educador|educadora)\b/u', $who) === 1
            || str_contains($who, 'escola')
            || str_contains($who, 'ensino')) {
            return 'Setting: a calm school or classroom with white or light walls, simple desks, and a board. '
                . 'Only the essentials. No packed bulletin boards, no toy explosion, no carnival colors, no crowd of children.';
        }

        if (str_contains($who, 'psicolog') || str_contains($who, 'terapeut')) {
            return 'Setting: a quiet therapy or consultation room, soft light, one chair or sofa, minimal objects on shelves.';
        }

        if (str_contains($who, 'nutricion')) {
            return 'Setting: a clean consultation desk or a simple plate on a plain table, not a food collage.';
        }

        if (str_contains($who, 'advogad')) {
            return 'Setting: a tidy office desk or meeting table, neutral walls, few papers, no courtroom drama.';
        }

        if (str_contains($who, 'dentist')) {
            return 'Setting: a bright, sterile consultation room ready for use, without patients visible.';
        }

        return '';
    }

    private static function plain(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', trim($value)));

        return mb_substr($value, 0, 160);
    }
}
