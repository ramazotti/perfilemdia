<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PDO;
use PerfilEmDia\Channel\ChannelInterface;
use PerfilEmDia\Messages;
use PerfilEmDia\Telegram\Keyboards;

final class TicketService
{
    public const ANALISE = 'em_analise';
    public const ANDAMENTO = 'em_andamento';
    public const ENCERRADO = 'encerrado';

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
    ) {
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::ANALISE => 'Em análise',
            self::ANDAMENTO => 'Em andamento',
            self::ENCERRADO => 'Encerrado',
            default => $status,
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    public function showMenu(array $user, int $chatId, ChannelInterface $channel): void
    {
        $this->clearDraft($user);
        $rows = $this->forUser((int) $user['id']);
        $lines = '';
        $open = [];
        foreach ($rows as $row) {
            $lines .= '#' . $row['id'] . ', ' . self::label((string) $row['status']) . ': ' . $row['subject'] . "\n";
            if ((string) $row['status'] !== self::ENCERRADO && count($open) < 5) {
                $open[] = (int) $row['id'];
            }
        }
        $channel->sendText($chatId, Messages::ticketList(trim($lines)), Keyboards::ticketMenu($open));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleCallback(array $user, int $chatId, string $data, ChannelInterface $channel): void
    {
        if ($data === 'ch:menu') {
            $this->showMenu($user, $chatId, $channel);

            return;
        }
        if ($data === 'ch:sair') {
            $pending = (string) ($user['pending_action'] ?? '');
            $this->clearDraft($user);
            $channel->sendText($chatId, str_starts_with($pending, 'chamado:') && $pending !== 'chamado:novo'
                ? Messages::ticketDraftDropped()
                : Messages::ticketDraftCancelled());

            return;
        }
        if ($data === 'ch:novo') {
            $this->users->update((int) $user['id'], ['pending_action' => 'chamado:novo']);
            $channel->sendText($chatId, Messages::ticketAskNew(), Keyboards::ticketCompose());

            return;
        }
        if (preg_match('/^ch:ver:(\d+)$/', $data, $m) === 1) {
            $this->showOne($user, $chatId, (int) $m[1], $channel);

            return;
        }
        if (preg_match('/^ch:resp:(\d+)$/', $data, $m) === 1) {
            $ticket = $this->owned((int) $m[1], (int) $user['id']);
            if ($ticket === null) {
                $channel->sendText($chatId, Messages::ticketMissing());

                return;
            }
            if ((string) $ticket['status'] === self::ENCERRADO) {
                $channel->sendText($chatId, Messages::ticketClosed((int) $ticket['id']), Keyboards::ticketOpen((int) $ticket['id'], true));

                return;
            }
            $this->users->update((int) $user['id'], ['pending_action' => 'chamado:' . (int) $ticket['id']]);
            $channel->sendText($chatId, Messages::ticketAskReply((int) $ticket['id']), Keyboards::ticketCompose());
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function receive(array $user, int $chatId, string $text, ChannelInterface $channel): void
    {
        $pending = (string) ($user['pending_action'] ?? '');
        $body = $this->clean($text);
        if ($body === '') {
            $channel->sendText(
                $chatId,
                $pending === 'chamado:novo' ? Messages::ticketAskNew() : Messages::ticketAskReply($this->pendingId($pending)),
                Keyboards::ticketCompose(),
            );

            return;
        }

        if ($pending === 'chamado:novo') {
            $id = $this->open((int) $user['id'], $body);
            $this->clearDraft($user);
            $channel->sendText($chatId, Messages::ticketOpened($id));

            return;
        }

        $id = $this->pendingId($pending);
        if ($id === 0) {
            $this->clearDraft($user);

            return;
        }
        $saved = $this->addClientMessage($id, (int) $user['id'], $body);
        $this->clearDraft($user);
        $channel->sendText($chatId, $saved ? Messages::ticketNoted($id) : Messages::ticketClosed($id));
    }

    public function open(int $userId, string $body): int
    {
        $body = $this->clean($body);
        $subject = $this->subject($body);
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO tickets (user_id, subject, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
            );
            $insert->execute([$userId, $subject, self::ANALISE]);
            $id = (int) $this->pdo->lastInsertId();
            $this->addMessage($id, 'cliente', $body);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $id;
    }

    public function addClientMessage(int $ticketId, int $userId, string $body): bool
    {
        $ticket = $this->owned($ticketId, $userId);
        if ($ticket === null || (string) $ticket['status'] === self::ENCERRADO) {
            return false;
        }
        $body = $this->clean($body);
        if ($body === '') {
            return false;
        }
        $this->addMessage($ticketId, 'cliente', $body);
        $this->touch($ticketId, null);

        return true;
    }

    /**
     * Grava a resposta e, se o chamado estava em análise ou encerrado, passa para em andamento.
     *
     * @return array<string, mixed>|null
     */
    public function addAdminMessage(int $ticketId, string $body): ?array
    {
        $ticket = $this->find($ticketId);
        $body = $this->clean($body);
        if ($ticket === null || $body === '') {
            return null;
        }
        $this->addMessage($ticketId, 'admin', $body);
        $next = (string) $ticket['status'] === self::ANDAMENTO ? null : self::ANDAMENTO;
        $this->touch($ticketId, $next);
        $fresh = $this->find($ticketId);

        return $fresh;
    }

    public function setStatus(int $ticketId, string $status): bool
    {
        if (!in_array($status, [self::ANALISE, self::ANDAMENTO, self::ENCERRADO], true)) {
            return false;
        }
        if ($this->find($ticketId) === null) {
            return false;
        }
        $this->touch($ticketId, $status);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.display_name, u.telegram_username, u.telegram_chat_id
             FROM tickets t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messages(int $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT author, body, created_at FROM ticket_messages WHERE ticket_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$ticketId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, subject, status, updated_at FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT 8'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminList(string $filter): array
    {
        $sql = 'SELECT t.id, t.subject, t.status, t.updated_at, t.user_id,
                    u.display_name, u.telegram_username,
                    (SELECT c.id FROM customers c WHERE c.user_id = t.user_id AND c.status <> \'excluido\' ORDER BY c.id DESC LIMIT 1) AS customer_id,
                    (SELECT c.name FROM customers c WHERE c.user_id = t.user_id AND c.status <> \'excluido\' ORDER BY c.id DESC LIMIT 1) AS customer_name
                FROM tickets t
                INNER JOIN users u ON u.id = t.user_id';
        $args = [];
        if ($filter === 'abertos') {
            $sql .= ' WHERE t.status <> ?';
            $args[] = self::ENCERRADO;
        } elseif (in_array($filter, [self::ANALISE, self::ANDAMENTO, self::ENCERRADO], true)) {
            $sql .= ' WHERE t.status = ?';
            $args[] = $filter;
        }
        $sql .= ' ORDER BY FIELD(t.status, \'em_analise\', \'em_andamento\', \'encerrado\'), t.updated_at DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $user
     */
    private function showOne(array $user, int $chatId, int $ticketId, ChannelInterface $channel): void
    {
        $ticket = $this->owned($ticketId, (int) $user['id']);
        if ($ticket === null) {
            $channel->sendText($chatId, Messages::ticketMissing());

            return;
        }
        $thread = '';
        foreach ($this->messages($ticketId) as $message) {
            $who = (string) $message['author'] === 'admin' ? 'Suporte' : 'Você';
            $thread .= $who . ":\n" . (string) $message['body'] . "\n\n";
        }
        if (mb_strlen($thread) > 3200) {
            $thread = '...' . mb_substr($thread, -3200);
        }
        $closed = (string) $ticket['status'] === self::ENCERRADO;
        $channel->sendText(
            $chatId,
            Messages::ticketView($ticketId, self::label((string) $ticket['status']), trim($thread)),
            Keyboards::ticketOpen($ticketId, $closed),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function owned(int $ticketId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tickets WHERE id = ? AND user_id = ?');
        $stmt->execute([$ticketId, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function addMessage(int $ticketId, string $author, string $body): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_messages (ticket_id, author, body, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$ticketId, $author, $body]);
    }

    private function touch(int $ticketId, ?string $status): void
    {
        if ($status === null) {
            $this->pdo->prepare('UPDATE tickets SET updated_at = NOW() WHERE id = ?')->execute([$ticketId]);

            return;
        }
        $this->pdo->prepare('UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $ticketId]);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function clearDraft(array $user): void
    {
        if (!str_starts_with((string) ($user['pending_action'] ?? ''), 'chamado:')) {
            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => null]);
    }

    private function pendingId(string $pending): int
    {
        if (preg_match('/^chamado:(\d+)$/', $pending, $m) !== 1) {
            return 0;
        }

        return (int) $m[1];
    }

    private function clean(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        if (mb_strlen($body) > 3500) {
            $body = mb_substr($body, 0, 3500);
        }

        return $body;
    }

    private function subject(string $body): string
    {
        $line = preg_split("/\R/u", $body)[0] ?? $body;
        $line = trim((string) $line);
        if (mb_strlen($line) > 120) {
            $line = mb_substr($line, 0, 117) . '...';
        }

        return $line;
    }
}
