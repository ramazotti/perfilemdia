<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;

final class IdeaVideo implements IdeaVideoGenerator
{
    private const API_URL = 'https://openrouter.ai/api/v1/videos';

    public function __construct(private readonly ?HttpPoster $http = null)
    {
    }

    public function submit(string $prompt, int $seconds, ?string $referencePath = null): string
    {
        $seconds = max(1, min(15, $seconds));
        $payload = [
            'model' => Config::get('OPENROUTER_VIDEO_MODEL', 'bytedance/seedance-2.0-mini'),
            'prompt' => $prompt,
            'duration' => $seconds,
            'resolution' => '720p',
            'aspect_ratio' => '9:16',
        ];
        $frame = $this->frameImage($referencePath);
        if ($frame !== null) {
            $payload['frame_images'] = [$frame];
        }

        $body = $this->json('POST', self::API_URL, $payload, 60);
        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '') {
            throw new ImageEditException('Video API returned no job');
        }

        return $id;
    }

    public function status(string $jobId): array
    {
        $body = $this->json('GET', self::API_URL . '/' . rawurlencode($jobId), null, 30);
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        $url = null;
        $urls = $body['unsigned_urls'] ?? null;
        if (is_array($urls) && isset($urls[0]) && is_string($urls[0]) && $urls[0] !== '') {
            $url = $urls[0];
        }
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            $error = $error['message'] ?? null;
        }

        return [
            'status' => $status,
            'url' => $url,
            'error' => is_string($error) && $error !== '' ? $error : null,
        ];
    }

    public function download(string $url): string
    {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            throw new ImageEditException('OpenRouter key missing');
        }
        $http = $this->http ?? new GuzzleHttpPoster();
        $response = $http->request('GET', $url, [
            'timeout' => 180,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'HTTP-Referer' => Config::get('APP_URL', 'https://perfilemdia.com.br'),
                'X-Title' => Config::get('OPENROUTER_APP_TITLE', 'Perfil em Dia'),
            ],
        ]);
        $status = (int) $response['status'];
        $body = $response['body'];
        if ($status < 200 || $status >= 300 || !is_string($body) || !str_contains($body, 'ftyp')) {
            throw new ImageEditException('Video API returned no video');
        }

        return $body;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, ?array $payload, int $timeout): array
    {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            throw new ImageEditException('OpenRouter key missing');
        }
        $http = $this->http ?? new GuzzleHttpPoster();
        $options = [
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'HTTP-Referer' => Config::get('APP_URL', 'https://perfilemdia.com.br'),
                'X-Title' => Config::get('OPENROUTER_APP_TITLE', 'Perfil em Dia'),
                'Content-Type' => 'application/json',
            ],
        ];
        if ($payload !== null) {
            $options['json'] = $payload;
        }
        $response = $http->request($method, $url, $options);
        $status = (int) $response['status'];
        $body = $response['body'];
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new ImageEditException('Video API HTTP ' . $status);
        }

        return $body;
    }

    /**
     * @return array{type:string, image_url:array{url:string}, frame_type:string}|null
     */
    private function frameImage(?string $path): ?array
    {
        if ($path === null || !is_file($path)) {
            return null;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $mime = str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg';

        return [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)],
            'frame_type' => 'first_frame',
        ];
    }
}
