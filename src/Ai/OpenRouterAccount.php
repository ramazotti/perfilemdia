<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;
use Throwable;

final class OpenRouterAccount
{
    private const KEY_URL = 'https://openrouter.ai/api/v1/key';

    private HttpPoster $http;

    public function __construct(?HttpPoster $http = null)
    {
        $this->http = $http ?? new GuzzleHttpPoster();
    }

    /**
     * @return array{usage:float,daily:float,weekly:float,monthly:float,limit:?float,remaining:?float,reset:?string}|null
     */
    public function spend(): ?array
    {
        Config::load();
        $key = Config::get('OPENROUTER_API_KEY', '');
        if ($key === '') {
            return null;
        }
        try {
            $response = $this->http->request('GET', self::KEY_URL, [
                'headers' => ['Authorization' => 'Bearer ' . $key],
                'timeout' => 8,
            ]);
        } catch (Throwable) {
            return null;
        }
        if ($response['status'] !== 200 || !is_array($response['body'])) {
            return null;
        }
        $data = $response['body']['data'] ?? null;

        return is_array($data) ? self::parse($data) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{usage:float,daily:float,weekly:float,monthly:float,limit:?float,remaining:?float,reset:?string}|null
     */
    public static function parse(array $data): ?array
    {
        if (!isset($data['usage']) && !isset($data['usage_monthly'])) {
            return null;
        }
        $limit = $data['limit'] ?? null;
        $remaining = $data['limit_remaining'] ?? null;
        $reset = $data['limit_reset'] ?? null;

        return [
            'usage' => (float) ($data['usage'] ?? 0),
            'daily' => (float) ($data['usage_daily'] ?? 0),
            'weekly' => (float) ($data['usage_weekly'] ?? 0),
            'monthly' => (float) ($data['usage_monthly'] ?? 0),
            'limit' => is_numeric($limit) ? (float) $limit : null,
            'remaining' => is_numeric($remaining) ? (float) $remaining : null,
            'reset' => is_string($reset) && $reset !== '' ? $reset : null,
        ];
    }
}
