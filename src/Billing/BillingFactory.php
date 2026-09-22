<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PDO;

final class BillingFactory
{
    public static function service(PDO $pdo): CheckoutService
    {
        $gateway = SandboxGateway::enabled() ? new SandboxGateway() : new AsaasGateway();

        return new CheckoutService($pdo, $gateway);
    }
}
