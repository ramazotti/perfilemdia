<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class IdeaPieces
{
    public const FEED_CAP = 10;

    public const STORY_CAP = 3;

    /**
     * @return list<string>
     */
    public static function cues(string $aspect): array
    {
        $series = $aspect === '9:16' ? 'Story com 3 telas:' : 'Infográfico com 3 slides:';

        return [
            '',
            'Infográfico:',
            $series,
            'Citação:',
            'Checklist:',
            'Comparativo:',
            'Antes e depois:',
            'Passo a passo:',
            'Passo a passo com 3:',
        ];
    }

    public static function randomCue(string $aspect): string
    {
        $cues = self::cues($aspect);

        return $cues[random_int(0, count($cues) - 1)];
    }

    public static function isDesigned(string $text): bool
    {
        return self::kind($text) !== 'photo';
    }

    public static function kind(string $text): string
    {
        if (preg_match('/antes\s+e\s+depois/iu', $text) === 1) {
            return 'before_after';
        }
        if (preg_match('/passo\s+a\s+passo|\d+\s+passos/iu', $text) === 1) {
            return 'steps';
        }
        if (preg_match('/cita[c\x{00e7}][a\x{00e3}]o/iu', $text) === 1) {
            return 'quote';
        }
        if (preg_match('/comparativo/iu', $text) === 1) {
            return 'compare';
        }
        if (preg_match('/check\s*list/iu', $text) === 1) {
            return 'checklist';
        }
        if (IdeaImage::isInfographic($text) || self::explicitCount($text) > 0 || self::asksSequence($text)) {
            return 'infographic';
        }

        return 'photo';
    }

    /**
     * @return list<array{kind: string, index: int, count: int}>
     */
    public static function pieces(string $idea, string $aspect): array
    {
        $kind = self::kind($idea);
        $cap = $aspect === '9:16' ? self::STORY_CAP : self::FEED_CAP;
        if ($kind === 'photo') {
            return [['kind' => 'photo', 'index' => 1, 'count' => 1]];
        }

        $count = self::explicitCount($idea);
        if ($count === 0 && self::asksSequence($idea)) {
            $count = 3;
        }
        if ($kind === 'before_after') {
            $count = max(2, $count);
        }
        if ($count < 1) {
            $count = 1;
        }
        $count = min($cap, $count);

        $pieces = [];
        for ($index = 1; $index <= $count; $index++) {
            $pieces[] = ['kind' => $kind, 'index' => $index, 'count' => $count];
        }

        return $pieces;
    }

    public static function prompt(string $idea, bool $hasReference, string $brand, string $aspect, int $index = 1, int $count = 0): string
    {
        $pieces = self::pieces($idea, $aspect);
        $piece = $pieces[0];
        foreach ($pieces as $candidate) {
            if ($candidate['index'] === $index) {
                $piece = $candidate;
                break;
            }
        }
        if ($count > 0) {
            $piece['count'] = $count;
        }
        if ($piece['kind'] === 'photo') {
            return self::photoPrompt($idea, $hasReference, $brand, $aspect);
        }

        return self::designedPrompt(
            $idea,
            $hasReference,
            $brand,
            $aspect,
            $piece['kind'],
            $piece['index'],
            $piece['count'],
        );
    }

    private static function explicitCount(string $text): int
    {
        $nearSlides = self::numberNear($text, 'slides?|telas?|carross[e\x{00e9}]is?');
        if ($nearSlides > 0) {
            return $nearSlides;
        }
        if (preg_match('/passo\s+a\s+passo(?:\s+(?:com|de))?\s+([0-9]{1,2}|um|uma|dois|duas|tr[e\x{00ea}]s|quatro|cinco|seis|sete|oito|nove|dez)/iu', $text, $match) === 1) {
            return self::toInt((string) $match[1]);
        }

        return self::numberNear($text, 'passos');
    }

    private static function asksSequence(string $text): bool
    {
        return preg_match('/em\s+sequ[e\x{00ea}]ncia|\bslides?\b|\bcarrossel\b/iu', $text) === 1;
    }

    private static function numberNear(string $text, string $noun): int
    {
        $number = '([0-9]{1,2}|um|uma|dois|duas|tr[e\x{00ea}]s|quatro|cinco|seis|sete|oito|nove|dez)';
        if (preg_match('/' . $number . '\s+(?:' . $noun . ')/iu', $text, $match) === 1) {
            return self::toInt((string) $match[1]);
        }
        if (preg_match('/(?:' . $noun . ')\s+(?:de|com)\s+' . $number . '/iu', $text, $match) === 1) {
            return self::toInt((string) $match[1]);
        }

        return 0;
    }

    private static function toInt(string $token): int
    {
        $token = mb_strtolower(trim($token));
        $token = strtr($token, ["\u{00e1}" => 'a', "\u{00e3}" => 'a', "\u{00e9}" => 'e', "\u{00ea}" => 'e', "\u{00ed}" => 'i', "\u{00f3}" => 'o', "\u{00f5}" => 'o', "\u{00fa}" => 'u', "\u{00e7}" => 'c']);
        $words = [
            'um' => 1,
            'uma' => 1,
            'dois' => 2,
            'duas' => 2,
            'tres' => 3,
            'quatro' => 4,
            'cinco' => 5,
            'seis' => 6,
            'sete' => 7,
            'oito' => 8,
            'nove' => 9,
            'dez' => 10,
        ];
        if (isset($words[$token])) {
            return $words[$token];
        }
        $value = (int) $token;

        return $value > 0 ? $value : 0;
    }

    private static function imageBrief(string $idea): string
    {
        $body = self::stripFormatCue($idea);
        $exact = self::exactWords($idea);
        $parts = ['Before drawing, decide what the picture shows.'];
        if ($exact !== '') {
            $parts[] = 'Exact text on the image, spelled as written. Do not add any other sentence from the assignment: "' . $exact . '".';
        } else {
            $parts[] = 'No sentence from the assignment may appear on the image. If the layout needs words, write new short ones in Brazilian Portuguese about the idea.';
        }
        $parts[] = 'Idea to show, never printed: ' . $body . '.';
        $parts[] = 'Do not print the format name, a sentence that starts with Mostre, the label Visual, or the label Para.';

        return implode(' ', $parts);
    }

    private static function stripFormatCue(string $idea): string
    {
        $text = trim($idea);
        $pattern = '/^(?:infogr[a\x{00e1}]fico(?:\s+com\s+\d+\s+slides?)?|story\s+com\s+\d+\s+telas?|cita[c\x{00e7}][a\x{00e3}]o|check\s*list|comparativo|antes\s+e\s+depois|passo\s+a\s+passo(?:\s+(?:com|de)\s+(?:[0-9]{1,2}|um|uma|dois|duas|tr[e\x{00ea}]s|quatro|cinco|seis|sete|oito|nove|dez))?)\s*:\s*/iu';
        $stripped = preg_replace($pattern, '', $text, 1);

        return trim(is_string($stripped) ? $stripped : $text);
    }

    private static function exactWords(string $idea): string
    {
        if (preg_match('/["\x{201c}]([^"\x{201d}]{2,160})["\x{201d}]/u', $idea, $match) === 1) {
            return trim($match[1]);
        }
        if (preg_match('/\x{00ab}([^\x{00bb}]{2,160})\x{00bb}/u', $idea, $match) === 1) {
            return trim($match[1]);
        }

        return '';
    }

    private static function photoPrompt(string $idea, bool $hasReference, string $brand, string $aspect): string
    {
        $story = $aspect === '9:16';
        $feedPortrait = $aspect === '4:5';
        $look = trim($brand) !== '' ? ' ' . trim($brand) : '';
        $clean = ' No text, letters, numbers, logos, or watermarks.';
        if ($hasReference) {
            $frame = $story
                ? ' Photorealistic, natural color, shot on a camera, vertical full screen, 9:16.'
                : ($feedPortrait
                    ? ' Photorealistic, natural color, shot on a camera, vertical 4:5.'
                    : ' Photorealistic, natural color, shot on a camera.');

            return 'The attached photo is the real scene. Keep the same people, their ages, faces, clothing, uniforms, objects, and the same place. Keep the same level of care: do not replace the scene with a different school, courtyard, building, poorer, generic, or neglected location. If the idea mentions sky or looking up, show that mood with these same people in this same place, not a new wide shot of another school. You may adjust framing, light, and sky so the feeling matches the idea. Do not add clutter, props, or busy backgrounds that were not there.'
                . $frame . $clean . $look
                . ' The idea describes the feeling, not a new place. ' . self::imageBrief($idea);
        }

        $open = $story
            ? 'Create one photorealistic vertical photo, full screen, 9:16, as if shot on a camera in a real, cared-for place.'
            : ($feedPortrait
                ? 'Create one photorealistic vertical photo, 4:5, as if shot on a camera in a real, cared-for place.'
                : 'Create one photorealistic photo, as if shot on a camera in a real, cared-for place.');

        return $open . ' Natural color and real materials. Do not invent a rundown, neglected, or generic stock setting.'
            . ' Follow the tone and composition rules in the profile look below; if they ask for calm or simple, keep the scene minimal.'
            . $clean . $look . ' ' . self::imageBrief($idea);
    }

    private static function designedPrompt(
        string $idea,
        bool $hasReference,
        string $brand,
        string $aspect,
        string $kind,
        int $index,
        int $count,
    ): string {
        $frame = $aspect === '9:16'
            ? 'Full-screen vertical design, 9:16.'
            : 'Vertical design, 3:4. Leave a clear empty band at the top and at the bottom.';
        $source = $hasReference
            ? 'The attached photo is only a reference for subject or color. Do not return that photo with a caption bar.'
            : 'Do not make a photograph.';
        $place = $count > 1
            ? ' This is image ' . $index . ' of ' . $count . ' in one series. Use the same colors, type, and margins on every image. Print ' . $index . '/' . $count . ' in a corner.'
            : '';
        $job = match ($kind) {
            'quote' => 'Design a quote card. One short sentence in very large type, and a small credit line only if the request names who said it.',
            'compare' => 'Design one comparison with two clear columns. The labels come from the request.',
            'checklist' => 'Design one checklist. Short items, each with a simple mark, six items at most.',
            'steps' => $count > 1
                ? 'Design step ' . $index . ' only, with a large number. Do not repeat the other steps.'
                : 'Design one step-by-step graphic. A short title and at most five numbered steps.',
            'before_after' => $index === 1
                ? 'Design the BEFORE state and label it ANTES. Keep room for the matching AFTER image.'
                : ($index === $count
                    ? 'Design the AFTER state and label it DEPOIS. Match the framing of the BEFORE image.'
                    : 'Design the middle of the change, between ANTES and DEPOIS.'),
            default => $count > 1
                ? ($index === 1
                    ? 'Design the cover of an infographic series. A short title and one line of context.'
                    : ($index === $count
                        ? 'Design the closing image of the infographic series. If the request names a site or a call to action, put it here only.'
                        : 'Design one middle image of the infographic series. One point only, with a short title.'))
                : 'Design one clean infographic, not a photo and not a poster with a paragraph over a picture. Use a short title and at most five short lines.',
        };
        $look = trim($brand) !== '' ? ' Follow this visual profile: ' . trim($brand) : '';

        return $job . ' ' . $frame . ' ' . $source . $place
            . ' Brazilian Portuguese only, spelled correctly.'
            . ' Large type, high contrast, generous margins, and every word fully inside the frame.'
            . ' Flat or editorial layout, few colors, no watermark, no tiny footnotes, no English labels.'
            . $look
            . ' ' . self::imageBrief($idea);
    }
}
