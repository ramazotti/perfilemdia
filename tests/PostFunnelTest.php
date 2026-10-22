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
use PerfilEmDia\Domain\IncomingMediaStash;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\PostStatus;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Image\IdeaImageGenerator;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Image\NormalizedImage;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Instagram\PublishedMedia;
use PerfilEmDia\Messages;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * Funil do /novo: destino, tipo, formato (só IA) e as perguntas no meio do caminho.
 *
 * Sem transação: a geração da IA reconecta o banco (wakeDatabase), então limpa as linhas no fim.
 */
final class PostFunnelTest extends TestCase
{
    private const TELEGRAM_ID = 930100;

    private const LONG = 'Hoje quero homenagear a nossa comunidade, que há trinta anos se reúne toda semana para rezar, '
        . 'acolher quem chega e servir quem precisa. Obrigado a cada voluntário que fez isso acontecer com tanto carinho.';

    private \PDO $pdo;
    private FunnelChannel $channel;
    private UserRepository $users;
    private PostRepository $posts;
    private FunnelIdea $ideas;
    private PostService $service;
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->cleanup();
        $this->channel = new FunnelChannel();
        $this->users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $this->posts = new PostRepository($this->pdo);
        $this->ideas = new FunnelIdea();
        $this->service = new PostService(
            $this->users,
            $this->posts,
            $this->channel,
            new FunnelNormalizer(),
            new FunnelCaptions(),
            new FunnelPublisher(),
            new PlanAccess($this->pdo),
            null,
            $this->ideas,
        );
        $this->userId = $this->users->create(self::TELEGRAM_ID, self::TELEGRAM_ID, 'funil');
        $this->users->update($this->userId, [
            'display_name' => 'Ana',
            'onboarding_step' => 'done',
            'about' => 'Exame de consciência e confissão',
        ]);
        $this->users->saveInstagramAccount(
            $this->userId,
            'ig-funil',
            'funil_ig',
            'BUSINESS',
            'token',
            new DateTimeImmutable('+30 days', new DateTimeZone('America/Sao_Paulo')),
            5,
        );
        $this->subscribe($this->userId);
        IncomingMediaStash::clear($this->userId);
    }

    protected function tearDown(): void
    {
        IncomingMediaStash::clear($this->userId);
        $this->pdo = Db::pdo();
        $this->cleanup();
    }

    public function testFormatIsAskedOnlyForAiAndChosenFormatWinsOverWords(): void
    {
        $this->service->chooseWhere($this->user(), self::TELEGRAM_ID, 'story');
        $this->assertContains(Messages::askStoryKind(), $this->channel->texts());
        $this->assertNotContains(Messages::askIdeaFormat(), $this->channel->texts());

        $this->service->choosePostKind($this->user(), self::TELEGRAM_ID, 'ia');
        $this->assertContains(Messages::askIdeaFormat(), $this->channel->texts());

        $this->service->chooseIdeaFormat($this->user(), self::TELEGRAM_ID, 'quote');
        $this->assertSame('kind:ia@story|fmt:quote', $this->pending());

        $this->service->handleIdeaText($this->user(), self::TELEGRAM_ID, '3 passos para uma boa confissão');

        $this->assertCount(1, $this->ideas->calls);
        $this->assertStringStartsWith("Cita\u{00e7}\u{00e3}o:", $this->ideas->calls[0]['idea']);
        $this->assertSame('9:16', $this->ideas->calls[0]['aspect']);
        $post = $this->posts->findPendingForUser($this->userId);
        $this->assertNotNull($post);
        $this->assertSame('story', $post['destination']);
    }

    public function testOwnPhotoNeverAsksForAFormat(): void
    {
        $this->service->chooseWhere($this->user(), self::TELEGRAM_ID, 'feed');
        $this->service->choosePostKind($this->user(), self::TELEGRAM_ID, 'foto');

        $this->assertSame('kind:foto', $this->pending());
        $this->assertNotContains(Messages::askIdeaFormat(), $this->channel->texts());
    }

    public function testIdeaTextBeforeFormatIsKeptUntilTheFormatIsPicked(): void
    {
        $this->service->chooseWhere($this->user(), self::TELEGRAM_ID, 'feed');
        $this->service->choosePostKind($this->user(), self::TELEGRAM_ID, 'ia');
        $this->service->handleIdeaText($this->user(), self::TELEGRAM_ID, 'dicas para preparar a confissão');

        $this->assertContains(Messages::ideaNeedFormat(), $this->channel->texts());
        $this->assertSame([], $this->ideas->calls);

        $this->service->chooseIdeaFormat($this->user(), self::TELEGRAM_ID, 'infographic');

        $this->assertCount(3, $this->ideas->calls);
        $this->assertStringStartsWith("Infogr\u{00e1}fico com 3 slides:", $this->ideas->calls[0]['idea']);
        $this->assertSame('4:5', $this->ideas->calls[0]['aspect']);
    }

    public function testMediaInStoryKindMenuKeepsStoryMenu(): void
    {
        $this->service->chooseWhere($this->user(), self::TELEGRAM_ID, 'story');
        $this->channel->sent = [];

        $this->service->handleIncomingMedia($this->user(), self::TELEGRAM_ID, $this->photo('frase curta'));

        $this->assertContains(Messages::askStoryKind(), $this->channel->texts());
        $this->assertNotContains(Messages::askPostKind(), $this->channel->texts());
    }

    public function testLongCaptionOnOwnPhotoAsksAndOwnChoiceKeepsThePhoto(): void
    {
        $this->setPending('kind:foto');

        $this->service->handleIncomingMedia($this->user(), self::TELEGRAM_ID, $this->photo(self::LONG));

        $this->assertContains(Messages::photoAsk(), $this->channel->texts());
        $this->assertSame([], $this->ideas->calls);
        $this->assertSame(1, preg_match('/^photoask:(\d+)$/', $this->pending(), $m));

        $this->service->handlePhotoChoice($this->user(), self::TELEGRAM_ID, true, (int) $m[1]);

        $this->assertSame([], $this->ideas->calls);
        $post = $this->posts->find((int) $m[1]);
        $this->assertNotNull($post);
        $this->assertSame(PostStatus::AwaitingApproval->value, $post['status']);
        $this->assertSame(0, (int) $post['creative']);
    }

    public function testAiChoiceFromOwnPhotoAsksFormatAndUsesThePhotoAsReference(): void
    {
        $this->setPending('kind:foto@story');
        $this->service->handleIncomingMedia($this->user(), self::TELEGRAM_ID, $this->photo(self::LONG));
        $this->assertSame(1, preg_match('/^photoask:(\d+)$/', $this->pending(), $m));

        $this->service->handlePhotoChoice($this->user(), self::TELEGRAM_ID, false, (int) $m[1]);

        $this->assertSame('kind:ia@story', $this->pending());
        $this->assertContains(Messages::askIdeaFormat(), $this->channel->texts());

        $this->service->chooseIdeaFormat($this->user(), self::TELEGRAM_ID, 'quote');

        $this->assertCount(1, $this->ideas->calls);
        $this->assertNotNull($this->ideas->calls[0]['reference']);
        $this->assertSame('9:16', $this->ideas->calls[0]['aspect']);
    }

    public function testReplacingWithLongTextAsksInsteadOfSayingReceived(): void
    {
        $oldId = $this->pendingPost();

        $this->service->handleIncomingMedia($this->user(), self::TELEGRAM_ID, $this->photo(self::LONG));
        $this->assertContains(Messages::replacePending(), $this->channel->texts());
        $this->channel->sent = [];

        $this->service->handleReplaceDecision($this->user(), self::TELEGRAM_ID, true);

        $this->assertContains(Messages::photoAsk(), $this->channel->texts());
        $this->assertNotContains(Messages::received(), $this->channel->texts());
        $this->assertSame(PostStatus::Cancelled->value, $this->posts->find($oldId)['status']);
        $this->assertSame([], $this->ideas->calls);
    }

    public function testCancelDropsTheDraftOfAnUnansweredQuestion(): void
    {
        $this->setPending('kind:foto');
        $this->service->handleIncomingMedia($this->user(), self::TELEGRAM_ID, $this->photo(self::LONG));
        $this->assertNotNull($this->posts->findOpenWorkflowForUser($this->userId));

        $this->service->cancelPending($this->user(), 930100);

        $this->assertNull($this->posts->findOpenWorkflowForUser($this->userId));
        $this->assertSame('', $this->pending());
    }

    public function testNewPostDropsTheDraftOfAnUnansweredQuestion(): void
    {
        $this->setPending('kind:foto');
        $this->service->handleIncomingMedia($this->user(), self::TELEGRAM_ID, $this->photo(self::LONG));

        $this->service->beginNewPost($this->user(), 930100);

        $this->assertNull($this->posts->findOpenWorkflowForUser($this->userId));
    }

    public function testMediaWhileQuestionIsOpenRepeatsTheQuestion(): void
    {
        $this->setPending('kind:foto');
        $this->service->handleIncomingMedia($this->user(), self::TELEGRAM_ID, $this->photo(self::LONG));
        $this->channel->sent = [];

        $this->service->handleIncomingMedia($this->user(), self::TELEGRAM_ID, $this->photo('outra'));

        $this->assertContains(Messages::photoAskReminder(), $this->channel->texts());
        $this->assertNotContains(Messages::postInProgress(), $this->channel->texts());
    }

    public function testIdeaTextDiscardsThePreviousPendingPost(): void
    {
        $oldId = $this->pendingPost();
        $this->setPending('kind:ia|fmt:photo');

        $this->service->handleIdeaText($this->user(), self::TELEGRAM_ID, 'um café da manhã em família');

        $this->assertSame(PostStatus::Cancelled->value, $this->posts->find($oldId)['status']);
        $this->assertCount(1, $this->ideas->calls);
        $this->assertStringStartsWith('Foto simples:', $this->ideas->calls[0]['idea']);
    }

    public function testFailedImageKeepsTheStepSoTheIdeaCanBeSentAgain(): void
    {
        $this->setPending('kind:ia@story|fmt:checklist');
        $this->ideas->fail = true;

        $this->service->handleIdeaText($this->user(), self::TELEGRAM_ID, 'o que levar para a confissão');

        $this->assertContains(Messages::ideaFailed(), $this->channel->texts());
        $this->assertSame('kind:ia@story|fmt:checklist', $this->pending());
    }

    public function testChangeFormatButtonKeepsTheAiStep(): void
    {
        $this->setPending('kind:ia@story|fmt:quote');

        $this->service->choosePostKind($this->user(), self::TELEGRAM_ID, 'formatos');
        $this->service->chooseIdeaFormat($this->user(), self::TELEGRAM_ID, 'checklist');

        $this->assertSame('kind:ia@story|fmt:checklist', $this->pending());
        $this->assertContains(Messages::kindStoryIa(), $this->channel->texts());
    }

    public function testDesignedCardGetsADesignBriefNotAPhotoScene(): void
    {
        $this->users->update($this->userId, ['brand_style' => 'azul marinho e dourado', 'city' => 'Belo Horizonte', 'profession' => 'Padre', 'tone' => 'acolhedor']);
        $this->setPending('kind:ia|fmt:quote');

        $this->service->handleIdeaText($this->user(), self::TELEGRAM_ID, 'misericórdia');

        $brand = $this->ideas->calls[0]['brand'];
        $this->assertStringContainsString('azul marinho e dourado', $brand);
        $this->assertStringContainsString('Type:', $brand);
        $this->assertStringNotContainsString('Setting', $brand);
        $this->assertStringNotContainsString('natural light', $brand);
        $this->assertStringNotContainsString('Belo Horizonte', $brand);
    }

    public function testStoryPhotoKeepsRoomForThePaintedText(): void
    {
        $this->setPending('kind:ia@story|fmt:photo');

        $this->service->handleIdeaText($this->user(), self::TELEGRAM_ID, 'um café da manhã em família');

        $this->assertStringContainsString('A text box is placed over', $this->ideas->calls[0]['brand']);
    }

    public function testChangeImageAsksWhatToChangeAndUsesTheRequest(): void
    {
        $this->setPending('kind:ia|fmt:photo');
        $this->service->handleIdeaText($this->user(), self::TELEGRAM_ID, 'vitrine da livraria');
        $post = $this->posts->findPendingForUser($this->userId);
        $this->assertNotNull($post);
        $postId = (int) $post['id'];

        $this->service->handleApprovalCallback($this->user(), self::TELEGRAM_ID, 'cb', 'pic', $postId);
        $this->assertContains(Messages::askIdeaChange(), $this->channel->texts());
        $this->assertSame('pic:' . $postId, $this->pending());
        $this->assertCount(1, $this->ideas->calls);

        $this->assertTrue($this->service->handleIdeaChangeText($this->user(), self::TELEGRAM_ID, 'sem pessoas, luz da tarde'));
        $this->assertCount(2, $this->ideas->calls);
        $this->assertStringContainsString('Ajuste da imagem: sem pessoas, luz da tarde', $this->ideas->calls[1]['idea']);
        $this->assertNull($this->ideas->calls[1]['reference']);

        $this->service->handleApprovalCallback($this->user(), self::TELEGRAM_ID, 'cb', 'pic', $postId);
        $this->service->handleIdeaChangeText($this->user(), self::TELEGRAM_ID, 'mantém a cena e só troca a cor do toldo');
        $this->assertCount(3, $this->ideas->calls);
        $this->assertNotNull($this->ideas->calls[2]['reference']);
        $this->assertSame(1, substr_count($this->ideas->calls[2]['idea'], 'Ajuste da imagem:'));
        $this->assertSame('', $this->pending());
    }

    public function testEditOnADesignedCardAsksForARedoInstead(): void
    {
        $this->setPending('kind:ia|fmt:checklist');
        $this->service->handleIdeaText($this->user(), self::TELEGRAM_ID, 'o que levar para a missa');
        $post = $this->posts->findPendingForUser($this->userId);
        $this->assertNotNull($post);

        $this->service->handleApprovalCallback($this->user(), self::TELEGRAM_ID, 'cb', 'img', (int) $post['id']);

        $this->assertContains(Messages::askIdeaChange(), $this->channel->texts());
        $this->assertSame(PostStatus::AwaitingApproval->value, $this->posts->find((int) $post['id'])['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function user(): array
    {
        $user = $this->users->find($this->userId);
        $this->assertNotNull($user);

        return $user;
    }

    private function pending(): string
    {
        return (string) ($this->user()['pending_action'] ?? '');
    }

    private function setPending(string $pending): void
    {
        $this->users->update($this->userId, ['pending_action' => $pending]);
    }

    private function pendingPost(): int
    {
        $id = $this->posts->create($this->userId, PostStatus::AwaitingApproval, 'post antigo');
        $this->posts->update($id, ['caption' => 'c']);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function photo(string $caption): array
    {
        return [
            'message_id' => random_int(100, 900000),
            'caption' => $caption,
            'photo' => [['file_id' => 'ph-' . random_int(1, 999999), 'width' => 1080, 'height' => 1350]],
        ];
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
            $paths = [(string) ($row['original_path'] ?? '')];
            if ((string) ($row['public_name'] ?? '') !== '') {
                $paths[] = Config::root() . '/public/m/' . $row['public_name'] . '.jpg';
            }
            foreach ($paths as $path) {
                if ($path !== '' && is_file($path)) {
                    unlink($path);
                }
            }
        }
        $this->pdo->prepare('DELETE pm FROM post_media pm INNER JOIN posts p ON p.id = pm.post_id WHERE p.user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE s FROM subscriptions s INNER JOIN customers c ON c.id = s.customer_id WHERE c.user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM customers WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM instagram_accounts WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
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
        )->execute(['Ana', 'funil-' . $userId . '@teste.local', '11999999999', '7' . $userId, 'cpf', $userId, 'ativo']);
        $customerId = (int) $this->pdo->lastInsertId();
        $end = (new DateTimeImmutable('+20 days'))->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO subscriptions (customer_id, plan_id, cycle, status, price_cents, current_period_end, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$customerId, (int) $planId, 'mensal', 'ativa', 4900, $end]);
    }
}

final class FunnelChannel implements \PerfilEmDia\Channel\ChannelInterface
{
    /** @var list<array{type:string, text:string, buttons:?array}> */
    public array $sent = [];

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_map(static fn (array $row): string => $row['text'], $this->sent);
    }

    public function sendText(int $chatId, string $text, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'text', 'text' => $text, 'buttons' => $buttons];

        return count($this->sent);
    }

    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'photo', 'text' => (string) $caption, 'buttons' => $buttons];

        return count($this->sent);
    }

    public function sendVideo(int $chatId, string $videoPath, ?string $caption, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'video', 'text' => (string) $caption, 'buttons' => $buttons];

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
        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($destPath, FunnelIdea::jpeg());
    }
}

final class FunnelIdea implements IdeaImageGenerator
{
    /** @var list<array{idea:string, reference:?string, aspect:string, brand:string}> */
    public array $calls = [];

    public bool $fail = false;

    public function create(string $idea, ?string $referenceJpeg = null, string $brand = '', string $aspect = ''): string
    {
        if ($this->fail) {
            throw new \RuntimeException('falhou');
        }
        $this->calls[] = ['idea' => $idea, 'reference' => $referenceJpeg, 'aspect' => $aspect, 'brand' => $brand];

        return self::jpeg();
    }

    public static function jpeg(): string
    {
        $image = imagecreatetruecolor(40, 50);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }
}

final class FunnelCaptions implements CaptionGeneratorInterface
{
    public function generate(array $profile, string $theme, array $jpegPaths, ?string $previousCaption = null, ?string $feedback = null, ?string $promptExtras = null): CaptionResult
    {
        return new CaptionResult('legenda', ['#a'], 'alt', 'legenda #a', 'fake', 1, 1);
    }
}

final class FunnelNormalizer implements ImageNormalizerInterface
{
    public function normalize(array $sourcePaths, string $publicDirectory, string $canvas = 'feed'): array
    {
        $out = [];
        foreach ($sourcePaths as $path) {
            $name = bin2hex(random_bytes(20));
            $abs = rtrim($publicDirectory, '/') . '/' . $name . '.jpg';
            copy($path, $abs);
            $out[] = new NormalizedImage($name, $abs, 1080, $canvas === 'story' ? 1920 : 1350, 1.0);
        }

        return $out;
    }
}

final class FunnelPublisher implements InstagramPublisherInterface
{
    public function publish(string $igUserId, string $accessToken, array $imageUrls, string $caption, ?string $altText = null, ?string $containerId = null, ?string $mediaId = null, ?callable $checkpoint = null): PublishedMedia
    {
        return new PublishedMedia('c', 'm', 'https://instagram.com/p/x');
    }

    public function publishStory(string $igUserId, string $accessToken, string $mediaUrl, bool $video, ?string $containerId = null, ?string $mediaId = null, ?callable $checkpoint = null): PublishedMedia
    {
        return new PublishedMedia('c', 'm', 'https://instagram.com/stories/x');
    }

    public function publishReel(string $igUserId, string $accessToken, string $videoUrl, string $caption, ?string $containerId = null, ?string $mediaId = null, ?callable $checkpoint = null): PublishedMedia
    {
        return new PublishedMedia('c', 'm', 'https://instagram.com/p/x');
    }
}
