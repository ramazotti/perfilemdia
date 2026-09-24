<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

interface ContactTransport
{
    /**
     * @return array{message_id: int}
     */
    public function sendMessage(int $chatId, string $text): array;

    /**
     * @return array{message_id: int}
     */
    public function forwardMessage(int $toChatId, int $fromChatId, int $messageId): array;

    /**
     * @return array{message_id: int}
     */
    public function copyMessage(int $toChatId, int $fromChatId, int $messageId): array;
}
