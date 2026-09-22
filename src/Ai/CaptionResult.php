<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

final class CaptionResult
{
    /**
     * @param list<string> $hashtags
     */
    public function __construct(
        public readonly string $legenda,
        public readonly array $hashtags,
        public readonly string $altText,
        public readonly string $caption,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {
    }
}
