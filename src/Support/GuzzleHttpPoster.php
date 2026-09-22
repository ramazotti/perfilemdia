<?php

declare(strict_types=1);

namespace PerfilEmDia\Support;

use GuzzleHttp\Client;

final class GuzzleHttpPoster implements HttpPoster
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout' => 45,
            'http_errors' => false,
        ]);
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $response = $this->client->request($method, $url, $options);
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : $raw,
        ];
    }
}
