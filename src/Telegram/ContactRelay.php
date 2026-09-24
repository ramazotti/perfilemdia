<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

final class ContactRelay
{
    public const WELCOME = 'Oi. Este é o contato do Perfil em Dia. Escreva sua dúvida aqui que a resposta volta nesta conversa.';

    public const HOLDING = 'Recebi sua mensagem. O atendimento do Perfil em Dia responde por aqui assim que estiver disponível.';

    public const LINKED = 'Pronto. As mensagens de quem escrever neste bot chegam neste chat. Para responder, mantenha o dedo na mensagem da pessoa e escolha Responder.';

    public const HINT = 'Para a resposta chegar na pessoa, mantenha o dedo na mensagem dela e escolha Responder.';

    public const BAD_CODE = 'Não reconheci esse código.';

    public const UNKNOWN_REPLY = 'Não achei a pessoa dessa mensagem. Responda a mensagem que eu encaminhei.';

    /**
     * @param array<string, mixed> $update
     * @return list<array<string, int|string>>
     */
    public static function about(string $productBot): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', ltrim($productBot, '@')) ?? '';
        if ($name === '') {
            $name = 'PerfilEmDiaBot';
        }

        return 'Olá. Este bot é para falar sobre planos, dúvidas ou problemas. Se quiser mandar foto e postar no Instagram, acesse @' . $name . '.';
    }

    public function decide(array $update, ?int $adminChatId, string $linkCode, bool $alreadyTold = false, string $productBot = 'PerfilEmDiaBot'): array
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return [];
        }

        $chat = $message['chat'] ?? null;
        if (!is_array($chat) || ($chat['type'] ?? '') !== 'private') {
            return [];
        }

        $from = $message['from'] ?? null;
        if (is_array($from) && ($from['is_bot'] ?? false) === true) {
            return [];
        }

        $chatId = (int) ($chat['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId === 0 || $messageId === 0) {
            return [];
        }

        $isAdmin = $adminChatId !== null && $chatId === $adminChatId;
        $text = isset($message['text']) ? trim((string) $message['text']) : '';
        $command = $this->command($text);
        if ($command !== null) {
            return $this->commandActions($command, $chatId, $isAdmin, $adminChatId, $linkCode, $message, $alreadyTold, $productBot);
        }

        if ($isAdmin) {
            $replyId = $this->replyId($message);
            if ($replyId > 0) {
                return [[
                    'type' => 'copy',
                    'message_id' => $messageId,
                    'reply_to_message_id' => $replyId,
                ]];
            }
            if ($text === '' && !$this->hasContent($message)) {
                return [];
            }

            return [['type' => 'send', 'chat_id' => $chatId, 'text' => self::HINT]];
        }

        return $this->customerTurn($chatId, $messageId, $adminChatId, $alreadyTold, $productBot);
    }

    /**
     * @param array{name: string, arg: string} $command
     * @param array<string, mixed> $message
     * @return list<array<string, int|string>>
     */
    private function commandActions(
        array $command,
        int $chatId,
        bool $isAdmin,
        ?int $adminChatId,
        string $linkCode,
        array $message,
        bool $alreadyTold,
        string $productBot,
    ): array {
        if ($command['arg'] !== '') {
            if ($this->codeMatches($command['arg'], $linkCode)) {
                return [
                    ['type' => 'link', 'chat_id' => $chatId],
                    ['type' => 'send', 'chat_id' => $chatId, 'text' => self::LINKED],
                ];
            }

            return [['type' => 'send', 'chat_id' => $chatId, 'text' => self::BAD_CODE]];
        }

        if ($command['name'] === 'vincular') {
            return [['type' => 'send', 'chat_id' => $chatId, 'text' => self::BAD_CODE]];
        }

        if ($isAdmin) {
            return [['type' => 'send', 'chat_id' => $chatId, 'text' => self::LINKED]];
        }

        $actions = [
            ['type' => 'send', 'chat_id' => $chatId, 'text' => self::about($productBot)],
        ];
        if (!$alreadyTold) {
            $actions[] = ['type' => 'greet', 'chat_id' => $chatId];
        }
        if ($adminChatId !== null) {
            $actions[] = [
                'type' => 'notice',
                'text' => $this->opened($message),
                'customer_chat_id' => $chatId,
            ];
        }

        return $actions;
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function customerTurn(int $chatId, int $messageId, ?int $adminChatId, bool $alreadyTold, string $productBot): array
    {
        $actions = [];
        if (!$alreadyTold && $chatId !== 0) {
            $actions[] = ['type' => 'send', 'chat_id' => $chatId, 'text' => self::about($productBot)];
            $actions[] = ['type' => 'greet', 'chat_id' => $chatId];
        } elseif ($adminChatId === null && $chatId !== 0) {
            $actions[] = ['type' => 'send', 'chat_id' => $chatId, 'text' => self::HOLDING];
        }

        if ($adminChatId !== null && $messageId !== 0) {
            $actions[] = [
                'type' => 'forward',
                'from_chat_id' => $chatId,
                'message_id' => $messageId,
            ];
        }

        return $actions;
    }

    /**
     * @return array{name: string, arg: string}|null
     */
    private function command(string $text): ?array
    {
        if ($text === '' || !str_starts_with($text, '/')) {
            return null;
        }

        $parts = preg_split('/\s+/', $text, 2) ?: [];
        $head = (string) ($parts[0] ?? '');
        $name = strtolower((string) preg_replace('/@.*$/', '', substr($head, 1)));
        if ($name !== 'start' && $name !== 'vincular') {
            return null;
        }

        return [
            'name' => $name,
            'arg' => trim((string) ($parts[1] ?? '')),
        ];
    }

    private function codeMatches(string $given, string $expected): bool
    {
        if ($expected === '' || strlen($given) !== strlen($expected)) {
            return false;
        }

        return hash_equals($expected, $given);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function replyId(array $message): int
    {
        $reply = $message['reply_to_message'] ?? null;
        if (!is_array($reply)) {
            return 0;
        }

        return (int) ($reply['message_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function hasContent(array $message): bool
    {
        foreach (['photo', 'video', 'voice', 'audio', 'document', 'sticker', 'animation', 'video_note', 'contact', 'location', 'venue'] as $key) {
            if (isset($message[$key])) {
                return true;
            }
        }

        return isset($message['caption']) && trim((string) $message['caption']) !== '';
    }

    /**
     * @param array<string, mixed> $message
     */
    private function opened(array $message): string
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $name = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));
        if ($name === '') {
            $name = 'Alguém';
        }
        $username = trim((string) ($from['username'] ?? ''));
        $who = $username !== '' ? $name . ' (@' . $username . ')' : $name;

        return $who . ' abriu o contato do Perfil em Dia.';
    }
}
