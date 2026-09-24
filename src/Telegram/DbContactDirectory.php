<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

use PDO;
use PerfilEmDia\Billing\Settings;

final class DbContactDirectory implements ContactDirectory
{
    public function __construct(private readonly PDO $pdo, private readonly string $linkCode)
    {
    }

    public function adminChatId(): ?int
    {
        $value = Settings::get('contact_admin_chat_id', '');
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    public function linkCode(): string
    {
        return $this->linkCode;
    }

    public function saveAdminChatId(int $chatId): void
    {
        Settings::set($this->pdo, 'contact_admin_chat_id', (string) $chatId);
    }

    public function customerChatId(int $adminChatId, int $adminMessageId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT customer_chat_id FROM contact_routes WHERE admin_chat_id = ? AND admin_message_id = ?'
        );
        $stmt->execute([$adminChatId, $adminMessageId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        return (int) $value;
    }

    public function remember(int $adminChatId, int $adminMessageId, int $customerChatId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO contact_routes (admin_chat_id, admin_message_id, customer_chat_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE customer_chat_id = VALUES(customer_chat_id)'
        );
        $stmt->execute([$adminChatId, $adminMessageId, $customerChatId]);
    }

    public function alreadyTold(int $customerChatId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM contact_greetings WHERE customer_chat_id = ?');
        $stmt->execute([$customerChatId]);

        return $stmt->fetchColumn() !== false;
    }

    public function markTold(int $customerChatId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO contact_greetings (customer_chat_id) VALUES (?)
             ON DUPLICATE KEY UPDATE customer_chat_id = customer_chat_id'
        );
        $stmt->execute([$customerChatId]);
    }
}
