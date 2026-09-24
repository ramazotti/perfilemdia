<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Telegram\ContactRelay;
use PHPUnit\Framework\TestCase;

final class ContactRelayTest extends TestCase
{
    private ContactRelay $relay;

    protected function setUp(): void
    {
        $this->relay = new ContactRelay();
    }

    public function testCustomerMessageIsForwardedWhenAdminExists(): void
    {
        $actions = $this->relay->decide($this->textUpdate(50, 7, 'Oi, preciso de ajuda'), 99, 'segredo');

        $this->assertSame([
            ['type' => 'send', 'chat_id' => 50, 'text' => ContactRelay::about('PerfilEmDiaBot')],
            ['type' => 'greet', 'chat_id' => 50],
            ['type' => 'forward', 'from_chat_id' => 50, 'message_id' => 7],
        ], $actions);
    }

    public function testALaterMessageOnlyForwards(): void
    {
        $actions = $this->relay->decide($this->textUpdate(50, 8, 'Meu Pix nao caiu'), 99, 'segredo', true);

        $this->assertSame([
            ['type' => 'forward', 'from_chat_id' => 50, 'message_id' => 8],
        ], $actions);
    }

    public function testCustomerMessageWaitsWhenNobodyIsLinked(): void
    {
        $actions = $this->relay->decide($this->textUpdate(50, 7, 'Oi'), null, 'segredo');

        $this->assertSame([
            ['type' => 'send', 'chat_id' => 50, 'text' => ContactRelay::about('PerfilEmDiaBot')],
            ['type' => 'greet', 'chat_id' => 50],
        ], $actions);
    }

    public function testStartWelcomesTheCustomerAndNotifiesTheAdmin(): void
    {
        $update = $this->textUpdate(50, 3, '/start', 'Ana', 'anafoto');
        $actions = $this->relay->decide($update, 99, 'segredo');

        $this->assertSame(ContactRelay::about('PerfilEmDiaBot'), $actions[0]['text']);
        $this->assertSame('greet', $actions[1]['type']);
        $this->assertSame('notice', $actions[2]['type']);
        $this->assertSame('Ana (@anafoto) abriu o contato do Perfil em Dia.', $actions[2]['text']);
        $this->assertSame(50, $actions[2]['customer_chat_id']);
    }

    public function testLinkCodeBindsThePersonalChat(): void
    {
        $actions = $this->relay->decide($this->textUpdate(99, 1, '/vincular segredo'), null, 'segredo');

        $this->assertSame('link', $actions[0]['type']);
        $this->assertSame(99, $actions[0]['chat_id']);
        $this->assertSame(ContactRelay::LINKED, $actions[1]['text']);
    }

    public function testWrongCodeDoesNotLink(): void
    {
        $actions = $this->relay->decide($this->textUpdate(50, 1, '/start outro'), null, 'segredo');

        $this->assertSame([
            ['type' => 'send', 'chat_id' => 50, 'text' => ContactRelay::BAD_CODE],
        ], $actions);
    }

    public function testEmptyCodeNeverLinks(): void
    {
        $actions = $this->relay->decide($this->textUpdate(99, 1, '/vincular segredo'), null, '');

        $this->assertSame([
            ['type' => 'send', 'chat_id' => 99, 'text' => ContactRelay::BAD_CODE],
        ], $actions);
    }

    public function testAdminReplyIsCopiedBack(): void
    {
        $update = $this->textUpdate(99, 15, 'Pode deixar');
        $update['message']['reply_to_message'] = ['message_id' => 8];

        $actions = $this->relay->decide($update, 99, 'segredo');

        $this->assertSame([
            ['type' => 'copy', 'message_id' => 15, 'reply_to_message_id' => 8],
        ], $actions);
    }

    public function testAdminMessageWithoutReplyAsksToReply(): void
    {
        $actions = $this->relay->decide($this->textUpdate(99, 16, 'oi'), 99, 'segredo');

        $this->assertSame([
            ['type' => 'send', 'chat_id' => 99, 'text' => ContactRelay::HINT],
        ], $actions);
    }

    public function testGroupMessagesAreIgnored(): void
    {
        $update = $this->textUpdate(50, 7, 'oi');
        $update['message']['chat']['type'] = 'group';

        $this->assertSame([], $this->relay->decide($update, 99, 'segredo'));
    }

    /**
     * @return array<string, mixed>
     */
    private function textUpdate(int $chatId, int $messageId, string $text, string $firstName = 'Ana', string $username = ''): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => $messageId,
                'text' => $text,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => $firstName,
                    'username' => $username,
                ],
            ],
        ];
    }
}
