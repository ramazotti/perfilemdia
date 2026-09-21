<?php

declare(strict_types=1);

namespace PerfilEmDia;

use Dotenv\Dotenv;
use RuntimeException;

final class Config
{
    private static bool $loaded = false;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function load(?string $root = null): void
    {
        if (self::$loaded) {
            return;
        }

        $root ??= self::root();
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }

        date_default_timezone_set(self::get('APP_TIMEZONE', 'America/Sao_Paulo'));
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            if ($default !== null) {
                return $default;
            }
            throw new RuntimeException('Config ausente: ' . $key);
        }

        return (string) $value;
    }
}
