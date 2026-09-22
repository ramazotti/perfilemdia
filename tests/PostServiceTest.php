<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Ai\CaptionGeneratorInterface;
use PerfilEmDia\Ai\CaptionResult;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\PostStatus;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Image\NormalizedImage;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Instagram\PublishedMedia;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class PostServiceTest extends TestCase
{
    private \PDO $pdo;
    private PostTestChannel $channel;
    private UserRepository $users;
    private PostRepository $posts;
    private CountingPublisher $publisher;
    private PostService $service;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
        $this->channel = new PostTestChannel();
        $this->users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $this->posts = new PostRepository($this->pdo);
        $this->publisher = new CountingPublisher();
        $this->service = new PostService(
            $this->users,
            $this->posts,
            $this->channel,
            new FakeNormalizer(),
            new FakeCaptions(),
            $this->publisher,
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testDoublePublishDoesNotCallPublisherTwice(): void
    {
        $userId = $this->users->create(920001, 920001, 'pub');
        $this->users->update($userId, [
            'display_name' => 'Ana',
            'onboarding_step' => 'done',
        ]);
        $expires = new \DateTimeImmutable('+30 days', new \DateTimeZone('America/Sao_Paulo'));
        $this->users->saveInstagramAccount($userId, 'ig1', 'ana_ig', 'BUSINESS', 'token', $expires);

        $postId = $this->posts->create($userId, PostStatus::AwaitingApproval, 'tema');
        $this->posts->update($postId, [
            'caption' => 'Legenda de teste',
            'alt_text' => 'alt',
            'preview_message_id' => 42,
        ]);
        $this->posts->addMedia($postId, 0, 'file-a', 1);
        $media = $this->posts->media($postId);
        $this->posts->updateMedia((int) $media[0]['id'], ['public_name' => str_repeat('a', 40)]);

        $user = $this->users->find($userId);
        $this->assertNotNull($user);

        $this->service->handleApprovalCallback($user, 920001, 'cb1', 'pub', $postId);
        $this->service->handleApprovalCallback($user, 920001, 'cb2', 'pub', $postId);

        $this->assertSame(1, $this->publisher->calls);
        $fresh = $this->posts->find($postId);
        $this->assertNotNull($fresh);
        $this->assertSame(PostStatus::Published->value, $fresh['status']);
    }
}

final class PostTestChannel implements \PerfilEmDia\Channel\ChannelInterface
{
    /** @var list<array{type:string, chatId:int, text?:string}> */
    public array $sent = [];

    public function sendText(int $chatId, string $text, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'text', 'chatId' => $chatId, 'text' => $text];

        return count($this->sent);
    }

    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'photo', 'chatId' => $chatId, 'text' => (string) $caption];

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

final class CountingPublisher implements InstagramPublisherInterface
{
    public int $calls = 0;

    public function publish(
        string $igUserId,
        string $accessToken,
        array $imageUrls,
        string $caption,
        ?string $altText = null,
    ): PublishedMedia {
        $this->calls++;

        return new PublishedMedia('c1', 'm1', 'https://instagram.com/p/x');
    }
}

final class FakeNormalizer implements ImageNormalizerInterface
{
    public function normalize(array $sourcePaths, string $publicDirectory): array
    {
        $out = [];
        foreach ($sourcePaths as $i => $path) {
            $name = 'n' . $i . str_repeat('0', 38);
            $name = substr($name, 0, 40);
            $abs = rtrim($publicDirectory, '/') . '/' . $name . '.jpg';
            if (!is_file($abs)) {
                file_put_contents($abs, 'fake');
            }
            $out[] = new NormalizedImage($name, $abs, 1080, 1080, 1.0);
        }

        return $out;
    }
}

final class FakeCaptions implements CaptionGeneratorInterface
{
    public function generate(
        array $profile,
        string $theme,
        array $jpegPaths,
        ?string $previousCaption = null,
        ?string $feedback = null,
    ): CaptionResult {
        return new CaptionResult(
            'legenda',
            ['#a'],
            'alt',
            'legenda #a',
            'fake-model',
            1,
            1,
        );
    }
}
