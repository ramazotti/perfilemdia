<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class ImageEditRequest
{
    public function __construct(
        public readonly string $treatment,
        public readonly ?string $phrase,
    ) {
    }

    public static function parse(string $text): self
    {
        $text = trim(strip_tags($text));
        $phrase = null;
        if (preg_match("/[\"\\x{201C}\\x{201D}](.+?)[\"\\x{201C}\\x{201D}]/u", $text, $matches) === 1) {
            $phrase = trim($matches[1]);
            $text = trim(str_replace($matches[0], ' ', $text));
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            $text = trim($text, " \t,.;");
        }
        if ($phrase === '') {
            $phrase = null;
        }
        if ($phrase !== null) {
            $phrase = mb_substr($phrase, 0, 80);
        }

        return new self(mb_substr($text, 0, 500), $phrase);
    }
}
