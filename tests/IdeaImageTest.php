<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\IdeaImage;
use PerfilEmDia\Support\HttpPoster;
use PHPUnit\Framework\TestCase;

final class IdeaImageTest extends TestCase
{
    public function testStoryAsksForAVerticalFullScreenFrame(): void
    {
        $http = new IdeaImagePoster();

        (new IdeaImage($http))->create('um cafe na mesa', null, '', '9:16');

        $json = $http->calls[0]['options']['json'];
        $this->assertSame('9:16', $json['aspect_ratio']);
        $this->assertSame('2K', $json['resolution']);
        $this->assertStringContainsString('9:16', $json['prompt']);
        $this->assertArrayNotHasKey('input_references', $json);
    }

    public function testFeedKeepsTheSquareRequest(): void
    {
        $http = new IdeaImagePoster();

        (new IdeaImage($http))->create('um cafe na mesa');

        $json = $http->calls[0]['options']['json'];
        $this->assertArrayNotHasKey('aspect_ratio', $json);
        $this->assertStringContainsString('Instagram photo', $json['prompt']);
    }
}

final class IdeaImagePoster implements HttpPoster
{
    /** @var list<array{method:string, url:string, options:array<string, mixed>}> */
    public array $calls = [];

    public function request(string $method, string $url, array $options = []): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return [
            'status' => 200,
            'body' => ['data' => [['b64_json' => base64_encode("\xFF\xD8\xFF")]]],
        ];
    }
}
