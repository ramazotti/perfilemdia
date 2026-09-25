<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\IdeaVideo;
use PerfilEmDia\Support\HttpPoster;
use PHPUnit\Framework\TestCase;

final class IdeaVideoTest extends TestCase
{
    public function testSubmitSendsFifteenSecondsAndAPortraitFrame(): void
    {
        $http = new RecordingPoster([
            ['status' => 202, 'body' => ['id' => 'job-15', 'status' => 'pending']],
        ]);
        $frame = tempnam(sys_get_temp_dir(), 'frm');
        $this->assertNotFalse($frame);
        file_put_contents($frame, "\xFF\xD8\xFF");

        $id = (new IdeaVideo($http))->submit('um kefir na mesa', 15, $frame);

        $this->assertSame('job-15', $id);
        $json = $http->calls[0]['options']['json'];
        $this->assertSame(15, $json['duration']);
        $this->assertSame('9:16', $json['aspect_ratio']);
        $this->assertSame('720p', $json['resolution']);
        $this->assertSame('first_frame', $json['frame_images'][0]['frame_type']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $json['frame_images'][0]['image_url']['url']);
        unlink($frame);
    }

    public function testStatusAndDownloadReadTheFinishedClip(): void
    {
        $clip = "\x00\x00\x00\x18ftypisom" . str_repeat('a', 40);
        $http = new RecordingPoster([
            ['status' => 200, 'body' => [
                'status' => 'completed',
                'unsigned_urls' => ['https://openrouter.ai/api/v1/videos/job-15/content?index=0'],
            ]],
            ['status' => 200, 'body' => $clip],
        ]);
        $video = new IdeaVideo($http);

        $job = $video->status('job-15');
        $bytes = $video->download($job['url'] ?? '');

        $this->assertSame('completed', $job['status']);
        $this->assertSame($clip, $bytes);
        $this->assertStringContainsString('/videos/job-15', $http->calls[0]['url']);
    }
}

final class RecordingPoster implements HttpPoster
{
    /** @var list<array{status:int, body:array<string, mixed>|string}> */
    public array $calls = [];

    /**
     * @param list<array{status:int, body:array<string, mixed>|string}> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $next = array_shift($this->responses);

        return $next ?? ['status' => 500, 'body' => 'empty'];
    }
}
