<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Ai\CaptionGeneratorInterface;
use PerfilEmDia\Ai\CaptionResult;
use PerfilEmDia\Billing\PlanAccess;
use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\OnboardingService;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Image\IdeaImageGenerator;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Image\NormalizedImage;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Instagram\PublishedMedia;
use PerfilEmDia\Messages;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Telegram\UpdateHandler;
use PHPUnit\Framework\TestCase;

final class StoryIdeaDryRunTest extends TestCase
{
    private const TELEGRAM_ID = 88044199;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testStoryIdeaSequenceDoesNotAskForAPhotoAgain(): void
    {
        $users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $posts = new PostRepository($this->pdo);
        $channel = new DryRunChannel();
        $userId = $users->create(self::TELEGRAM_ID, self::TELEGRAM_ID, 'dryrun');
        $users->update($userId, [
            'display_name' => 'Ana',
            'onboarding_step' => 'done',
            'profession' => 'pastora',
            'city' => 'online',
        ]);
        $users->saveInstagramAccount(
            $userId,
            'ig-dry-' . $userId,
            'dry.run',
            'BUSINESS',
            'token',
            new DateTimeImmutable('+30 days', new DateTimeZone('America/Sao_Paulo')),
        );
        $this->subscribe($userId);

        $service = new PostService(
            $users,
            $posts,
            $channel,
            new DryRunNormalizer(),
            new DryRunCaptions(),
            new DryRunPublisher(),
            new PlanAccess($this->pdo),
            null,
            new DryRunIdea(),
        );
        $handler = new UpdateHandler(
            $users,
            $posts,
            $channel,
            new OnboardingService($users, $channel),
            $service,
        );

        $from = ['id' => self::TELEGRAM_ID, 'username' => 'dryrun'];
        $chat = ['id' => self::TELEGRAM_ID, 'type' => 'private'];
        $handler->handle([
            'update_id' => 1,
            'message' => ['message_id' => 1, 'from' => $from, 'chat' => $chat, 'text' => '/novo'],
        ]);
        $handler->handle([
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb-story',
                'data' => 'wh:story',
                'from' => $from,
                'message' => ['message_id' => 2, 'chat' => $chat],
            ],
        ]);
        $handler->handle([
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cb-ia',
                'data' => 'pk:ia',
                'from' => $from,
                'message' => ['message_id' => 3, 'chat' => $chat],
            ],
        ]);
        $idea = 'Quero outra história, com outra reflexão sobre o dia da eleição. Seria algo assim, o que Jesus faria hoje? Que número ele escolheria? E a imagem dele... Talvez... Ele escrevendo algo no papel';
        $handler->handle([
            'update_id' => 4,
            'message' => ['message_id' => 4, 'from' => $from, 'chat' => $chat, 'text' => $idea],
        ]);

        $texts = array_map(static fn (array $row): string => (string) ($row['text'] ?? ''), $channel->sent);
        $joined = implode("\n", $texts);
        $this->assertStringNotContainsString(Messages::captionFailed(), $joined, $joined);
        $row = $this->pdo->query(
            'SELECT status, creative, destination, CHAR_LENGTH(photo_phrase) AS phrase_len FROM posts WHERE user_id = ' . $userId . ' ORDER BY id DESC LIMIT 1'
        )->fetch();
        $this->assertIsArray($row);
        $this->assertSame('story', $row['destination']);
        $this->assertSame(1, (int) $row['creative']);
        $this->assertNotSame('FAILED', $row['status'], $joined);
        $this->assertGreaterThan(80, (int) $row['phrase_len']);
        $this->assertStringContainsString('Que número ele escolheria?', $joined);
    }

    private function subscribe(int $userId): void
    {
        $plan = $this->pdo->prepare('SELECT id FROM plans WHERE slug = ?');
        $plan->execute(['estudio']);
        $planId = $plan->fetchColumn();
        $this->assertNotFalse($planId);
        $this->pdo->prepare(
            'INSERT INTO customers (name, email, phone, document, document_type, user_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute(['Ana', 'dry-' . $userId . '@teste.local', '11999999999', '9' . $userId, 'cpf', $userId, 'ativo']);
        $customerId = (int) $this->pdo->lastInsertId();
        $end = (new DateTimeImmutable('+20 days'))->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO subscriptions (customer_id, plan_id, cycle, status, price_cents, current_period_end, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$customerId, (int) $planId, 'mensal', 'ativa', 7490, $end]);
    }

    private function cleanup(): void
    {
        $user = $this->pdo->prepare('SELECT id FROM users WHERE telegram_user_id = ?');
        $user->execute([self::TELEGRAM_ID]);
        $userId = $user->fetchColumn();
        if ($userId === false) {
            return;
        }
        $userId = (int) $userId;
        $media = $this->pdo->prepare(
            'SELECT pm.public_name, pm.original_path FROM post_media pm INNER JOIN posts p ON p.id = pm.post_id WHERE p.user_id = ?'
        );
        $media->execute([$userId]);
        foreach ($media->fetchAll() as $row) {
            $name = (string) ($row['public_name'] ?? '');
            if ($name !== '') {
                foreach (['.jpg', '.mp4'] as $ext) {
                    $path = Config::root() . '/public/m/' . $name . $ext;
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            $original = (string) ($row['original_path'] ?? '');
            if ($original !== '' && is_file($original)) {
                unlink($original);
            }
        }
        $ids = $this->pdo->prepare('SELECT id FROM posts WHERE user_id = ?');
        $ids->execute([$userId]);
        foreach ($ids->fetchAll() as $row) {
            $clean = Config::root() . '/storage/media/storyclean_' . (int) $row['id'] . '.jpg';
            foreach (glob(Config::root() . '/storage/media/' . (int) $row['id'] . '_*.jpg') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        $mediaIds = $this->pdo->prepare(
            'SELECT pm.id FROM post_media pm INNER JOIN posts p ON p.id = pm.post_id WHERE p.user_id = ?'
        );
        $mediaIds->execute([$userId]);
        foreach ($mediaIds->fetchAll() as $row) {
            $clean = Config::root() . '/storage/media/storyclean_' . (int) $row['id'] . '.jpg';
            if (is_file($clean)) {
                unlink($clean);
            }
        }
        $this->pdo->prepare('DELETE pm FROM post_media pm INNER JOIN posts p ON p.id = pm.post_id WHERE p.user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE s FROM subscriptions s INNER JOIN customers c ON c.id = s.customer_id WHERE c.user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM customers WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM instagram_accounts WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}

final class DryRunIdea implements IdeaImageGenerator
{
    public function create(string $idea, ?string $referenceJpeg = null, string $brand = '', string $aspect = ''): string
    {
        $image = imagecreatetruecolor(80, 140);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return is_string($jpeg) ? $jpeg : '';
    }
}

final class DryRunCaptions implements CaptionGeneratorInterface
{
    public function generate(array $profile, string $theme, array $jpegPaths, ?string $previousCaption = null, ?string $feedback = null): CaptionResult
    {
        $text = 'Quero outra história, com outra reflexão sobre o dia da eleição. Seria algo assim, o que Jesus faria hoje? Que número ele escolheria?';

        return new CaptionResult($text, [], 'alt', $text, 'dry', 1, 1);
    }
}

final class DryRunNormalizer implements ImageNormalizerInterface
{
    public function normalize(array $sourcePaths, string $publicDirectory, string $canvas = 'feed'): array
    {
        $name = bin2hex(random_bytes(20));
        $path = $publicDirectory . '/' . $name . '.jpg';
        copy($sourcePaths[0], $path);
        $size = @getimagesize($path);

        return [new NormalizedImage($name, $path, (int) ($size[0] ?? 80), (int) ($size[1] ?? 140), 0.5)];
    }
}

final class DryRunPublisher implements InstagramPublisherInterface
{
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
}

final class DryRunChannel implements \PerfilEmDia\Channel\ChannelInterface
{
    /** @var list<array{text:string}> */
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

    public function download(string $fileId, string $destPath): void
    {
    }
}
