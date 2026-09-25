<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Ai\CaptionGeneratorInterface;
use PerfilEmDia\Ai\CaptionResult;
use PerfilEmDia\Billing\PlanAccess;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\PostStatus;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Image\IdeaVideoGenerator;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Instagram\PublishedMedia;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class AiVideoFlowTest extends TestCase
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

    public function testTheButtonStaysHiddenUntilTheAdminTurnsItOn(): void
    {
        [$service, $channel, $users, $user] = $this->service(new QuietVideo());
        $service->choosePostKind($user, 940001, 'aivideo');
        $this->assertStringContainsString('nesta conta', implode("\n", array_column($channel->sent, 'text')));

        $this->pdo->prepare('UPDATE customers SET ai_video = 1 WHERE user_id = ?')->execute([(int) $user['id']]);
        $service->askPostKind(940001, $users->find((int) $user['id']));
        $labels = [];
        foreach ($channel->sent as $row) {
            foreach ($row['buttons'] ?? [] as $line) {
                foreach ($line as $button) {
                    $labels[] = (string) ($button['text'] ?? '');
                }
            }
        }
        $joined = implode("\n", $labels);
        $this->assertStringContainsString('com IA', $joined);
    }

    public function testAnIdeaBecomesAReelAfterTheJobFinishes(): void
    {
        $video = new QuietVideo("\x00\x00\x00\x18ftypisom" . str_repeat('v', 80));
        [$service, $channel, $users, $user] = $this->service($video);
        $this->pdo->prepare('UPDATE customers SET ai_video = 1 WHERE user_id = ?')->execute([(int) $user['id']]);
        $user = $users->find((int) $user['id']);
        $this->assertIsArray($user);

        $service->chooseVideoSeconds($user, 940001, 'cb', 8);
        $user = $users->find((int) $user['id']);
        $this->assertIsArray($user);
        $this->assertTrue($service->handleAiVideoText($user, 940001, 'kefir em cima da mesa'));
        $this->assertSame(8, $video->seconds);
        $this->assertStringContainsString('kefir em cima da mesa', $video->prompt);
        $this->assertStringContainsString('instrumental', $video->prompt);
        $this->assertStringContainsString('No voice', $video->prompt);

        $posts = new PostRepository($this->pdo);
        $row = $this->pdo->query("SELECT id, status, video_job_id, video_seconds FROM posts ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertIsArray($row);
        $this->assertSame('job-1', $row['video_job_id']);
        $this->assertSame(8, (int) $row['video_seconds']);
        $this->assertSame(PostStatus::Generating->value, $row['status']);

        $video->state = 'pending';
        $service->finishAiVideos();
        $this->assertSame(PostStatus::Generating->value, $posts->find((int) $row['id'])['status']);
        $this->pdo->prepare('UPDATE posts SET created_at = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id = ?')->execute([(int) $row['id']]);
        $service->finishAiVideos();
        $waiting = implode("\n", array_column($channel->sent, 'text'));
        $this->assertStringContainsString('Ainda estou gerando', $waiting);

        $video->state = 'completed';
        $service->finishAiVideos();
        $fresh = $posts->find((int) $row['id']);
        $this->assertSame(PostStatus::AwaitingApproval->value, $fresh['status']);
        $media = $posts->media((int) $row['id']);
        $this->assertSame('video', $media[0]['kind']);
        $public = dirname(__DIR__) . '/public/m/' . $media[0]['public_name'] . '.mp4';
        $this->assertFileExists($public);
        unlink($public);
        $original = (string) $media[0]['original_path'];
        if (is_file($original)) {
            unlink($original);
        }
        $texts = implode("\n", array_column($channel->sent, 'text'));
        $this->assertStringContainsString('8 segundos', $texts);
    }

    public function testARequestedSentenceIsKeptOnScreen(): void
    {
        $video = new QuietVideo("\x00\x00\x00\x18ftypisom");
        [$service, $channel, $users, $user] = $this->service($video);
        $this->pdo->prepare('UPDATE customers SET ai_video = 1 WHERE user_id = ?')->execute([(int) $user['id']]);
        $user = $users->find((int) $user['id']);
        $this->assertIsArray($user);
        $service->chooseVideoSeconds($user, 940001, 'cb', 15);
        $user = $users->find((int) $user['id']);
        $this->assertIsArray($user);
        $service->handleAiVideoText($user, 940001, 'Cena na igreja e no final uma frase A lei do Senhor Deus e perfeita, conforto para alma.');
        $this->assertStringContainsString('A lei do Senhor Deus e perfeita', $video->prompt);
        $this->assertStringContainsString('Do not speak it', $video->prompt);
    }

    /**
     * @return array{0:PostService,1:FlowChannel,2:UserRepository,3:array<string,mixed>}
     */
    private function service(QuietVideo $video): array
    {
        $users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $posts = new PostRepository($this->pdo);
        $channel = new FlowChannel();
        $userId = $users->create(940001, 940001, 'Video');
        $users->update($userId, ['display_name' => 'Ana', 'onboarding_step' => 'done']);
        $this->pdo->prepare(
            'INSERT INTO customers (name, email, phone, document, document_type, user_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute(['Ana', 'ana@teste.local', '11999999999', '94000194000', 'cpf', $userId, 'ativo']);
        $service = new PostService(
            $users,
            $posts,
            $channel,
            new class implements ImageNormalizerInterface {
                public function normalize(array $sourcePaths, string $publicDirectory): array
                {
                    return [];
                }
            },
            new class implements CaptionGeneratorInterface {
                public function generate(array $profile, string $theme, array $jpegPaths, ?string $previousCaption = null, ?string $feedback = null): CaptionResult
                {
                    return new CaptionResult('Legenda do vídeo', [], 'alt', 'l', 'm', 1, 1);
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
            new PlanAccess($this->pdo),
            null,
            null,
            $video,
        );
        $user = $users->find($userId);
        $this->assertIsArray($user);

        return [$service, $channel, $users, $user];
    }
}

final class QuietVideo implements IdeaVideoGenerator
{
    public string $prompt = '';

    public int $seconds = 0;

    public string $state = 'completed';

    public function __construct(private readonly string $mp4 = '')
    {
    }

    public function submit(string $prompt, int $seconds, ?string $referencePath = null): string
    {
        $this->prompt = $prompt;
        $this->seconds = $seconds;

        return 'job-1';
    }

    public function status(string $jobId): array
    {
        return [
            'status' => $this->state,
            'url' => $this->state === 'completed' ? 'https://example.test/v.mp4' : null,
            'error' => null,
        ];
    }

    public function download(string $url): string
    {
        return $this->mp4;
    }
}

final class FlowChannel implements \PerfilEmDia\Channel\ChannelInterface
{
    /** @var list<array{type:string, text?:string, buttons?:array<int, array<int, array<string, string>>>}> */
    public array $sent = [];

    public function sendText(int $chatId, string $text, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'text', 'text' => $text, 'buttons' => $buttons ?? []];

        return count($this->sent);
    }

    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'photo', 'text' => (string) $caption, 'buttons' => $buttons ?? []];

        return count($this->sent);
    }

    public function sendVideo(int $chatId, string $videoPath, ?string $caption, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'video', 'text' => (string) $caption, 'buttons' => $buttons ?? []];

        return count($this->sent);
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

    public function download(string $fileId, string $destPath): void
    {
    }
}
