<?php

declare(strict_types=1);

namespace PerfilEmDia\Security;

use InvalidArgumentException;
use PerfilEmDia\Config;
use RuntimeException;

final class Crypto
{
    public function __construct(private readonly string $key)
    {
        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException('A chave de criptografia deve ter 32 bytes.');
        }
    }

    public static function fromConfig(): self
    {
        Config::load();
        $decoded = base64_decode(Config::get('APP_ENCRYPTION_KEY'), true);
        if ($decoded === false) {
            throw new RuntimeException('APP_ENCRYPTION_KEY não é base64 válido.');
        }

        return new self($decoded);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($decoded === false || strlen($decoded) < $minLength) {
            throw new RuntimeException('Payload cifrado inválido.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Falha ao decifrar.');
        }

        return $plain;
    }
}
