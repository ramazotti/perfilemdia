<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

final class ContactInbox
{
    public function __construct(
        private readonly ContactRelay $relay,
        private readonly ContactTransport $transport,
        private readonly ContactDirectory $directory,
        private readonly string $productBot = 'PerfilEmDiaBot',
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function handle(array $update): void
    {
        $adminChatId = $this->directory->adminChatId();
        $customerChatId = $this->customerChatId($update);
        $alreadyTold = $customerChatId !== null && $this->directory->alreadyTold($customerChatId);
        $actions = $this->relay->decide($update, $adminChatId, $this->directory->linkCode(), $alreadyTold, $this->productBot);
        foreach ($actions as $action) {
            $adminChatId = $this->apply($action, $adminChatId);
        }
    }

    /**
     * @param array<string, int|string> $action
     */
    private function apply(array $action, ?int $adminChatId): ?int
    {
        $type = (string) $action['type'];
        if ($type === 'send') {
            $this->transport->sendMessage((int) $action['chat_id'], (string) $action['text']);

            return $adminChatId;
        }

        if ($type === 'greet') {
            $this->directory->markTold((int) $action['chat_id']);

            return $adminChatId;
        }

        if ($type === 'link') {
            $chatId = (int) $action['chat_id'];
            $this->directory->saveAdminChatId($chatId);

            return $chatId;
        }

        if ($type === 'forward') {
            if ($adminChatId === null) {
                return null;
            }
            $sent = $this->transport->forwardMessage(
                $adminChatId,
                (int) $action['from_chat_id'],
                (int) $action['message_id'],
            );
            $this->remember($adminChatId, (int) ($sent['message_id'] ?? 0), (int) $action['from_chat_id']);

            return $adminChatId;
        }

        if ($type === 'notice') {
            if ($adminChatId === null) {
                return null;
            }
            $sent = $this->transport->sendMessage($adminChatId, (string) $action['text']);
            $this->remember($adminChatId, (int) ($sent['message_id'] ?? 0), (int) $action['customer_chat_id']);

            return $adminChatId;
        }

        if ($type === 'copy') {
            if ($adminChatId === null) {
                return null;
            }
            $customerChatId = $this->directory->customerChatId($adminChatId, (int) $action['reply_to_message_id']);
            if ($customerChatId === null) {
                $this->transport->sendMessage($adminChatId, ContactRelay::UNKNOWN_REPLY);

                return $adminChatId;
            }
            $this->transport->copyMessage($customerChatId, $adminChatId, (int) $action['message_id']);
        }

        return $adminChatId;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function customerChatId(array $update): ?int
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }
        $chat = $message['chat'] ?? null;
        if (!is_array($chat) || ($chat['type'] ?? '') !== 'private') {
            return null;
        }
        $chatId = (int) ($chat['id'] ?? 0);
        if ($chatId === 0) {
            return null;
        }
        $adminChatId = $this->directory->adminChatId();
        if ($adminChatId !== null && $chatId === $adminChatId) {
            return null;
        }

        return $chatId;
    }

    private function remember(int $adminChatId, int $adminMessageId, int $customerChatId): void
    {
        if ($adminMessageId <= 0 || $customerChatId === 0) {
            return;
        }

        $this->directory->remember($adminChatId, $adminMessageId, $customerChatId);
    }
}
