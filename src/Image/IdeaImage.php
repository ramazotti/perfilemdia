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

    public static function promptFor(string $idea, bool $hasReference, string $brand, string $aspect): string
    {
        if (self::isInfographic($idea)) {
            return self::infographicPrompt($idea, $hasReference, $brand, $aspect);
        }

        $story = $aspect === '9:16';
        $feedPortrait = $aspect === '4:5';
        $look = trim($brand) !== '' ? ' ' . trim($brand) : '';
        $clean = ' No text, letters, numbers, logos, or watermarks.';
        if ($hasReference) {
            $frame = $story
                ? ' Photorealistic, natural color, shot on a camera, vertical full screen, 9:16.'
                : ($feedPortrait
                    ? ' Photorealistic, natural color, shot on a camera, vertical 4:5.'
                    : ' Photorealistic, natural color, shot on a camera.');

            return 'The attached photo is the real scene. Keep the same people, their ages, faces, clothing, uniforms, objects, and the same place. Keep the same level of care: do not replace the scene with a different school, courtyard, building, poorer, generic, or neglected location. If the idea mentions sky or looking up, show that mood with these same people in this same place, not a new wide shot of another school. You may adjust framing, light, and sky so the feeling matches the idea. Do not add clutter, props, or busy backgrounds that were not there.'
                . $frame . $clean . $look
                . ' The idea describes the feeling, not a new place: ' . $idea;
        }

        $open = $story
            ? 'Create one photorealistic vertical photo, full screen, 9:16, as if shot on a camera in a real, cared-for place.'
            : ($feedPortrait
                ? 'Create one photorealistic vertical photo, 4:5, as if shot on a camera in a real, cared-for place.'
                : 'Create one photorealistic photo, as if shot on a camera in a real, cared-for place.');

        return $open . ' Natural color and real materials. Do not invent a rundown, neglected, or generic stock setting.'
            . ' Follow the tone and composition rules in the profile look below; if they ask for calm or simple, keep the scene minimal.'
            . $clean . $look . ' The idea: ' . $idea;
    }

    private static function infographicPrompt(string $idea, bool $hasReference, string $brand, string $aspect): string
    {
        $story = $aspect === '9:16';
        $frame = $story
            ? 'Full-screen vertical infographic, 9:16.'
            : 'Vertical infographic, 3:4. Leave a clear empty band at the top and at the bottom.';
        $look = trim($brand) !== '' ? ' Follow this visual profile: ' . trim($brand) : '';
        $source = $hasReference
            ? 'The attached photo is only a reference for subject or color. Do not return that photo with a caption bar.'
            : 'Do not make a photograph.';

        return 'Design one clean infographic, not a photo and not a poster with a paragraph over a picture. '
            . $frame . ' ' . $source
            . ' Brazilian Portuguese only, spelled correctly, taken from the request. Do not print the word infográfico unless that word is the title they asked for.'
            . ' Use a short title and at most five short lines. Large type, high contrast, generous margins, and every word fully inside the frame.'
            . ' Flat or editorial layout, few colors, no watermark, no tiny footnotes, no English labels.'
            . $look
            . ' The request: ' . $idea;
    }

    /**
     * @return array{model: ?string, output_format: ?string, aspect_ratio: ?string, resolution: ?string, quality: ?string}
     */
    public static function requestOptions(string $idea, string $aspect): array
    {
        $framed = $aspect === '9:16' || $aspect === '4:5';
        if (!self::isInfographic($idea)) {
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

        $options = self::requestOptions($idea, $aspect);
        $payload = [
            'model' => $options['model'] ?? Config::get('OPENROUTER_IMAGE_MODEL', 'google/gemini-3.1-flash-image'),
            'prompt' => self::promptFor($idea, $hasReference, $brand, $aspect),
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
            'timeout' => self::isInfographic($idea) ? 180 : 120,
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
