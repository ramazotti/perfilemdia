<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Billing\PaymentWebhook;
use PerfilEmDia\Telegram\SpeechDuration;
use PHPUnit\Framework\TestCase;

final class PaymentWebhookTest extends TestCase
{
    public function testEmptyWebhookSecretIsRejected(): void
    {
        $this->assertFalse(PaymentWebhook::tokenMatches('', ''));
        $this->assertFalse(PaymentWebhook::tokenMatches('', 'algo'));
        $this->assertTrue(PaymentWebhook::tokenMatches('segredo', 'segredo'));
    }

    public function testGenericEventMustProveThePayment(): void
    {
        $this->assertFalse(PaymentWebhook::provesPayment(['external_id' => '10']));
        $this->assertFalse(PaymentWebhook::provesPayment(['status' => 'failed']));
        $this->assertTrue(PaymentWebhook::provesPayment(['status' => 'pago', 'external_id' => '10']));
        $this->assertTrue(PaymentWebhook::provesPayment(['payment' => ['status' => 'approved']]));
    }

    public function testWavDurationIsMeasuredWhenTheMessageOmitsIt(): void
    {
        $this->assertSame(2, SpeechDuration::seconds($this->wavSeconds(2), 'wav'));
        $this->assertSame(200, SpeechDuration::seconds($this->wavSeconds(200), 'wav'));
        $this->assertNull(SpeechDuration::seconds('nao-e-audio', 'wav'));
    }

    private function wavSeconds(int $seconds): string
    {
        $byteRate = 1000;
        $data = str_repeat("\0", $seconds * $byteRate);
        $chunk = 36 + strlen($data);

        return 'RIFF' . pack('V', $chunk) . 'WAVE'
            . 'fmt ' . pack('V', 16)
            . pack('v', 1) . pack('v', 1) . pack('V', $byteRate) . pack('V', $byteRate) . pack('v', 1) . pack('v', 8)
            . 'data' . pack('V', strlen($data)) . $data;
    }
}
