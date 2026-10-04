<?php

declare(strict_types=1);

namespace PerfilEmDia\Instagram;

use RuntimeException;

final class InstagramAccountLimitException extends RuntimeException
{
    public function __construct(
        public readonly int $maxAccounts,
        public readonly int $userId,
    ) {
        parent::__construct('instagram_account_limit');
    }
}
