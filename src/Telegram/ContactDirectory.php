<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

interface ContactDirectory
{
    public function adminChatId(): ?int;

    public function linkCode(): string;

    public function saveAdminChatId(int $chatId): void;

    public function customerChatId(int $adminChatId, int $adminMessageId): ?int;

    public function remember(int $adminChatId, int $adminMessageId, int $customerChatId): void;

    public function alreadyTold(int $customerChatId): bool;

    public function markTold(int $customerChatId): void;
}
