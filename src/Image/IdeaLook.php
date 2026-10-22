<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

/**
 * Visual direction from the profile the person already chose:
 * brief() for a photographic scene, design() for a designed card with text.
 */
final class IdeaLook
{
    /**
     * @param array<string, mixed> $user
     */
    public static function brief(array $user, string $promptExtras = '', string $idea = ''): string
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
        if ($where !== '' && mb_strtolower($where) !== 'online' && self::asksForPlace($idea, $where)) {
            $bits[] = 'Setting should feel like a real, well kept place in ' . $where . ', not a generic stock location.';
        }
        $about = self::plain((string) ($user['about'] ?? ''));
        if ($about !== '') {
            $bits[] = 'Context from the profile: ' . $about . '.';
        }
        if (self::looksSpiritual($user)) {
            $bits[] = 'Vary the scene: do not default to the same bible-candle-desk setup every time. '
                . 'Use different metaphors, outdoor light, hands in action, paper, paths, windows, or a single symbolic object.';
        }
        $extra = trim($promptExtras);
        if ($extra !== '') {
            $bits[] = $extra;
        }

        return implode(' ', $bits);
    }

    /**
     * Direção para arte com texto: paleta, tipografia e público. Sem cenário, luz ou cidade.
     *
     * @param array<string, mixed> $user
     */
    public static function design(array $user, string $promptExtras = ''): string
    {
        $bits = [];
        $brand = self::plain((string) ($user['brand_style'] ?? ''));
        $bits[] = $brand !== ''
            ? 'Palette and style come from the brand: ' . $brand . '. Use those colors as the main palette, with one accent and one neutral background.'
            : self::nichePalette($user);
        $bits[] = self::typography((string) ($user['tone'] ?? ''));
        $who = self::plain((string) ($user['profession'] ?? ''));
        $about = mb_substr(self::plain((string) ($user['about'] ?? '')), 0, 120);
        if ($who !== '' || $about !== '') {
            $bits[] = 'Audience: followers of ' . ($who !== '' ? 'a ' . $who : 'this profile')
                . ($about !== '' ? ' (' . $about . ')' : '') . '. Keep the mood fitting for them.';
        }
        $bits[] = 'Background stays simple: flat color, soft gradient, or a subtle texture. One small simple icon or line illustration is fine if it helps. No photographic scene, no rooms, no people.';
        $extra = trim($promptExtras);
        if ($extra !== '') {
            $bits[] = $extra;
        }

        return implode(' ', $bits);
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function nichePalette(array $user): string
    {
        return match (IdeaFormatBrief::niche($user)) {
            'spiritual' => 'Palette: deep blue or burgundy, warm gold accent, off-white. Serene and reverent.',
            'accounting' => 'Palette: navy or dark teal, one fresh accent, white. Trustworthy and orderly.',
            'education' => 'Palette: soft light background with one cheerful accent color. Friendly, never childish chaos.',
            default => 'Palette: two harmonious colors plus a neutral background. Clean and modern.',
        };
    }

    private static function typography(string $tone): string
    {
        return match ($tone) {
            'profissional' => 'Type: clean modern sans-serif, strong hierarchy, generous spacing.',
            'descontraido' => 'Type: rounded friendly sans-serif, relaxed but easy to read.',
            'tecnico' => 'Type: precise sans-serif on a tidy grid, numbers and labels very clear.',
            'acolhedor' => 'Type: warm elegant serif for the headline with a simple sans-serif for the rest.',
            default => 'Type: one clear headline font and one simple body font.',
        };
    }

    /**
     * A cidade só entra quando o pedido fala de um lugar; senão puxa cartão-postal.
     */
    private static function asksForPlace(string $idea, string $city): bool
    {
        $idea = mb_strtolower($idea);
        if ($idea === '') {
            return false;
        }

        return mb_stripos($idea, mb_strtolower($city)) !== false
            || preg_match('/\b(cidade|bairro|rua|pra[cç]a|centro|fachada|vitrine|loja|consult[oó]rio|escrit[oó]rio|igreja|par[oó]quia|sal[aã]o)\b/u', $idea) === 1;
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

    /**
     * @param array<string, mixed> $user
     */
    private static function looksSpiritual(array $user): bool
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            (string) ($user['profession'] ?? ''),
            (string) ($user['about'] ?? ''),
            (string) ($user['display_name'] ?? ''),
        ], static fn (string $p): bool => trim($p) !== ''))));

        return $haystack !== ''
            && preg_match('/confiss|consci[eê]ncia|cat[oó]lic|espiritual|\bora[cç][aã]o|\bigreja|ter[cç]o|b[ií]bl|exame de consci/u', $haystack) === 1;
    }
}
