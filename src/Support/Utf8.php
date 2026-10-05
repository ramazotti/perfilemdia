<?php

declare(strict_types=1);

namespace PerfilEmDia\Support;

final class Utf8
{
    public static function clean(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($fixed !== false) {
            return $fixed;
        }

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    public static function cleanDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::clean($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::cleanDeep($item);
        }

        return $value;
    }
}
