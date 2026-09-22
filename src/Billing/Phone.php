<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

final class Phone
{
    public static function normalize(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        if (str_starts_with($digits, '55') && (strlen($digits) === 12 || strlen($digits) === 13)) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) !== 11 || ($digits[2] ?? '') !== '9') {
            return null;
        }

        return $digits;
    }

    public static function format(string $digits): string
    {
        if (strlen($digits) !== 11) {
            return $digits;
        }

        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
    }
}
