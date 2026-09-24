<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PDO;

final class BillingFactory
{
    public static function service(PDO $pdo): CheckoutService
    {
        return new CheckoutService($pdo, self::gateway($pdo));
    }

    public static function gateway(PDO $pdo): PaymentGateway
    {
        if (AppMaxGateway::configured()) {
            return new AppMaxGateway($pdo);
        }
        if (SandboxGateway::enabled()) {
            return new SandboxGateway();
        }

        return new AsaasGateway();
    }
}
