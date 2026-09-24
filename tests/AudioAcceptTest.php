<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Ai\CaptionGeneratorInterface;
use PerfilEmDia\Ai\CaptionResult;
use PerfilEmDia\Ai\OpenRouterSpeechTranscriber;
use PerfilEmDia\Ai\SpeechTranscriber;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\OnboardingService;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\PostStatus;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Instagram\PublishedMedia;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Support\HttpPoster;
use PerfilEmDia\Telegram\SpeechMessage;
use PerfilEmDia\Telegram\UpdateHandler;
use PHPUnit\Framework\TestCase;

final class AudioAcceptTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testVoiceNoteBecomesThePhotoTheme(): void
    {
        $speech = new FixedSpeech('estoque novo da semana');
        [$handler, $channel, $posts, $userId] = $this->handler($speech);
        $postId = $posts->create($userId, PostStatus::AwaitingTheme, null);

        $handler->handle([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => 930441, 'username' => 'ana'],
                'chat' => ['id' => 930441],
                'voice' => [
                    'file_id' => 'voice-1',
                    'duration' => 8,
                    'mime_type' => 'audio/ogg',
                    'file_size' => 1200,
                ],
            ],
        ]);

        $this->assertSame(1, $speech->calls);
        $this->assertSame('estoque novo da semana', $posts->find($postId)['theme_text']);
        $heard = implode("\n", array_column($channel->sent, 'text'));
        $this->assertStringContainsString('Ouvi: estoque novo da semana', $heard);
    }

    public function testLongVoiceIsRefusedBeforeTranscription(): void
    {
        $speech = new FixedSpeech('nao deve ouvir');
        [$handler, $channel] = $this->handler($speech);

        $handler->handle([
            'update_id' => 2,
            'message' => [
                'message_id' => 11,
                'from' => ['id' => 930441, 'username' => 'ana'],
                'chat' => ['id' => 930441],
                'voice' => [
                    'file_id' => 'voice-2',
                    'duration' => 400,
                    'mime_type' => 'audio/ogg',
                ],
            ],
        ]);

        $this->assertSame(0, $speech->calls);
        $heard = implode("\n", array_column($channel->sent, 'text'));
        $this->assertStringContainsString('3 minutos', $heard);
    }

    public function testAudioDocumentWithoutDurationIsMeasured(): void
    {
        $speech = new FixedSpeech('frase curta');
        [$handler, $channel] = $this->handler($speech);
        $channel->downloadBytes = $this->wavSeconds(200);

        $handler->handle([
            'update_id' => 3,
            'message' => [
                'message_id' => 12,
                'from' => ['id' => 930441, 'username' => 'ana'],
                'chat' => ['id' => 930441],
                'document' => [
                    'file_id' => 'doc-audio',
                    'mime_type' => 'audio/wav',
                    'file_name' => 'fala.wav',
                    'file_size' => 200000,
                ],
            ],
        ]);

        $this->assertSame(0, $speech->calls);
        $heard = implode("\n", array_column($channel->sent, 'text'));
        $this->assertStringContainsString('3 minutos', $heard);
    }

    public function testSpeechMessageReadsVoiceAudioAndAudioFiles(): void
    {
        $voice = SpeechMessage::from([
            'voice' => ['file_id' => 'v', 'duration' => 3, 'mime_type' => 'audio/ogg'],
        ]);
        $this->assertNotNull($voice);
        $this->assertSame('ogg', $voice['format']);

        $audio = SpeechMessage::from([
            'audio' => ['file_id' => 'a', 'duration' => 12, 'mime_type' => 'audio/mpeg', 'file_size' => 40],
        ]);
        $this->assertNotNull($audio);
        $this->assertSame('mp3', $audio['format']);

        $file = SpeechMessage::from([
            'document' => ['file_id' => 'd', 'mime_type' => 'audio/mp4', 'file_name' => 'fala.m4a'],
        ]);
        $this->assertNotNull($file);
        $this->assertSame('m4a', $file['format']);
        $this->assertNull(SpeechMessage::from(['photo' => [['file_id' => 'p']]]));
        $this->assertTrue(SpeechMessage::tooLong(['file_id' => 'v', 'format' => 'ogg', 'duration' => 181, 'bytes' => 10]));
    }

    public function testTranscriberUsesTheFallbackModel(): void
    {
        $http = new SequencePoster([
            ['status' => 503, 'body' => ['error' => ['message' => 'busy']]],
            ['status' => 200, 'body' => ['text' => 'mais luz na vitrine']],
        ]);
        $text = (new OpenRouterSpeechTranscriber($http))->transcribe('abc', 'ogg');

        $this->assertSame('mais luz na vitrine', $text);
        $this->assertSame('openai/whisper-1', $http->models[0]);
        $this->assertSame('openai/whisper-large-v3', $http->models[1]);
        $this->assertSame('ogg', $http->formats[0]);
    }

    /**
     * @return array{0:UpdateHandler,1:AudioTestChannel,2:PostRepository,3:int}
     */
    private function handler(SpeechTranscriber $speech): array
    {
        $users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $posts = new PostRepository($this->pdo);
        $channel = new AudioTestChannel();
        $userId = $users->create(930441, 930441, 'ana');
        $users->update($userId, ['display_name' => 'Ana', 'onboarding_step' => 'done']);
        $postsService = new PostService(
            $users,
            $posts,
            $channel,
            new EmptyNormalizer(),
            new class implements CaptionGeneratorInterface {
                public function generate(array $profile, string $theme, array $jpegPaths, ?string $previousCaption = null, ?string $feedback = null): CaptionResult
                {
                    return new CaptionResult('legenda', [], 'alt', 'm', 'm', 1, 1);
                }
            },
            new class implements InstagramPublisherInterface {
                public function publish(string $igUserId, string $accessToken, array $imageUrls, string $caption, ?string $altText = null, ?string $containerId = null, ?string $mediaId = null, ?callable $checkpoint = null): PublishedMedia
                {
                    return new PublishedMedia('c', 'm', 'https://example.com/p');
                }

                public function publishReel(string $igUserId, string $accessToken, string $videoUrl, string $caption, ?string $containerId = null, ?string $mediaId = null, ?callable $checkpoint = null): PublishedMedia
                {
                    return new PublishedMedia('c', 'm', 'https://example.com/p');
                }

                public function publishStory(string $igUserId, string $accessToken, string $mediaUrl, bool $video, ?string $containerId = null, ?string $mediaId = null, ?callable $checkpoint = null): PublishedMedia
                {
                    return new PublishedMedia('c', 'm', 'https://example.com/p');
                }
            },
        );
        $handler = new UpdateHandler(
            $users,
            $posts,
            $channel,
            new OnboardingService($users, $channel),
            $postsService,
            null,
            null,
            $speech,
        );

        return [$handler, $channel, $posts, $userId];
    }

    private function wavSeconds(int $seconds): string
    {
        $byteRate = 1000;
        $data = str_repeat("\0", $seconds * $byteRate);
        $chunk = 36 + strlen($data);

        return 'RIFF' . pack('V', $chunk) . 'WAVE'
            . 'fmt ' . pack('V', 16)
            . pack('v', 1) . pack('v', 1) . pack('V', $byteRate) . pack('V', $byteRate) . pack('v', 1) . pack('v', 8)
            . 'data' . pack('V', strlen($data)) . $data;
    }
}

final class FixedSpeech implements SpeechTranscriber
{
    public int $calls = 0;

    public function __construct(private readonly string $text)
    {
    }

    public function transcribe(string $bytes, string $format): string
    {
        $this->calls++;

        return $this->text;
    }
}

final class AudioTestChannel implements \PerfilEmDia\Channel\ChannelInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function sendText(int $chatId, string $text, ?array $buttons = null): int
    {
        $this->sent[] = ['text' => $text];

        return count($this->sent);
    }

    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int
    {
        $this->sent[] = ['text' => (string) $caption];

        return count($this->sent);
    }

    public function sendVideo(int $chatId, string $videoPath, ?string $caption, ?array $buttons = null): int
    {
        return 1;
    }

    public function sendAlbum(int $chatId, array $photoPaths): void
    {
    }

    public function editText(int $chatId, int $messageId, string $text, ?array $buttons = null): void
    {
    }

    public function editButtons(int $chatId, int $messageId, ?array $buttons): void
    {
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
    }

    public string $downloadBytes = 'ogg-bytes';

    public function download(string $fileId, string $destPath): void
    {
        file_put_contents($destPath, $this->downloadBytes);
    }
}

final class EmptyNormalizer implements ImageNormalizerInterface
{
    public function normalize(array $sourcePaths, string $publicDirectory): array
    {
        return [];
    }
}

final class SequencePoster implements HttpPoster
{
    /** @var list<string> */
    public array $models = [];

    /** @var list<string> */
    public array $formats = [];

    /**
     * @param list<array{status:int, body:array<string, mixed>|string}> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $json = $options['json'] ?? [];
        $this->models[] = is_array($json) ? (string) ($json['model'] ?? '') : '';
        $audio = is_array($json) ? ($json['input_audio'] ?? []) : [];
        $this->formats[] = is_array($audio) ? (string) ($audio['format'] ?? '') : '';
        $next = array_shift($this->responses);

        return $next ?? ['status' => 500, 'body' => ['error' => 'empty']];
    }
}
