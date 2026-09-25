<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhraseColor
{
    public const DOURADO = 'dourado';

    public const BRANCO = 'branco';

    public const PRETO = 'preto';

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, [self::DOURADO, self::BRANCO, self::PRETO], true)
            ? $value
            : self::BRANCO;
    }

    public static function label(string $color): string
    {
        return match (self::normalize($color)) {
            self::DOURADO => 'Dourado',
            self::PRETO => 'Preto',
            default => 'Branco',
        };
    }

    public static function lightWash(string $color): bool
    {
        return self::normalize($color) === self::PRETO;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public static function ink(string $color): array
    {
        return match (self::normalize($color)) {
            self::DOURADO => [232, 196, 106],
            self::PRETO => [20, 16, 14],
            default => [255, 255, 255],
        };
    }

    public static function inkHex(string $color): string
    {
        return self::hex(self::ink($color));
    }

    public static function ruleHex(string $color): string
    {
        return self::lightWash($color) ? '#2A241C' : '#E4C27A';
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }
}
