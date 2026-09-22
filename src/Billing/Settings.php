<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PDO;
use Throwable;

final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();

        return $all[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $pdo = \PerfilEmDia\Db::pdo();
            $rows = $pdo->query('SELECT skey, svalue FROM settings')->fetchAll();
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row['skey']] = (string) $row['svalue'];
            }
            self::$cache = $map;
        } catch (Throwable) {
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function set(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        );
        $stmt->execute([$key, $value]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
