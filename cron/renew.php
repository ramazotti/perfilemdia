<?php

declare(strict_types=1);

use PerfilEmDia\Billing\BillingFactory;
use PerfilEmDia\Config;
use PerfilEmDia\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

Config::load();

$renewed = BillingFactory::service(Db::pdo())->renewDue();
echo "Renovações: {$renewed}.\n";
