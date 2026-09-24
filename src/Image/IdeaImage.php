<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;

final class IdeaImage implements IdeaImageGenerator
{
    private const API_URL = 'https://openrouter.ai/api/v1/images';

    public function __construct(private readonly ?HttpPoster $http = null)
    {
    }

    public function create(string $idea, ?string $referenceJpeg = null, string $brand = ''): string
    {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            throw new ImageEditException('OpenRouter key missing');
        }

        $look = trim($brand) !== '' ? ' ' . trim($brand) : '';
        $prompt = 'Create one realistic Instagram photo. No text, letters, numbers, logos, or watermarks.' . $look . ' The idea: ' . $idea;
        $payload = [
            'model' => Config::get('OPENROUTER_IMAGE_MODEL', 'google/gemini-3.1-flash-image'),
            'prompt' => $prompt,
            'output_format' => 'jpeg',
        ];
        if ($referenceJpeg !== null && is_file($referenceJpeg)) {
            $bytes = file_get_contents($referenceJpeg);
            if ($bytes !== false && $bytes !== '') {
                $payload['prompt'] = 'Use the photo only as a reference. Create a new image. No text, letters, numbers, logos, or watermarks.' . $look . ' The idea: ' . $idea;
                $payload['input_references'] = [[
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($bytes)],
                ]];
            }
        }

        $http = $this->http ?? new GuzzleHttpPoster();
        $response = $http->request('POST', self::API_URL, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'HTTP-Referer' => Config::get('APP_URL', 'https://perfilemdia.com.br'),
                'X-Title' => Config::get('OPENROUTER_APP_TITLE', 'Perfil em Dia'),
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $status = (int) $response['status'];
        $body = $response['body'];
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new ImageEditException('Image API HTTP ' . $status);
        }
        $b64 = (string) ($body['data'][0]['b64_json'] ?? '');
        $binary = base64_decode($b64, true);
        if ($binary === false || $binary === '') {
            throw new ImageEditException('Image API returned no image');
        }

        return $this->toJpeg($binary);
    }

    private function toJpeg(string $binary): string
    {
        if (str_starts_with($binary, "\xFF\xD8")) {
            return $binary;
        }
        $gd = @imagecreatefromstring($binary);
        if ($gd === false) {
            throw new ImageEditException('Created image is not a picture');
        }
        ob_start();
        imagejpeg($gd, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($gd);
        if (!is_string($jpeg) || $jpeg === '') {
            throw new ImageEditException('Could not convert created image');
        }

        return $jpeg;
    }
}
