<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhraseColor
{
    public const BRANCO = 'branco';

    public const PRETO = 'preto';

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'dourado') {
            return self::BRANCO;
        }

        return $value === self::PRETO ? self::PRETO : self::BRANCO;
    }

    public static function label(string $color): string
    {
        return self::normalize($color) === self::PRETO ? 'Preto' : 'Branco';
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
        return self::normalize($color) === self::PRETO ? [20, 16, 14] : [255, 255, 255];
    }

    public static function inkHex(string $color): string
    {
        return self::hex(self::ink($color));
    }

    public static function ruleHex(string $color): string
    {
        return self::lightWash($color) ? '#FFFFFF' : '#141414';
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }
}
