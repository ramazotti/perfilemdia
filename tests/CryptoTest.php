<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use InvalidArgumentException;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoTest extends TestCase
{
    public function testEncryptAndDecryptRoundTrip(): void
    {
        $crypto = new Crypto(sodium_crypto_secretbox_keygen());
        $plain = 'token-instagram-exemplo';

        $stored = $crypto->encrypt($plain);

        $this->assertNotSame($plain, $stored);
        $this->assertSame($plain, $crypto->decrypt($stored));
    }

    public function testSamePlaintextProducesDifferentPayloads(): void
    {
        $crypto = new Crypto(sodium_crypto_secretbox_keygen());

        $this->assertNotSame($crypto->encrypt('mesmo'), $crypto->encrypt('mesmo'));
    }

    public function testDecryptRejectsTamperedPayload(): void
    {
        $crypto = new Crypto(sodium_crypto_secretbox_keygen());
        $stored = $crypto->encrypt('segredo');
        $tampered = substr($stored, 0, -2) . 'aa';

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($tampered);
    }

    public function testRejectsKeyWithWrongLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Crypto('curta');
    }
}
