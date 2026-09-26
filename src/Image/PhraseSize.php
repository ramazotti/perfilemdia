<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhraseSize
{
    public const MENOR = 'menor';

    public const NORMAL = 'normal';

    public const MAIOR = 'maior';

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, [self::MENOR, self::NORMAL, self::MAIOR], true)
            ? $value
            : self::NORMAL;
    }

    public static function label(string $size): string
    {
        return match (self::normalize($size)) {
            self::MENOR => 'Menor',
            self::MAIOR => 'Maior',
            default => 'Normal',
        };
    }

    public static function factor(string $size): float
    {
        return match (self::normalize($size)) {
            self::MENOR => 0.7,
            self::MAIOR => 1.35,
            default => 1.0,
        };
    }
}
