<?php

declare(strict_types=1);

namespace PerfilEmDia\Instagram;

use RuntimeException;

final class InstagramApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly ?int $subcode = null,
        public readonly bool $retryable = false,
        public readonly string $kind = 'other',
    ) {
        parent::__construct($message);
    }
}
