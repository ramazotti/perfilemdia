<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

final class Card
{
    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    public static function luhn(string $digits): bool
    {
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }

        return $sum % 10 === 0;
    }

    public static function brand(string $digits): string
    {
        if (str_starts_with($digits, '4')) {
            return 'visa';
        }
        if (preg_match('/^3[47]/', $digits)) {
            return 'amex';
        }
        if (preg_match('/^(5[1-5]|2[2-7])/', $digits)) {
            return 'mastercard';
        }
        if (preg_match('/^(636368|438935|504175|451416|5090|5067|4576|4011)/', $digits)) {
            return 'elo';
        }

        return 'cartao';
    }
}
