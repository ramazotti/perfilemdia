<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhrasePlace
{
    public const TOPO = 'topo';

    public const MEIO = 'meio';

    public const RODAPE = 'rodape';

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, [self::TOPO, self::MEIO, self::RODAPE], true)
            ? $value
            : self::RODAPE;
    }

    public static function label(string $place): string
    {
        return match (self::normalize($place)) {
            self::TOPO => 'Topo',
            self::MEIO => 'Meio',
            default => "Rodap\u{00e9}",
        };
    }

    public static function where(string $place): string
    {
        return match (self::normalize($place)) {
            self::TOPO => 'no topo',
            self::MEIO => 'no meio',
            default => "no rodap\u{00e9}",
        };
    }
}
