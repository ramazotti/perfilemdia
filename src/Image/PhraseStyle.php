<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhraseStyle
{
    public const CURSIVA = 'cursiva';

    public const CLASSICA = 'classica';

    public const LIMPA = 'limpa';

    public const FORTE = 'forte';

    public const BALAO = 'balao';

    public const CAIXA = 'caixa';

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, [self::CURSIVA, self::CLASSICA, self::LIMPA, self::FORTE, self::BALAO, self::CAIXA], true)
            ? $value
            : self::CLASSICA;
    }

    public static function label(string $style): string
    {
        return match (self::normalize($style)) {
            self::CURSIVA => 'Cursiva',
            self::LIMPA => 'Limpa',
            self::FORTE => 'Forte',
            self::BALAO => "Bal\u{00e3}o",
            self::CAIXA => 'Caixa',
            default => "Cl\u{00e1}ssica",
        };
    }

    public static function pairedColor(string $style): ?string
    {
        return match (self::normalize($style)) {
            self::BALAO => PhraseColor::PRETO,
            self::CAIXA => PhraseColor::BRANCO,
            default => null,
        };
    }
}
