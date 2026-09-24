<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Telegram\ContactDirectory;
use PerfilEmDia\Telegram\ContactInbox;
use PerfilEmDia\Telegram\ContactRelay;
use PerfilEmDia\Telegram\ContactTransport;
use PHPUnit\Framework\TestCase;

final class ContactInboxTest extends TestCase
{
    public function testForwardRemembersTheRouteToTheCustomer(): void
    {
        $transport = new FakeContactTransport();
        $directory = new FakeContactDirectory();
        $directory->admin = 99;
        $inbox = new ContactInbox(new ContactRelay(), $transport, $directory);

        $inbox->handle([
            'update_id' => 1,
            'message' => [
                'message_id' => 7,
                'text' => 'Minha foto não publicou',
                'chat' => ['id' => 50, 'type' => 'private'],
                'from' => ['id' => 50, 'is_bot' => false, 'first_name' => 'Ana'],
            ],
        ]);

        $this->assertSame('send', $transport->calls[0][0]);
        $this->assertSame(ContactRelay::about('PerfilEmDiaBot'), $transport->calls[0][2]);
        $this->assertSame(['forward', 99, 50, 7], $transport->calls[1]);
        $this->assertTrue($directory->alreadyTold(50));
        $this->assertSame(50, $directory->customerChatId(99, 11));
    }

    public function testAdminReplyIsCopiedToTheCustomer(): void
    {
        $transport = new FakeContactTransport();
        $directory = new FakeContactDirectory();
        $directory->admin = 99;
        $directory->remember(99, 10, 50);
        $inbox = new ContactInbox(new ContactRelay(), $transport, $directory);

        $inbox->handle([
            'update_id' => 2,
            'message' => [
                'message_id' => 15,
                'text' => 'Já vou ver',
                'chat' => ['id' => 99, 'type' => 'private'],
                'from' => ['id' => 99, 'is_bot' => false, 'first_name' => 'Evan'],
                'reply_to_message' => ['message_id' => 10],
            ],
        ]);

        $this->assertSame(['copy', 50, 99, 15], $transport->calls[0]);
    }

    public function testLinkCodeSavesThePersonalChat(): void
    {
        $transport = new FakeContactTransport();
        $directory = new FakeContactDirectory();
        $inbox = new ContactInbox(new ContactRelay(), $transport, $directory);

        $inbox->handle([
            'update_id' => 3,
            'message' => [
                'message_id' => 1,
                'text' => '/vincular segredo',
                'chat' => ['id' => 99, 'type' => 'private'],
                'from' => ['id' => 99, 'is_bot' => false, 'first_name' => 'Evan'],
            ],
        ]);

        $this->assertSame(99, $directory->adminChatId());
        $this->assertSame(ContactRelay::LINKED, $transport->calls[0][2]);
    }
}

final class FakeContactTransport implements ContactTransport
{
    /** @var list<array<int, int|string>> */
    public array $calls = [];

    private int $nextId = 10;

    public function sendMessage(int $chatId, string $text): array
    {
        $id = $this->nextId++;
        $this->calls[] = ['send', $chatId, $text, $id];

        return ['message_id' => $id];
    }

    public function forwardMessage(int $toChatId, int $fromChatId, int $messageId): array
    {
        $id = $this->nextId++;
        $this->calls[] = ['forward', $toChatId, $fromChatId, $messageId];

        return ['message_id' => $id];
    }

    public function copyMessage(int $toChatId, int $fromChatId, int $messageId): array
    {
        $this->calls[] = ['copy', $toChatId, $fromChatId, $messageId];

        return ['message_id' => $this->nextId++];
    }
}

final class FakeContactDirectory implements ContactDirectory
{
    public ?int $admin = null;

    public string $code = 'segredo';

    /** @var array<string, int> */
    public array $routes = [];

    /** @var array<int, true> */
    public array $told = [];

    public function adminChatId(): ?int
    {
        return $this->admin;
    }

    public function linkCode(): string
    {
        return $this->code;
    }

    public function saveAdminChatId(int $chatId): void
    {
        $this->admin = $chatId;
    }

    public function customerChatId(int $adminChatId, int $adminMessageId): ?int
    {
        return $this->routes[$adminChatId . ':' . $adminMessageId] ?? null;
    }

    public function remember(int $adminChatId, int $adminMessageId, int $customerChatId): void
    {
        $this->routes[$adminChatId . ':' . $adminMessageId] = $customerChatId;
    }

    public function alreadyTold(int $customerChatId): bool
    {
        return isset($this->told[$customerChatId]);
    }

    public function markTold(int $customerChatId): void
    {
        $this->told[$customerChatId] = true;
    }
}
