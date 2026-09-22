<?php

declare(strict_types=1);

namespace PerfilEmDia\Support;

interface HttpPoster
{
    /**
     * @param array<string, mixed> $options opções no formato do Guzzle (headers, json, form_params, query, body, timeout)
     * @return array{status:int, body:array<string, mixed>|string}
     */
    public function request(string $method, string $url, array $options = []): array;
}
