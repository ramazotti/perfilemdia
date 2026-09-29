<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;

final class OpenRouterImageEditor implements ImageEditorInterface
{
    private const API_URL = 'https://openrouter.ai/api/v1/images';

    public function __construct(private readonly ?HttpPoster $http = null)
    {
    }

    public function edit(string $jpegPath, string $instruction, string $aspect = ''): string
    {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            throw new ImageEditException('OpenRouter key missing');
        }
        $bytes = file_get_contents($jpegPath);
        if ($bytes === false || $bytes === '') {
            throw new ImageEditException('Cannot read source photo');
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
            'json' => $this->editPayload($instruction, $bytes, $aspect),
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

    /**
     * @return array<string, mixed>
     */
    private function editPayload(string $instruction, string $bytes, string $aspect): array
    {
        $story = $aspect === '9:16';
        $portrait = $aspect === '4:5';
        $prompt = $story
            ? 'Edit this vertical full-screen photo and keep the 9:16 frame. Do not add words, letters, numbers, logos, or watermarks. Keep the same people, objects, and place. Apply only this change: ' . $instruction
            : ($portrait
                ? 'Edit this vertical photo and keep the 4:5 frame. Do not add words, letters, numbers, logos, or watermarks. Keep the same people, objects, and place. Apply only this change: ' . $instruction
                : 'Edit this photo. Do not add words, letters, numbers, logos, or watermarks. Keep the same people, objects, and place. Apply only this change: ' . $instruction);
        $payload = [
            'model' => Config::get('OPENROUTER_IMAGE_MODEL', 'google/gemini-3.1-flash-image'),
            'prompt' => $prompt,
            'input_references' => [[
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($bytes)],
            ]],
            'output_format' => 'jpeg',
        ];
        if ($story || $portrait) {
            $payload['aspect_ratio'] = $aspect;
            $payload['resolution'] = '2K';
        }

        return $payload;
    }

    private function toJpeg(string $binary): string
    {
        if (str_starts_with($binary, "\xFF\xD8")) {
            return $binary;
        }
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            $image = new \Imagick();
            $image->readImageBlob($binary);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(90);

            return $image->getImageBlob();
        }
        $gd = @imagecreatefromstring($binary);
        if ($gd === false) {
            throw new ImageEditException('Edited image is not a picture');
        }
        ob_start();
        imagejpeg($gd, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($gd);
        if (!is_string($jpeg) || $jpeg === '') {
            throw new ImageEditException('Could not convert edited image');
        }

        return $jpeg;
    }
}
