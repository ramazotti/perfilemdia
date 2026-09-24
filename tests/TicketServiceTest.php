<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Channel\ChannelInterface;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\TicketService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class TicketServiceTest extends TestCase
{
    private \PDO $pdo;
    private UserRepository $users;
    private TicketService $tickets;
    private TicketChannel $channel;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
        $this->users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $this->tickets = new TicketService($this->pdo, $this->users);
        $this->channel = new TicketChannel();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testOpenStartsInAnalysisAndKeepsTheFirstLineAsSubject(): void
    {
        $userId = $this->users->create(980001, 980001, 'ana');
        $id = $this->tickets->open($userId, "Instagram não conecta\nJá tentei /conectar");

        $ticket = $this->tickets->find($id);
        $this->assertNotNull($ticket);
        $this->assertSame(TicketService::ANALISE, $ticket['status']);
        $this->assertSame('Instagram não conecta', $ticket['subject']);
        $messages = $this->tickets->messages($id);
        $this->assertCount(1, $messages);
        $this->assertSame('cliente', $messages[0]['author']);
    }

    public function testClientCanAddToAnOpenTicketAndNotToAClosedOne(): void
    {
        $userId = $this->users->create(980002, 980002, 'bia');
        $id = $this->tickets->open($userId, 'Cobrança estranha');
        $this->assertTrue($this->tickets->addClientMessage($id, $userId, 'O valor veio dobrado'));
        $this->assertCount(2, $this->tickets->messages($id));

        $this->tickets->setStatus($id, TicketService::ENCERRADO);
        $this->assertFalse($this->tickets->addClientMessage($id, $userId, 'Ainda está errado'));
        $this->assertCount(2, $this->tickets->messages($id));
    }

    public function testAnotherUserCannotWriteOnTheTicket(): void
    {
        $owner = $this->users->create(980003, 980003, 'caio');
        $other = $this->users->create(980004, 980004, 'duda');
        $id = $this->tickets->open($owner, 'Meu post não saiu');

        $this->assertFalse($this->tickets->addClientMessage($id, $other, 'oi'));
        $this->assertCount(1, $this->tickets->messages($id));
    }

    public function testAdminReplyMovesAnalysisAndClosedTicketsToInProgress(): void
    {
        $userId = $this->users->create(980005, 980005, 'eva');
        $id = $this->tickets->open($userId, 'Quero cancelar');

        $fresh = $this->tickets->addAdminMessage($id, 'Pode usar /assinatura e tocar em Cancelar.');
        $this->assertNotNull($fresh);
        $this->assertSame(TicketService::ANDAMENTO, $fresh['status']);
        $this->assertSame('admin', $this->tickets->messages($id)[1]['author']);

        $this->tickets->setStatus($id, TicketService::ENCERRADO);
        $reopened = $this->tickets->addAdminMessage($id, 'Reabri para ver o comprovante.');
        $this->assertNotNull($reopened);
        $this->assertSame(TicketService::ANDAMENTO, $reopened['status']);
    }

    public function testReceiveFromTheBotOpensAndThenAppends(): void
    {
        $userId = $this->users->create(980006, 980006, 'felipe');
        $user = $this->users->find($userId);
        $this->assertNotNull($user);
        $this->users->update($userId, ['pending_action' => 'chamado:novo']);
        $user['pending_action'] = 'chamado:novo';

        $this->tickets->receive($user, 980006, 'A foto cortou o produto', $this->channel);

        $this->assertNotSame('', $this->channel->last());
        $saved = $this->users->find($userId);
        $this->assertNotNull($saved);
        $this->assertNull($saved['pending_action']);
        $list = $this->tickets->forUser($userId);
        $this->assertCount(1, $list);
        $this->assertSame(TicketService::ANALISE, $list[0]['status']);

        $id = (int) $list[0]['id'];
        $user = $saved;
        $this->users->update($userId, ['pending_action' => 'chamado:' . $id]);
        $user['pending_action'] = 'chamado:' . $id;
        $this->tickets->receive($user, 980006, 'Mandei de novo e cortou igual', $this->channel);
        $this->assertCount(2, $this->tickets->messages($id));
    }

    public function testDeletingTheAccountRemovesTickets(): void
    {
        $userId = $this->users->create(980007, 980007, 'gabi');
        $id = $this->tickets->open($userId, 'Apaga com a conta');
        $this->users->deleteAccount($userId);

        $this->assertNull($this->tickets->find($id));
        $this->assertSame([], $this->tickets->messages($id));
    }
}

final class TicketChannel implements ChannelInterface
{
    public string $text = '';

    public function sendText(int $chatId, string $text, ?array $buttons = null): int
    {
        $this->text = $text;

        return 1;
    }

    public function last(): string
    {
        return $this->text;
    }

    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int
    {
        return 1;
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
