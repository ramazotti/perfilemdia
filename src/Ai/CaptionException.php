<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

use RuntimeException;

final class CaptionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $kind,
    ) {
        parent::__construct($message);
    }
}
