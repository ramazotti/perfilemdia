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

    public static function isInfographic(string $text): bool
    {
        return preg_match('/info\s*gr[aá]fic/iu', $text) === 1;
    }

    public static function isDesigned(string $text): bool
    {
        return IdeaPieces::isDesigned($text);
    }

    /**
     * @return list<array{kind: string, index: int, count: int}>
     */
    public static function pieces(string $idea, string $aspect): array
    {
        return IdeaPieces::pieces($idea, $aspect);
    }

    public static function promptFor(string $idea, bool $hasReference, string $brand, string $aspect, int $index = 1): string
    {
        return IdeaPieces::prompt($idea, $hasReference, $brand, $aspect, $index);
    }

    /**
     * @return array{model: ?string, output_format: ?string, aspect_ratio: ?string, resolution: ?string, quality: ?string}
     */
    public static function requestOptions(string $idea, string $aspect): array
    {
        $framed = $aspect === '9:16' || $aspect === '4:5';
        if (!self::isDesigned($idea)) {
            return [
                'model' => null,
                'output_format' => 'jpeg',
                'aspect_ratio' => $framed ? $aspect : null,
                'resolution' => $framed ? '2K' : null,
                'quality' => null,
            ];
        }

        return [
            'model' => 'openai/gpt-image-2',
            'output_format' => null,
            'aspect_ratio' => $aspect === '9:16' ? '9:16' : ($aspect === '4:5' ? '3:4' : null),
            'resolution' => null,
            'quality' => 'medium',
        ];
    }

    /**
     * @return list<string>
     */
    public function createSet(string $idea, ?string $referenceJpeg = null, string $brand = '', string $aspect = ''): array
    {
        $frames = [];
        foreach (self::pieces($idea, $aspect) as $piece) {
            $frames[] = $this->render($idea, $referenceJpeg, $brand, $aspect, $piece['index']);
        }

        return $frames;
    }

    public function create(string $idea, ?string $referenceJpeg = null, string $brand = '', string $aspect = '', int $index = 1): string
    {
        return $this->render($idea, $referenceJpeg, $brand, $aspect, $index);
    }

    private function render(string $idea, ?string $referenceJpeg, string $brand, string $aspect, int $index): string
    {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            throw new ImageEditException('OpenRouter key missing');
        }

        $hasReference = false;
        $referenceUrl = null;
        if ($referenceJpeg !== null && is_file($referenceJpeg)) {
            $referenceUrl = $this->referenceDataUrl($referenceJpeg);
            if ($referenceUrl !== null) {
                $hasReference = true;
            }
        }

        $options = self::requestOptions($idea, $aspect);
        $payload = [
            'model' => $options['model'] ?? Config::get('OPENROUTER_IMAGE_MODEL', 'google/gemini-3.1-flash-image'),
            'prompt' => self::promptFor($idea, $hasReference, $brand, $aspect, $index),
        ];
        if ($options['output_format'] !== null) {
            $payload['output_format'] = $options['output_format'];
        }
        if ($options['aspect_ratio'] !== null) {
            $payload['aspect_ratio'] = $options['aspect_ratio'];
        }
        if ($options['resolution'] !== null) {
            $payload['resolution'] = $options['resolution'];
        }
        if ($options['quality'] !== null) {
            $payload['quality'] = $options['quality'];
        }
        if ($referenceUrl !== null) {
            $payload['input_references'] = [[
                'type' => 'image_url',
                'image_url' => ['url' => $referenceUrl],
            ]];
        }

        $http = $this->http ?? new GuzzleHttpPoster();
        $response = $http->request('POST', self::API_URL, [
            'timeout' => self::isDesigned($idea) ? 180 : 120,
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

    private function referenceDataUrl(string $path): ?string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $mime = 'image/jpeg';
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (is_resource($finfo)) {
            $detected = finfo_buffer($finfo, $bytes);
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                $mime = $detected;
            }
            finfo_close($finfo);
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
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
