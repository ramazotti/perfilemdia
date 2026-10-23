<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

interface QuotePickerInterface
{
    /**
     * @param array<string, mixed> $profile
     * @return array{quote:string, credit:string}|null
     */
    public function pick(string $idea, array $profile, string $avoid = ''): ?array;
}
