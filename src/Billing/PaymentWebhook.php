<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

final class PaymentWebhook
{
    /**
     * @param array<string, mixed> $json
     */
    public static function provesPayment(array $json): bool
    {
        $status = strtolower(trim((string) ($json['status'] ?? '')));
        if ($status === '' && isset($json['payment']) && is_array($json['payment'])) {
            $status = strtolower(trim((string) ($json['payment']['status'] ?? '')));
        }

        return in_array($status, ['pago', 'paid', 'approved', 'aprovado', 'autorizado', 'authorized', 'integrado'], true);
    }

    public static function tokenMatches(string $expected, string $token): bool
    {
        return $expected !== '' && hash_equals($expected, $token);
    }
}
