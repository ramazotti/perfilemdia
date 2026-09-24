<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

final class TelegramContactTransport implements ContactTransport
{
    public function __construct(private readonly TelegramClient $client)
    {
    }

    public function sendMessage(int $chatId, string $text): array
    {
        $result = $this->client->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);

        return ['message_id' => (int) ($result['message_id'] ?? 0)];
    }

    public function forwardMessage(int $toChatId, int $fromChatId, int $messageId): array
    {
        $result = $this->client->request('forwardMessage', [
            'chat_id' => $toChatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ]);

        return ['message_id' => (int) ($result['message_id'] ?? 0)];
    }

    public function copyMessage(int $toChatId, int $fromChatId, int $messageId): array
    {
        $result = $this->client->request('copyMessage', [
            'chat_id' => $toChatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ]);

        return ['message_id' => (int) ($result['message_id'] ?? 0)];
    }
}
