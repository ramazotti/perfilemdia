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
use PerfilEmDia\Image\IdeaImageGenerator;
use PerfilEmDia\Image\ImageEditRequest;
use PerfilEmDia\Image\ImageEditorInterface;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Image\NormalizedImage;
use PerfilEmDia\Image\OpenRouterImageEditor;
use PerfilEmDia\Image\PhotoPhrase;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Instagram\PublishedMedia;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Support\HttpPoster;
use PHPUnit\Framework\TestCase;

final class ImageEditTest extends TestCase
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
        $path = dirname(__DIR__) . '/public/m/' . str_repeat('b', 40) . '.jpg';
        if (is_file($path)) {
            unlink($path);
        }
        $original = dirname(__DIR__) . '/public/m/' . str_repeat('a', 40) . '.jpg';
        if (is_file($original)) {
            unlink($original);
        }
    }

    public function testParseKeepsTheQuotedPhraseLiteral(): void
    {
        $request = ImageEditRequest::parse("mais luz na bancada, \"estoque novo\"");

        $this->assertSame('mais luz na bancada', $request->treatment);
        $this->assertSame('estoque novo', $request->phrase);
    }

    public function testPhraseIsDrawnOnThePhoto(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdj');
        $this->assertNotFalse($path);
        $image = imagecreatetruecolor(200, 160);
        $this->assertNotFalse($image);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
        $before = file_get_contents($path);
        PhotoPhrase::draw($path, 'estoque novo');
        $after = file_get_contents($path);
        $this->assertNotSame($before, $after);
        $this->assertIsString($after);
        $this->assertStringStartsWith("\xFF\xD8", $after);
        unlink($path);
    }

    public function testEditorDecodesJpegFromTheApi(): void
    {
        $jpeg = $this->tinyJpeg();
        $http = new class($jpeg) implements HttpPoster {
            public function __construct(private readonly string $jpeg)
            {
            }

            public function request(string $method, string $url, array $options = []): array
            {
                return [
                    'status' => 200,
                    'body' => ['data' => [['b64_json' => base64_encode($this->jpeg)]]],
                ];
            }
        };
        $source = tempnam(sys_get_temp_dir(), 'pds');
        $this->assertNotFalse($source);
        file_put_contents($source, $jpeg);
        $edited = (new OpenRouterImageEditor($http))->edit($source, 'mais luz');
        $this->assertStringStartsWith("\xFF\xD8", $edited);
        unlink($source);
    }

    public function testEssencialDoesNotStartAnImageEdit(): void
    {
        [$service, $channel, $users, $posts, $user, $postId] = $this->readyPost('essencial');
        $service->handleApprovalCallback($user, 930001, 'cb', 'img', $postId);

        $fresh = $posts->find($postId);
        $this->assertSame(PostStatus::AwaitingApproval->value, $fresh['status']);
        $this->assertStringContainsString('Profissional', implode(' ', array_column($channel->sent, 'text')));
    }

    public function testProfissionalEditsThePhotoAndReturnsToApproval(): void
    {
        [$service, $channel, $users, $posts, $user, $postId] = $this->readyPost('profissional');
        $service->handleApprovalCallback($user, 930001, 'cb', 'img', $postId);
        $this->assertSame(PostStatus::AwaitingImageEdit->value, $posts->find($postId)['status']);

        $handled = $service->handleThemeText($user, 930001, "mais luz na bancada, \"estoque novo\"");

        $this->assertTrue($handled);
        $fresh = $posts->find($postId);
        $this->assertSame(PostStatus::AwaitingApproval->value, $fresh['status']);
        $this->assertSame(1, (int) $fresh['image_edit_count']);
        $media = $posts->media($postId);
        $original = (string) ($media[0]['original_path'] ?? '');
        $public = dirname(__DIR__) . '/public/m/' . $media[0]['public_name'] . '.jpg';
        $this->assertNotSame('', $original);
        $this->assertFileEquals($public, $original);
        if (is_file($original)) {
            unlink($original);
        }
        $photos = array_filter($channel->sent, static fn (array $row): bool => $row['type'] === 'photo');
        $this->assertNotEmpty($photos);
        $this->assertContains('Tratar foto', $this->buttonLabels($channel));
    }

    public function testTreatPhotoStaysAvailableForASecondUse(): void
    {
        [$service, $channel, $users, $posts, $user, $postId] = $this->readyPost('profissional');
        $service->handleApprovalCallback($user, 930001, 'cb', 'img', $postId);
        $service->handleThemeText($user, 930001, 'mais luz');
        $this->assertContains('Tratar foto', $this->buttonLabels($channel));
        $this->assertSame(1, (int) $posts->find($postId)['image_edit_count']);

        $service->handleApprovalCallback($user, 930001, 'cb2', 'img', $postId);
        $service->handleThemeText($user, 930001, 'mais contraste');

        $this->assertSame(2, (int) $posts->find($postId)['image_edit_count']);
        $this->assertNotContains('Tratar foto', $this->buttonLabels($channel));
        $this->assertContains('Texto na foto', $this->buttonLabels($channel));
    }

    public function testQuotedPhraseDoesNotSpendATreatment(): void
    {
        [$service, $channel, $users, $posts, $user, $postId] = $this->readyPost('profissional');
        $service->handleApprovalCallback($user, 930001, 'cb', 'img', $postId);
        $handled = $service->handleThemeText($user, 930001, '"estoque novo"');

        $this->assertTrue($handled);
        $this->assertSame(0, (int) $posts->find($postId)['image_edit_count']);
        $this->assertSame(PostStatus::AwaitingApproval->value, $posts->find($postId)['status']);
    }

    public function testCreatedPhotoCanBeRedoneTwice(): void
    {
        $ideas = new CountingIdea($this->tinyJpeg());
        [$service, $channel, $users, $posts, $user, $postId] = $this->readyPost('estudio', $ideas);
        $media = $posts->media($postId);
        $original = sys_get_temp_dir() . '/pd-idea-' . $postId . '.jpg';
        file_put_contents($original, $this->tinyJpeg());
        $posts->updateMedia((int) $media[0]['id'], ['original_path' => $original]);
        $posts->update($postId, ['creative' => 1, 'theme_text' => 'vitrine iluminada']);

        try {
            $service->handleApprovalCallback($user, 930001, 'cb', 'pic', $postId);
            $this->assertSame(1, $ideas->calls);
            $this->assertContains('Outra foto', $this->buttonLabels($channel));

            $service->handleApprovalCallback($user, 930001, 'cb2', 'pic', $postId);
            $this->assertSame(2, $ideas->calls);
            $this->assertSame(2, (int) $posts->find($postId)['idea_regen_count']);
            $this->assertNotContains('Outra foto', $this->buttonLabels($channel));

            $service->handleApprovalCallback($user, 930001, 'cb3', 'pic', $postId);
            $this->assertSame(2, $ideas->calls);
            $texts = implode(' ', array_column($channel->sent, 'text'));
            $this->assertStringContainsString('duas versões novas', $texts);
        } finally {
            if (is_file($original)) {
                unlink($original);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function buttonLabels(EditTestChannel $channel): array
    {
        $photos = array_values(array_filter(
            $channel->sent,
            static fn (array $row): bool => $row['type'] === 'photo',
        ));
        $last = $photos[array_key_last($photos)] ?? null;
        if ($last === null) {
            return [];
        }
        $labels = [];
        foreach ($last['buttons'] ?? [] as $row) {
            foreach ($row as $button) {
                $labels[] = (string) ($button['text'] ?? '');
            }
        }

        return $labels;
    }

    /**
     * @return array{0:PostService,1:EditTestChannel,2:UserRepository,3:PostRepository,4:array<string,mixed>,5:int}
     */
    private function readyPost(string $slug, ?IdeaImageGenerator $ideas = null): array
    {
        $users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $posts = new PostRepository($this->pdo);
        $channel = new EditTestChannel();
        $userId = $users->create(930001, 930001, 'Foto');
        $users->update($userId, ['display_name' => 'Ana', 'onboarding_step' => 'done']);
        $this->subscribe($userId, $slug);

        $postId = $posts->create($userId, PostStatus::AwaitingApproval, 'tema');
        $posts->update($postId, ['caption' => 'Legenda', 'alt_text' => 'alt']);
        $posts->addMedia($postId, 0, 'file-a', 1);
        $media = $posts->media($postId);
        $name = str_repeat('a', 40);
        $dir = dirname(__DIR__) . '/public/m';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $name . '.jpg', $this->tinyJpeg());
        $posts->updateMedia((int) $media[0]['id'], ['public_name' => $name]);

        $service = new PostService(
            $users,
            $posts,
            $channel,
            new CopyJpegNormalizer(),
            new class implements CaptionGeneratorInterface {
                public function generate(array $profile, string $theme, array $jpegPaths, ?string $previousCaption = null, ?string $feedback = null): CaptionResult
                {
                    return new CaptionResult('l', [], 'a', 'l', 'm', 1, 1);
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
            new class implements ImageEditorInterface {
                public function edit(string $jpegPath, string $instruction): string
                {
                    $image = imagecreatetruecolor(80, 60);
                    ob_start();
                    imagejpeg($image, null, 80);
                    $bytes = ob_get_clean();
                    imagedestroy($image);

                    return is_string($bytes) ? $bytes : '';
                }
            },
            $ideas,
        );
        $user = $users->find($userId);
        $this->assertIsArray($user);

        return [$service, $channel, $users, $posts, $user, $postId];
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
        )->execute(['Ana', $slug . '@teste.local', '11999999999', '9' . $userId . $slug, 'cpf', $userId, 'ativo']);
        $customerId = (int) $this->pdo->lastInsertId();
        $end = (new \DateTimeImmutable('+20 days'))->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO subscriptions (customer_id, plan_id, cycle, status, price_cents, current_period_end, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$customerId, (int) $planId, 'mensal', 'ativa', 4900, $end]);
    }

    private function tinyJpeg(): string
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return is_string($bytes) ? $bytes : '';
    }
}

final class EditTestChannel implements \PerfilEmDia\Channel\ChannelInterface
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
        $this->sent[] = [
            'type' => 'photo',
            'chatId' => $chatId,
            'text' => (string) $caption,
            'buttons' => $buttons ?? [],
        ];

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

final class CountingIdea implements IdeaImageGenerator
{
    public int $calls = 0;

    public function __construct(private readonly string $jpeg)
    {
    }

    public function create(string $idea, ?string $referenceJpeg = null, string $brand = ''): string
    {
        $this->calls++;

        return $this->jpeg;
    }
}

final class CopyJpegNormalizer implements ImageNormalizerInterface
{
    public function normalize(array $sourcePaths, string $publicDirectory): array
    {
        $name = str_repeat('b', 40);
        $abs = rtrim($publicDirectory, '/') . '/' . $name . '.jpg';
        copy($sourcePaths[0], $abs);

        return [new NormalizedImage($name, $abs, 80, 60, 80 / 60)];
    }
}
