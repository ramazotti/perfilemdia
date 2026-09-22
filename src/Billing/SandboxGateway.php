<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PerfilEmDia\Config;

final class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function createPix(string $customerName, string $document, int $amountCents, string $reference): array
    {
        $expires = (new \DateTimeImmutable('now'))->modify('+30 minutes')->format('Y-m-d H:i:s');

        return [
            'external_id' => 'sandbox_pix_' . $reference,
            'payload' => '00020126580014BR.GOV.BCB.PIX0136sandbox-' . $reference . '520400005303986540' . $amountCents,
            'expires_at' => $expires,
        ];
    }

    public function chargeCard(string $token, int $amountCents, string $reference): array
    {
        if (str_ends_with($token, '0002')) {
            throw new PaymentRefused('O banco recusou este cartão.');
        }

        return [
            'external_id' => 'sandbox_card_' . $reference,
            'status' => 'pago',
        ];
    }

    public static function enabled(): bool
    {
        $gateway = strtolower(Config::get('PAYMENT_GATEWAY', 'sandbox'));
        $key = Config::get('PAYMENT_API_KEY', '');

        return $gateway === 'sandbox' || $key === '';
    }
}
