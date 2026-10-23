<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class IdeaPieces
{
    public const FEED_CAP = 10;

    public const STORY_CAP = 3;

    public const PHOTO_CUE = 'Foto simples:';

    /** Escolha "Manter o que aparece na foto" (fica no tema para valer também ao refazer). */
    public const KEEP_REFERENCE = 'Referência: manter o que aparece na foto.';

    private const NUMBER = '(?:[0-9]{1,2}|um|uma|dois|duas|tr[e\x{00ea}]s|quatro|cinco|seis|sete|oito|nove|dez)';

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

    /**
     * @return list<array{key:string, label:string}>
     */
    public static function formatChoices(): array
    {
        return [
            ['key' => 'photo', 'label' => 'Foto simples (sem texto)'],
            ['key' => 'infographic', 'label' => 'Infográfico (sequência)'],
            ['key' => 'quote', 'label' => 'Citação'],
            ['key' => 'checklist', 'label' => 'Checklist'],
            ['key' => 'compare', 'label' => 'Comparativo'],
            ['key' => 'before_after', 'label' => 'Antes e depois'],
            ['key' => 'steps', 'label' => 'Passo a passo'],
            ['key' => 'steps3', 'label' => 'Passo a passo (3 passos)'],
        ];
    }

    public static function isFormatKey(string $key): bool
    {
        foreach (self::formatChoices() as $choice) {
            if ($choice['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    public static function formatLabel(string $key): string
    {
        foreach (self::formatChoices() as $choice) {
            if ($choice['key'] === $key) {
                return $choice['label'];
            }
        }

        return '';
    }

    public static function cueFromKey(string $key, string $aspect): string
    {
        if (!self::isFormatKey($key)) {
            return '';
        }
        if ($key === 'photo') {
            return self::PHOTO_CUE;
        }

        return match ($key) {
            'quote' => 'Citação:',
            'checklist' => 'Checklist:',
            'compare' => 'Comparativo:',
            'before_after' => 'Antes e depois:',
            'steps' => 'Passo a passo:',
            'steps3' => 'Passo a passo com 3:',
            'infographic' => $aspect === '9:16'
                ? 'Story com 3 telas:'
                : 'Infográfico com 3 slides:',
            default => '',
        };
    }

    /**
     * O formato escolhido pela pessoa vale mais que as palavras do pedido:
     * troca o rótulo que já estiver no começo e põe o do formato.
     */
    public static function applyCue(string $idea, string $cue): string
    {
        $idea = trim($idea);
        if ($cue === '') {
            return $idea;
        }
        if (self::leadingKind($idea) === self::leadingKind($cue)) {
            return $idea;
        }

        return rtrim($cue) . ' ' . self::stripFormatCue($idea);
    }

    /**
     * @return array{0:string,1:string}
     */
    public static function dress(string $idea, string $aspect, bool $surprise): array
    {
        if ($surprise || self::isDesigned($idea) || $aspect === '9:16') {
            return [$idea, ''];
        }
        $cue = self::randomCue($aspect);
        if ($cue === '') {
            return [$idea, ''];
        }

        return [$cue . ' ' . $idea, $cue];
    }

    public static function isDesigned(string $text): bool
    {
        return self::kind($text) !== 'photo';
    }

    public static function kind(string $text): string
    {
        $lead = self::leadingKind($text);
        if ($lead !== null) {
            return $lead;
        }
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
        $lead = self::leadingKind($idea);
        $kind = $lead ?? self::kind($idea);
        $cap = $aspect === '9:16' ? self::STORY_CAP : self::FEED_CAP;
        if ($kind === 'photo') {
            return [['kind' => 'photo', 'index' => 1, 'count' => 1]];
        }

        $count = in_array($lead, ['quote', 'checklist', 'compare'], true) ? 1 : self::explicitCount($idea);
        if ($count === 0 && $lead === null && self::asksSequence($idea)) {
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

    /**
     * @param array{title:string, text:string}|null $slide texto planejado para este slide da série
     * @param bool $styleReference a última imagem anexada é o slide 1 da mesma série
     */
    public static function prompt(
        string $idea,
        bool $hasReference,
        string $brand,
        string $aspect,
        int $index = 1,
        int $count = 0,
        ?array $slide = null,
        bool $styleReference = false,
    ): string {
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
            $slide,
            $styleReference,
        );
    }

    /**
     * Itens que a pessoa (ou o Surpreenda-me) já escreveu para entrar na arte.
     *
     * @return list<string>
     */
    public static function listedItems(string $idea, string $kind): array
    {
        $pattern = match ($kind) {
            'checklist' => '/Item\s*\d+\s*:\s*([^\n;]+?)(?=\s*(?:Item\s*\d+\s*:|[;\n]|$))/iu',
            'steps' => '/Passo\s*\d+\s*[:\x{2013}\-]\s*([^\n;]+?)(?=\s*(?:Passo\s*\d+\s*[:\x{2013}\-]|[;\n]|$))/iu',
            'compare' => '/Coluna\s+(?:esquerda|direita)\s*:\s*([^\n;]+?)(?=\s*(?:Coluna\s+(?:esquerda|direita)\s*:|[;\n]|$))/iu',
            default => '',
        };
        if ($pattern === '' || preg_match_all($pattern, $idea, $matches) < 1) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $item): string => trim(rtrim(trim($item), '.')), $matches[1]),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Rótulo de formato no começo do texto (posto pelo botão de formato).
     */
    private static function leadingKind(string $text): ?string
    {
        $text = ltrim($text);
        $patterns = [
            'photo' => '/^foto\s+simples\s*:/iu',
            'quote' => '/^cita[c\x{00e7}][a\x{00e3}]o\s*:/iu',
            'checklist' => '/^check\s*list\s*:/iu',
            'compare' => '/^comparativo\s*:/iu',
            'before_after' => '/^antes\s+e\s+depois\s*:/iu',
            'steps' => '/^passo\s+a\s+passo(?:\s+(?:com|de)\s+' . self::NUMBER . ')?\s*:/iu',
            'infographic' => '/^(?:infogr[a\x{00e1}]fico(?:\s+com\s+\d+\s+slides?)?|story\s+com\s+\d+\s+telas?)\s*:/iu',
        ];
        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $kind;
            }
        }

        return null;
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

    /**
     * @param array{title:string, text:string}|null $slide
     */
    private static function imageBrief(string $idea, string $kind = 'photo', ?array $slide = null, int $index = 1): string
    {
        $parts = ['Before drawing, decide what the picture shows.'];
        if ($kind === 'photo') {
            // Aspas no pedido de foto são contexto, nunca texto na imagem.
            $parts[] = 'Do not write any words on the image.';
            $parts[] = 'Idea to show, never printed: ' . rtrim(self::stripFormatCue($idea), ' .') . '.';

            return implode(' ', $parts);
        }

        $exact = self::exactWords($idea);
        $credit = self::credit($idea);
        $items = self::listedItems($idea, $kind);
        if ($slide !== null) {
            $parts[] = 'Exact text on this image, spelled as written, and nothing else: headline "' . $slide['title'] . '"'
                . ($slide['text'] !== '' ? '; body "' . $slide['text'] . '"' : '') . '.';
        } elseif ($exact !== '') {
            $parts[] = 'Exact text on the image, spelled as written. Do not add any other sentence from the assignment: "' . $exact . '".';
        } elseif ($kind === 'quote') {
            $parts[] = 'The assignment gives no exact quote. Pick one short, real, well-known quote in Brazilian Portuguese that fits the idea. If it is from the Bible, use the exact wording and put the correct book chapter:verse in a small credit line. Never invent an author or a reference. Do not print the assignment itself.';
        } elseif ($kind === 'steps' && isset($items[$index - 1]) && count($items) > 1 && self::seriesStep($idea)) {
            $parts[] = 'Exact text of this step, spelled as written: "' . $items[$index - 1] . '".';
        } elseif ($items !== []) {
            $parts[] = 'Print exactly these items, in this order, spelled as written: ' . implode(' | ', array_map(
                static fn (string $item): string => '"' . $item . '"',
                $items,
            )) . '. Add only a short title if needed.';
        } else {
            $parts[] = 'No sentence from the assignment may appear on the image. If the layout needs words, write new short ones in Brazilian Portuguese about the idea.';
        }
        if ($credit !== '' && $slide === null) {
            $parts[] = 'Small credit line under the main text, spelled as written: "' . $credit . '".';
        }
        $parts[] = 'Idea to show, never printed: ' . rtrim(self::briefBody($idea), ' .') . '.';
        $parts[] = 'Do not print the format name, a sentence that starts with Mostre, the label Visual, or the label Para.';

        return implode(' ', $parts);
    }

    private static function seriesStep(string $idea): bool
    {
        return count(self::pieces($idea, '4:5')) > 1;
    }

    /**
     * Pedido sem o rótulo do formato e sem os trechos que já viraram texto exato.
     */
    private static function briefBody(string $idea): string
    {
        $body = self::stripFormatCue($idea);
        $clean = preg_replace(
            [
                '/Texto na imagem:\s*[\x{00ab}"\x{201c}][^\x{00bb}"\x{201d}]*[\x{00bb}"\x{201d}]\s*/u',
                '/Cr[e\x{00e9}]dito:\s*[^\n]*?(?=\.\s|\.$|\n|$)\.?\s*/u',
            ],
            '',
            $body,
        );

        return trim(is_string($clean) && trim($clean) !== '' ? $clean : $body);
    }

    private static function credit(string $idea): string
    {
        if (preg_match('/Cr[e\x{00e9}]dito:\s*([^\n]{2,80}?)(?=\.\s|\.$|\n|$)/u', $idea, $match) !== 1) {
            return '';
        }

        return trim($match[1], " \t\"\x{00ab}\x{00bb}");
    }

    private static function keepsReference(string $idea): bool
    {
        return str_contains($idea, self::KEEP_REFERENCE);
    }

    private static function stripFormatCue(string $idea): string
    {
        $text = trim(str_replace(self::KEEP_REFERENCE, '', $idea));
        $pattern = '/^(?:foto\s+simples|infogr[a\x{00e1}]fico(?:\s+com\s+\d+\s+slides?)?|story\s+com\s+\d+\s+telas?|cita[c\x{00e7}][a\x{00e3}]o|check\s*list|comparativo|antes\s+e\s+depois|passo\s+a\s+passo(?:\s+(?:com|de)\s+(?:[0-9]{1,2}|um|uma|dois|duas|tr[e\x{00ea}]s|quatro|cinco|seis|sete|oito|nove|dez))?)\s*:\s*/iu';
        $stripped = preg_replace($pattern, '', $text, 1);

        return trim(is_string($stripped) ? $stripped : $text);
    }

    /**
     * Texto exato já definido para a arte («...» em Texto na imagem, ou entre aspas).
     */
    public static function exactText(string $idea): string
    {
        return self::exactWords($idea);
    }

    /**
     * Pedido sem o texto exato e sem o crédito (para escolher outra citação).
     */
    public static function withoutExactText(string $idea): string
    {
        $clean = preg_replace(
            [
                '/\s*Texto na imagem:\s*[\x{00ab}"\x{201c}][^\x{00bb}"\x{201d}]*[\x{00bb}"\x{201d}]/u',
                '/\s*Cr[e\x{00e9}]dito:\s*[^\n]*?(?=\.\s|\.$|\n|$)\.?/u',
            ],
            '',
            $idea,
        );

        return trim(is_string($clean) ? $clean : $idea);
    }

    public static function withExactQuote(string $idea, string $quote, string $credit = ''): string
    {
        $base = self::withoutExactText($idea);

        return $base . ' Texto na imagem: «' . trim($quote) . '»' . (trim($credit) !== '' ? ' Crédito: ' . trim($credit) . '.' : '');
    }

    private static function exactWords(string $idea): string
    {
        if (preg_match('/Texto na imagem:\s*[«"\x{201c}]([^»"\x{201d}]{2,220})[»"\x{201d}]/u', $idea, $match) === 1) {
            return trim($match[1]);
        }
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
        $clean = ' No text, letters, numbers, logos, or watermarks.'
            . ' People: no identifiable faces unless the idea asks for a specific person; prefer hands, silhouettes, people seen from behind, or objects.';
        if ($hasReference && self::keepsReference($idea)) {
            $frame = $story
                ? ' Photorealistic, natural color, vertical full screen, 9:16.'
                : ($feedPortrait ? ' Photorealistic, natural color, vertical 4:5.' : ' Photorealistic, natural color.');

            return 'The attached photo shows the real subject (a product, a person, or a place). Keep that subject faithful: same shape, colors, labels, and details; do not invent a different one.'
                . ' Change only what the idea below asks for: background, setting, light, angle, or framing.'
                . $frame . $clean . $look
                . ' ' . self::imageBrief($idea);
        }
        if ($hasReference) {
            $frame = $story
                ? ' Photorealistic, natural color, shot on a camera, vertical full screen, 9:16.'
                : ($feedPortrait
                    ? ' Photorealistic, natural color, shot on a camera, vertical 4:5.'
                    : ' Photorealistic, natural color, shot on a camera.');

            return 'The attached image is reference only, not the final post. Create a NEW photograph that fulfills the written idea below.'
                . ' Do not return the same picture, a crop, or a near-duplicate. You may echo the subject, symbols, era, or mood suggested by the reference, but compose a fresh scene that matches the idea text.'
                . $frame . $clean . $look
                . ' ' . self::imageBrief($idea);
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
        ?array $slide = null,
        bool $styleReference = false,
    ): string {
        $frame = $aspect === '9:16'
            ? 'Full-screen vertical design, 9:16. Instagram covers the top 14% (profile name) and the bottom 20% (reply bar): keep every word out of those bands.'
            : 'Vertical design, 3:4. The picture will be trimmed to 4:5, so keep every word, number and credit line at least 8% away from the top and bottom edges.';
        $source = match (true) {
            $hasReference && self::keepsReference($idea) => 'Place the real subject of the first attached photo inside the design, faithful to it (same shape, colors, and labels), cut out or framed. The rest of the design is not a photograph.',
            $hasReference => 'The first attached photo is only a reference for subject or color. Do not return that photo with a caption bar. Do not make a photograph.',
            default => 'Do not make a photograph.',
        };
        $place = $count > 1
            ? ' This is image ' . $index . ' of ' . $count . ' in one series. Use the same colors, type, and margins on every image. Print ' . $index . '/' . $count . ' small in the bottom-right corner.'
            : '';
        if ($styleReference) {
            $place .= ' The last attached image is image 1 of this same series: match its palette, fonts, layout grid, and margins exactly. Change only the text and the small illustration.';
        }
        $job = match ($kind) {
            'quote' => 'Design a quote card for Instagram, not a scenic photo. Print the exact quote from Texto na imagem or from guillemets in the assignment, in very large high-contrast type. Add a small credit line (author or Bible reference) when the assignment includes Crédito. Background may be subtle texture or soft color tied to the niche; text must dominate.',
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
            . ' ' . self::imageBrief($idea, $kind, $slide, $index);
    }
}
