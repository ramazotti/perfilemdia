<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Channel\ChannelInterface;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\OnboardingService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class OnboardingServiceTest extends TestCase
{
    private \PDO $pdo;
    private FakeChannel $channel;
    private UserRepository $users;
    private OnboardingService $onboarding;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
        $this->channel = new FakeChannel();
        $this->users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $this->onboarding = new OnboardingService($this->users, $this->channel);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testNewUserAdvancesStartToNameOnReceivingName(): void
    {
        $id = $this->users->create(910001, 910001, 'alice');
        $user = $this->users->find($id);
        $this->assertNotNull($user);
        $this->assertSame('start', $user['onboarding_step']);

        $handled = $this->onboarding->handleText($user, 910001, 'Maria');
        $this->assertTrue($handled);

        $fresh = $this->users->find($id);
        $this->assertNotNull($fresh);
        $this->assertSame('name', $fresh['onboarding_step']);
        $this->assertSame('Maria', $fresh['display_name']);
    }

    public function testUnexpectedEmptyNameDoesNotPersist(): void
    {
        $id = $this->users->create(910002, 910002, null);
        $user = $this->users->find($id);
        $this->assertNotNull($user);

        $handled = $this->onboarding->handleText($user, 910002, '   ');
        $this->assertTrue($handled);

        $fresh = $this->users->find($id);
        $this->assertNotNull($fresh);
        $this->assertSame('start', $fresh['onboarding_step']);
        $this->assertNull($fresh['display_name']);
    }
}

final class FakeChannel implements ChannelInterface
{
    /** @var list<array{type:string, chatId:int, text?:string}> */
    public array $sent = [];

    public function sendText(int $chatId, string $text, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'text', 'chatId' => $chatId, 'text' => $text];

        return count($this->sent);
    }

    public function sendVideo(int $chatId, string $videoPath, ?string $caption, ?array $buttons = null): int
    {
        return 1;
    }

    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int
    {
        $this->sent[] = ['type' => 'photo', 'chatId' => $chatId, 'text' => (string) $caption];

        return count($this->sent);
    }

    public function sendAlbum(int $chatId, array $photoPaths): void
    {
        $this->sent[] = ['type' => 'album', 'chatId' => $chatId];
    }

    public function editText(int $chatId, int $messageId, string $text, ?array $buttons = null): void
    {
        $this->sent[] = ['type' => 'editText', 'chatId' => $chatId, 'text' => $text];
    }

    public function editButtons(int $chatId, int $messageId, ?array $buttons): void
    {
        $this->sent[] = ['type' => 'editButtons', 'chatId' => $chatId];
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $this->sent[] = ['type' => 'answer', 'chatId' => 0, 'text' => (string) $text];
    }

    public function download(string $fileId, string $destPath): void
    {
    }
}
