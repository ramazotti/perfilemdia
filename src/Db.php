<?php

declare(strict_types=1);

namespace PerfilEmDia;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        Config::load();

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST'),
            Config::get('DB_NAME')
        );
        $user = Config::get('DB_USER');
        $pass = Config::get('DB_PASS', '');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            $fallback = Config::get('APP_ENV', 'production') === 'local' && Config::get('DB_HOST') === '127.0.0.1';
            if (!$fallback) {
                throw $e;
            }
            $dsn = sprintf('mysql:host=db;dbname=%s;charset=utf8mb4', Config::get('DB_NAME'));
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        }

        return self::$pdo;
    }
}
