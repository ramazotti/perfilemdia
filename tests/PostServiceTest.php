<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Ai\CaptionGeneratorInterface;
use PerfilEmDia\Billing\PlanAccess;
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
use PerfilEmDia\Messages;
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
        $texts = array_map(static fn (array $row): string => (string) ($row['text'] ?? ''), $this->channel->sent);
        $this->assertContains(Messages::publishing(), $texts);
        $this->assertContains(Messages::published(), $texts);
    }

    public function testShortVideoRequiresProfissionalPlan(): void
    {
        $userId = $this->users->create(920002, 920002, 'video');
        $this->users->update($userId, [
            'display_name' => 'Ana',
            'onboarding_step' => 'done',
        ]);
        $expires = new \DateTimeImmutable('+30 days', new \DateTimeZone('America/Sao_Paulo'));
        $this->users->saveInstagramAccount($userId, 'ig2', 'ana_ig', 'BUSINESS', 'token', $expires);
        $user = $this->users->find($userId);
        $this->assertNotNull($user);
        $user['pending_action'] = 'kind:video';

        $this->service->handleIncomingMedia($user, 920002, [
            'message_id' => 7,
            'caption' => 'Peca nova na oficina',
            'video' => [
                'file_id' => 'vid-1',
                'duration' => 12,
                'file_size' => 800000,
                'width' => 720,
                'height' => 1280,
                'mime_type' => 'video/mp4',
            ],
        ]);

        $texts = array_map(static fn (array $row): string => (string) ($row['text'] ?? ''), $this->channel->sent);
        $this->assertContains(Messages::videoPlan(), $texts);
        $this->assertNull($this->posts->findPendingForUser($userId));
    }

    public function testLongVideoOnProfissionalIsRefused(): void
    {
        $userId = $this->users->create(920003, 920003, 'videolong');
        $this->users->update($userId, [
            'display_name' => 'Ana',
            'onboarding_step' => 'done',
        ]);
        $expires = new \DateTimeImmutable('+30 days', new \DateTimeZone('America/Sao_Paulo'));
        $this->users->saveInstagramAccount($userId, 'ig3', 'ana_ig', 'BUSINESS', 'token', $expires);
        $this->subscribe($userId, 'profissional');
        $user = $this->users->find($userId);
        $this->assertNotNull($user);
        $user['pending_action'] = 'kind:video';

        $service = new PostService(
            $this->users,
            $this->posts,
            $this->channel,
            new FakeNormalizer(),
            new FakeCaptions(),
            $this->publisher,
            new PlanAccess($this->pdo),
        );
        $service->handleIncomingMedia($user, 920003, [
            'message_id' => 8,
            'caption' => 'Peca nova',
            'video' => [
                'file_id' => 'vid-2',
                'duration' => 120,
                'file_size' => 800000,
                'width' => 720,
                'height' => 1280,
            ],
        ]);

        $texts = array_map(static fn (array $row): string => (string) ($row['text'] ?? ''), $this->channel->sent);
        $this->assertContains(Messages::videoTooLong(), $texts);
        $this->assertNull($this->posts->findPendingForUser($userId));
    }

    public function testReplacingAPendingPostStillRefusesALongVideo(): void
    {
        $userId = $this->users->create(920011, 920011, 'troca');
        $this->users->update($userId, [
            'display_name' => 'Ana',
            'onboarding_step' => 'done',
        ]);
        $expires = new \DateTimeImmutable('+30 days', new \DateTimeZone('America/Sao_Paulo'));
        $this->users->saveInstagramAccount($userId, 'ig11', 'ana_ig', 'BUSINESS', 'token', $expires);
        $this->subscribe($userId, 'profissional');
        $postId = $this->posts->create($userId, PostStatus::AwaitingApproval, 'tema');
        $user = $this->users->find($userId);
        $this->assertNotNull($user);
        $user['pending_action'] = 'kind:video';
        $service = new PostService(
            $this->users,
            $this->posts,
            $this->channel,
            new FakeNormalizer(),
            new FakeCaptions(),
            $this->publisher,
            new PlanAccess($this->pdo),
        );
        $service->handleIncomingMedia($user, 920011, [
            'message_id' => 18,
            'video' => [
                'file_id' => 'vid-replace',
                'duration' => 120,
                'file_size' => 800000,
                'width' => 720,
                'height' => 1280,
            ],
        ]);

        $texts = array_map(static fn (array $row): string => (string) ($row['text'] ?? ''), $this->channel->sent);
        $this->assertContains(Messages::videoTooLong(), $texts);
        $this->assertSame(PostStatus::AwaitingApproval->value, $this->posts->find($postId)['status']);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM posts WHERE user_id = ?');
        $count->execute([$userId]);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    public function testMediaWithoutKindAsksTheType(): void
    {
        $userId = $this->users->create(920004, 920004, 'semtipo');
        $this->users->update($userId, [
            'display_name' => 'Ana',
            'onboarding_step' => 'done',
        ]);
        $expires = new \DateTimeImmutable('+30 days', new \DateTimeZone('America/Sao_Paulo'));
        $this->users->saveInstagramAccount($userId, 'ig4', 'ana_ig', 'BUSINESS', 'token', $expires);
        $user = $this->users->find($userId);
        $this->assertNotNull($user);

        $this->service->handleIncomingMedia($user, 920004, [
            'message_id' => 9,
            'caption' => 'Uma frase',
            'photo' => [['file_id' => 'ph-1']],
        ]);

        $texts = array_map(static fn (array $row): string => (string) ($row['text'] ?? ''), $this->channel->sent);
        $this->assertContains(Messages::askPostKind(), $texts);
        $this->assertNull($this->posts->findPendingForUser($userId));
    }

    public function testAiPostRequiresStudioPlan(): void
    {
        $userId = $this->users->create(920005, 920005, 'ideia');
        $user = $this->users->find($userId);
        $this->assertNotNull($user);

        $this->service->choosePostKind($user, 920005, 'ia');

        $texts = array_map(static fn (array $row): string => (string) ($row['text'] ?? ''), $this->channel->sent);
        $this->assertContains(Messages::aiPlan(), $texts);
        $fresh = $this->users->find($userId);
        $this->assertNotNull($fresh);
        $this->assertStringStartsNotWith('kind:', (string) ($fresh['pending_action'] ?? ''));
    }

    public function testForeverCompKeepsAiAfterPeriodEnd(): void
    {
        $userId = $this->users->create(920006, 920006, 'isento');
        $this->subscribe($userId, 'estudio');
        $this->pdo->prepare(
            'UPDATE subscriptions s
             INNER JOIN customers c ON c.id = s.customer_id
             SET s.comp_forever = 1, s.current_period_end = DATE_SUB(NOW(), INTERVAL 2 DAY)
             WHERE c.user_id = ?'
        )->execute([$userId]);
        $access = new PlanAccess($this->pdo);
        $window = $access->window($userId);
        $this->assertNotNull($window);
        $this->assertSame('', $window['blocked']);
        $this->assertTrue($access->canCreateWithAi($userId));
    }

    private function subscribe(int $userId, string $slug): void
    {
        $plan = $this->pdo->prepare('SELECT id FROM plans WHERE slug = ?');
        $plan->execute([$slug]);
        $planId = $plan->fetchColumn();
        if ($planId === false) {
            $this->pdo->prepare(
                'INSERT INTO plans (slug, name, description, price_cents, posts_limit, features, highlighted, active, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, 4900, 40, ?, 1, 1, 2, NOW(), NOW())'
            )->execute([$slug, $slug, 'plano', 'item']);
            $planId = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare(
            'INSERT INTO customers (name, email, phone, document, document_type, user_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute(['Ana', $slug . '-' . $userId . '@teste.local', '11999999999', '8' . $userId . 'v', 'cpf', $userId, 'ativo']);
        $customerId = (int) $this->pdo->lastInsertId();
        $end = (new \DateTimeImmutable('+20 days'))->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO subscriptions (customer_id, plan_id, cycle, status, price_cents, current_period_end, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$customerId, (int) $planId, 'mensal', 'ativa', 4900, $end]);
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

    public function sendVideo(int $chatId, string $videoPath, ?string $caption, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'video', 'chatId' => $chatId, 'text' => (string) $caption];

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
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
    ): PublishedMedia {
        $this->calls++;

        return new PublishedMedia('c1', 'm1', 'https://instagram.com/p/x');
    }

    public function publishStory(
        string $igUserId,
        string $accessToken,
        string $mediaUrl,
        bool $video,
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
    ): PublishedMedia {
        $this->calls++;

        return new PublishedMedia('c1', 'm1', 'https://instagram.com/stories/x');
    }

    public function publishReel(
        string $igUserId,
        string $accessToken,
        string $videoUrl,
        string $caption,
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
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
