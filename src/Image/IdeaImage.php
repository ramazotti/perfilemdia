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

    public static function promptFor(string $idea, bool $hasReference, string $brand, string $aspect): string
    {
        $story = $aspect === '9:16';
        $look = trim($brand) !== '' ? ' ' . trim($brand) : '';
        $clean = ' No text, letters, numbers, logos, or watermarks.';
        if ($hasReference) {
            $frame = $story
                ? ' Photorealistic, natural color, shot on a camera, vertical full screen, 9:16.'
                : ' Photorealistic, natural color, shot on a camera.';

            return 'The attached photo is the real scene. Keep the same people, their ages, faces, clothing, uniforms, objects, and the same place. Keep the same level of care: do not replace the scene with a different school, courtyard, building, poorer, generic, or neglected location. If the idea mentions sky or looking up, show that mood with these same people in this same place, not a new wide shot of another school. You may adjust framing, light, and sky so the feeling matches the idea.'
                . $frame . $clean . $look
                . ' The idea describes the feeling, not a new place: ' . $idea;
        }

        $open = $story
            ? 'Create one photorealistic vertical photo, full screen, 9:16, as if shot on a camera in a real, cared-for place.'
            : 'Create one photorealistic photo, as if shot on a camera in a real, cared-for place.';

        return $open . ' Natural color and real materials. Do not invent a rundown, neglected, or generic stock setting.'
            . $clean . $look . ' The idea: ' . $idea;
    }

    public function create(string $idea, ?string $referenceJpeg = null, string $brand = '', string $aspect = ''): string
    {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            throw new ImageEditException('OpenRouter key missing');
        }

        $hasReference = false;
        $referenceUrl = null;
        if ($referenceJpeg !== null && is_file($referenceJpeg)) {
            $bytes = file_get_contents($referenceJpeg);
            if ($bytes !== false && $bytes !== '') {
                $hasReference = true;
                $referenceUrl = 'data:image/jpeg;base64,' . base64_encode($bytes);
            }
        }

        $payload = [
            'model' => Config::get('OPENROUTER_IMAGE_MODEL', 'google/gemini-3.1-flash-image'),
            'prompt' => self::promptFor($idea, $hasReference, $brand, $aspect),
            'output_format' => 'jpeg',
        ];
        if ($aspect === '9:16') {
            $payload['aspect_ratio'] = '9:16';
            $payload['resolution'] = '2K';
        }
        if ($referenceUrl !== null) {
            $payload['input_references'] = [[
                'type' => 'image_url',
                'image_url' => ['url' => $referenceUrl],
            ]];
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
