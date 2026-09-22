<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

interface PaymentGateway
{
    public function name(): string;

    /**
     * @return array{external_id:string,payload:string,expires_at:string}
     */
    public function createPix(string $customerName, string $document, int $amountCents, string $reference): array;

    /**
     * @return array{external_id:string,status:string}
     */
    public function chargeCard(string $token, int $amountCents, string $reference): array;
}
