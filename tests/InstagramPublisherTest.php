<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use InvalidArgumentException;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramPublisher;
use PHPUnit\Framework\TestCase;

final class InstagramPublisherTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['INSTAGRAM_APP_ID'] = 'app-client-id';
        $_ENV['INSTAGRAM_APP_SECRET'] = 'app-secret';
        $_ENV['INSTAGRAM_REDIRECT_URI'] = 'https://example.com/oauth/instagram-callback.php';
        $_ENV['INSTAGRAM_SCOPES'] = 'instagram_business_basic,instagram_business_content_publish';
        $_ENV['INSTAGRAM_GRAPH_VERSION'] = 'v25.0';
        $_SERVER['INSTAGRAM_APP_ID'] = $_ENV['INSTAGRAM_APP_ID'];
        $_SERVER['INSTAGRAM_APP_SECRET'] = $_ENV['INSTAGRAM_APP_SECRET'];
        $_SERVER['INSTAGRAM_REDIRECT_URI'] = $_ENV['INSTAGRAM_REDIRECT_URI'];
        $_SERVER['INSTAGRAM_SCOPES'] = $_ENV['INSTAGRAM_SCOPES'];
        $_SERVER['INSTAGRAM_GRAPH_VERSION'] = $_ENV['INSTAGRAM_GRAPH_VERSION'];
        putenv('INSTAGRAM_APP_ID=app-client-id');
        putenv('INSTAGRAM_APP_SECRET=app-secret');
        putenv('INSTAGRAM_REDIRECT_URI=https://example.com/oauth/instagram-callback.php');
        putenv('INSTAGRAM_SCOPES=instagram_business_basic,instagram_business_content_publish');
        putenv('INSTAGRAM_GRAPH_VERSION=v25.0');
    }

    public function testPublishSingleUrlCallsMediaAndMediaPublish(): void
    {
        $fake = new FakeHttpPoster([
            ['status' => 200, 'body' => ['id' => 'container-1']],
            ['status' => 200, 'body' => ['status_code' => 'FINISHED']],
            ['status' => 200, 'body' => ['id' => 'media-1']],
            ['status' => 200, 'body' => ['permalink' => 'https://www.instagram.com/p/abc/']],
        ]);
        $publisher = new InstagramPublisher(new InstagramClient($fake));

        $result = $publisher->publish(
            'ig-user-1',
            'token-xyz',
            ['https://cdn.example.com/a.jpg'],
            'Legenda',
            'Texto alternativo',
        );

        $this->assertSame('container-1', $result->containerId);
        $this->assertSame('media-1', $result->mediaId);
        $this->assertSame('https://www.instagram.com/p/abc/', $result->permalink);

        $this->assertStringContainsString('/ig-user-1/media', $fake->requests[0]['url']);
        $this->assertSame('https://cdn.example.com/a.jpg', $fake->requests[0]['options']['form_params']['image_url']);
        $this->assertSame('Legenda', $fake->requests[0]['options']['form_params']['caption']);
        $this->assertArrayNotHasKey('is_carousel_item', $fake->requests[0]['options']['form_params']);

        $this->assertStringContainsString('/ig-user-1/media_publish', $fake->requests[2]['url']);
        $this->assertSame('container-1', $fake->requests[2]['options']['form_params']['creation_id']);
    }

    public function testPublishTwoUrlsMarksCarouselItemAndCarouselType(): void
    {
        $fake = new FakeHttpPoster([
            ['status' => 200, 'body' => ['id' => 'child-1']],
            ['status' => 200, 'body' => ['id' => 'child-2']],
            ['status' => 200, 'body' => ['id' => 'carousel-1']],
            ['status' => 200, 'body' => ['status_code' => 'FINISHED']],
            ['status' => 200, 'body' => ['id' => 'media-2']],
            ['status' => 200, 'body' => ['permalink' => 'https://www.instagram.com/p/carousel/']],
        ]);
        $publisher = new InstagramPublisher(new InstagramClient($fake));

        $result = $publisher->publish(
            'ig-user-1',
            'token-xyz',
            ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'],
            'Carrossel',
        );

        $this->assertSame('carousel-1', $result->containerId);
        $this->assertSame('true', $fake->requests[0]['options']['form_params']['is_carousel_item']);
        $this->assertSame('true', $fake->requests[1]['options']['form_params']['is_carousel_item']);
        $this->assertSame('CAROUSEL', $fake->requests[2]['options']['form_params']['media_type']);
        $this->assertSame('child-1,child-2', $fake->requests[2]['options']['form_params']['children']);
        $this->assertSame('Carrossel', $fake->requests[2]['options']['form_params']['caption']);
    }

    public function testPublishWithZeroUrlsThrowsInvalidArgument(): void
    {
        $publisher = new InstagramPublisher(new InstagramClient(new FakeHttpPoster([])));

        $this->expectException(InvalidArgumentException::class);
        $publisher->publish('ig-user-1', 'token', [], 'x');
    }
}
