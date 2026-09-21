<?php

declare(strict_types=1);

namespace PerfilEmDia;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;

final class Logger
{
    private static ?MonologLogger $logger = null;

    public static function get(): MonologLogger
    {
        if (self::$logger instanceof MonologLogger) {
            return self::$logger;
        }

        Config::load();

        $dir = Config::root() . '/storage/logs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar storage/logs.');
        }

        $logger = new MonologLogger('perfilemdia');
        $logger->pushHandler(new RotatingFileHandler($dir . '/app.log', 14, Level::Debug));
        self::$logger = $logger;

        return self::$logger;
    }
}
