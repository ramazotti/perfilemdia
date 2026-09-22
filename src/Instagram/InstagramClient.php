<?php

declare(strict_types=1);

namespace PerfilEmDia\Instagram;

use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;
use RuntimeException;

final class InstagramClient
{
    private HttpPoster $http;
    private string $version;
    private string $appId;
    private string $appSecret;
    private string $redirectUri;

    public function __construct(?HttpPoster $http = null)
    {
        Config::load();
        $this->http = $http ?? new GuzzleHttpPoster();
        $this->version = Config::get('INSTAGRAM_GRAPH_VERSION', 'v25.0');
        $this->appId = Config::get('INSTAGRAM_APP_ID');
        $this->appSecret = Config::get('INSTAGRAM_APP_SECRET');
        $this->redirectUri = Config::get('INSTAGRAM_REDIRECT_URI');
    }

    /**
     * @return array{access_token:string, user_id:string}
     */
    public function exchangeCode(string $code): array
    {
        if (str_ends_with($code, '#_')) {
            $code = substr($code, 0, -2);
        }

        $response = $this->http->request('POST', 'https://api.instagram.com/oauth/access_token', [
            'form_params' => [
                'client_id' => $this->appId,
                'client_secret' => $this->appSecret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri,
                'code' => $code,
            ],
        ]);

        $body = $this->assertOk($response);

        return [
            'access_token' => (string) ($body['access_token'] ?? ''),
            'user_id' => (string) ($body['user_id'] ?? ''),
        ];
    }

    /**
     * @return array{access_token:string, expires_in:int}
     */
    public function exchangeLongLived(string $shortToken): array
    {
        $response = $this->http->request('GET', 'https://graph.instagram.com/access_token', [
            'query' => [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->appSecret,
                'access_token' => $shortToken,
            ],
        ]);

        $body = $this->assertOk($response);

        return [
            'access_token' => (string) ($body['access_token'] ?? ''),
            'expires_in' => (int) ($body['expires_in'] ?? 0),
        ];
    }

    /**
     * @return array{user_id:string, username:string, account_type:?string}
     */
    public function me(string $token): array
    {
        $response = $this->http->request(
            'GET',
            'https://graph.instagram.com/' . $this->version . '/me',
            [
                'query' => [
                    'fields' => 'user_id,username,account_type',
                    'access_token' => $token,
                ],
            ]
        );

        $body = $this->assertOk($response);

        return [
            'user_id' => (string) ($body['user_id'] ?? ''),
            'username' => (string) ($body['username'] ?? ''),
            'account_type' => isset($body['account_type']) ? (string) $body['account_type'] : null,
        ];
    }

    /**
     * @return array{access_token:string, expires_in:int}
     */
    public function refreshLongLivedToken(string $token): array
    {
        $response = $this->http->request('GET', 'https://graph.instagram.com/refresh_access_token', [
            'query' => [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $token,
            ],
        ]);

        $body = $this->assertOk($response);

        return [
            'access_token' => (string) ($body['access_token'] ?? ''),
            'expires_in' => (int) ($body['expires_in'] ?? 0),
        ];
    }

    public function createImageContainer(
        string $igUserId,
        string $token,
        string $imageUrl,
        ?string $caption,
        ?string $altText,
        bool $isCarouselItem,
    ): string {
        $params = [
            'image_url' => $imageUrl,
            'access_token' => $token,
        ];
        if ($caption !== null) {
            $params['caption'] = $caption;
        }
        if ($altText !== null) {
            $params['alt_text'] = $altText;
        }
        if ($isCarouselItem) {
            $params['is_carousel_item'] = 'true';
        }

        $response = $this->http->request(
            'POST',
            'https://graph.instagram.com/' . $this->version . '/' . $igUserId . '/media',
            ['form_params' => $params]
        );

        $body = $this->assertOk($response);

        return (string) ($body['id'] ?? '');
    }

    /**
     * @param list<string> $childrenIds
     */
    public function createCarouselContainer(
        string $igUserId,
        string $token,
        array $childrenIds,
        string $caption,
    ): string {
        $response = $this->http->request(
            'POST',
            'https://graph.instagram.com/' . $this->version . '/' . $igUserId . '/media',
            [
                'form_params' => [
                    'media_type' => 'CAROUSEL',
                    'children' => implode(',', $childrenIds),
                    'caption' => $caption,
                    'access_token' => $token,
                ],
            ]
        );

        $body = $this->assertOk($response);

        return (string) ($body['id'] ?? '');
    }

    public function containerStatus(string $containerId, string $token): string
    {
        $response = $this->http->request(
            'GET',
            'https://graph.instagram.com/' . $this->version . '/' . $containerId,
            [
                'query' => [
                    'fields' => 'status_code',
                    'access_token' => $token,
                ],
            ]
        );

        $body = $this->assertOk($response);

        return (string) ($body['status_code'] ?? '');
    }

    public function publishContainer(string $igUserId, string $token, string $creationId): string
    {
        $response = $this->http->request(
            'POST',
            'https://graph.instagram.com/' . $this->version . '/' . $igUserId . '/media_publish',
            [
                'form_params' => [
                    'creation_id' => $creationId,
                    'access_token' => $token,
                ],
            ]
        );

        $body = $this->assertOk($response);

        return (string) ($body['id'] ?? '');
    }

    public function permalink(string $mediaId, string $token): string
    {
        $response = $this->http->request(
            'GET',
            'https://graph.instagram.com/' . $this->version . '/' . $mediaId,
            [
                'query' => [
                    'fields' => 'permalink',
                    'access_token' => $token,
                ],
            ]
        );

        $body = $this->assertOk($response);

        return (string) ($body['permalink'] ?? '');
    }

    /**
     * @param array{status:int, body:array<string, mixed>|string} $response
     * @return array<string, mixed>
     */
    private function assertOk(array $response): array
    {
        $status = $response['status'];
        $body = $response['body'];

        if ($status === 429 || $status >= 500) {
            throw new InstagramApiException(
                'Instagram API network error (HTTP ' . $status . ')',
                null,
                null,
                true,
                'network',
            );
        }

        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $error = $body['error'];
            $code = isset($error['code']) ? (int) $error['code'] : null;
            $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
            $message = (string) ($error['message'] ?? 'Instagram API error');
            [$kind, $retryable] = $this->classifyError($code, $subcode);

            throw new InstagramApiException($message, $code, $subcode, $retryable, $kind);
        }

        if ($status < 200 || $status >= 300) {
            throw new InstagramApiException(
                'Instagram API HTTP ' . $status,
                null,
                null,
                false,
                'other',
            );
        }

        if (!is_array($body)) {
            throw new RuntimeException('Instagram API returned a non-JSON body');
        }

        return $body;
    }

    /**
     * @return array{0:string, 1:bool}
     */
    private function classifyError(?int $code, ?int $subcode): array
    {
        if ($code === 190) {
            return ['token', false];
        }
        if ($code === 9 || $subcode === 2207042) {
            return ['rate_limit', true];
        }
        if ($code === 2207009 || $code === 2207005 || $subcode === 2207009 || $subcode === 2207005) {
            return ['media_invalid', false];
        }
        if ($code === 2207027 || $subcode === 2207027) {
            return ['not_ready', true];
        }

        return ['other', false];
    }
}
