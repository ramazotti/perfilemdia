<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

final class BrazilianDocument
{
    public static function digitsAndLetters(string $value): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $value));
    }

    public static function isValid(string $value): bool
    {
        $clean = self::digitsAndLetters($value);
        if (strlen($clean) === 11) {
            return self::isValidCpf($clean);
        }

        return self::isValidCnpj($clean);
    }

    public static function type(string $value): ?string
    {
        $clean = self::digitsAndLetters($value);
        if (strlen($clean) === 11 && self::isValidCpf($clean)) {
            return 'cpf';
        }
        if (strlen($clean) === 14 && self::isValidCnpj($clean)) {
            return 'cnpj';
        }

        return null;
    }

    public static function canonical(string $value): string
    {
        return self::digitsAndLetters($value);
    }

    public static function isValidCpf(string $digits): bool
    {
        if (!preg_match('/^\d{11}$/', $digits) || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }
        $calc = static function (int $length) use ($digits): int {
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $digits[$i] * ($length + 1 - $i);
            }
            $rest = ($sum * 10) % 11;

            return $rest === 10 ? 0 : $rest;
        };

        return $calc(9) === (int) $digits[9] && $calc(10) === (int) $digits[10];
    }

    public static function isValidCnpj(string $value): bool
    {
        if (!preg_match('/^[0-9A-Z]{12}\d{2}$/', $value) || preg_match('/^(.)\1{13}$/', $value)) {
            return false;
        }
        $dv = static function (int $length) use ($value): int {
            $weights = $length === 12
                ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
                : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (ord($value[$i]) - 48) * $weights[$i];
            }
            $rest = $sum % 11;

            return $rest < 2 ? 0 : 11 - $rest;
        };

        return $dv(12) === (int) $value[12] && $dv(13) === (int) $value[13];
    }
}
