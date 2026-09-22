<?php

declare(strict_types=1);

namespace PerfilEmDia\Channel;

use CURLFile;
use PerfilEmDia\Telegram\TelegramClient;

final class TelegramChannel implements ChannelInterface
{
    private const CAPTION_LIMIT = 1024;

    public function __construct(private readonly TelegramClient $client)
    {
    }

    public function sendText(int $chatId, string $text, ?array $buttons = null): int
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($buttons !== null) {
            $params['reply_markup'] = $this->markup($buttons);
        }
        $result = $this->client->request('sendMessage', $params);

        return (int) ($result['message_id'] ?? 0);
    }

    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int
    {
        $captionTooLong = $caption !== null && mb_strlen($caption) > self::CAPTION_LIMIT;
        $params = [
            'chat_id' => $chatId,
            'photo' => $this->photoInput($photoPath),
        ];
        if ($caption !== null && !$captionTooLong) {
            $params['caption'] = $caption;
            if ($buttons !== null) {
                $params['reply_markup'] = $this->markup($buttons);
            }
        }

        $result = $this->client->request('sendPhoto', $params);
        $messageId = (int) ($result['message_id'] ?? 0);

        if ($captionTooLong) {
            return $this->sendText($chatId, (string) $caption, $buttons);
        }

        return $messageId;
    }

    public function sendAlbum(int $chatId, array $photoPaths): void
    {
        $media = [];
        $params = ['chat_id' => $chatId];
        $i = 0;
        foreach ($photoPaths as $path) {
            if (is_file($path)) {
                $attach = 'file' . $i;
                $media[] = [
                    'type' => 'photo',
                    'media' => 'attach://' . $attach,
                ];
                $params[$attach] = new CURLFile($path);
            } else {
                $media[] = [
                    'type' => 'photo',
                    'media' => $path,
                ];
            }
            $i++;
        }
        $params['media'] = $media;
        $this->client->request('sendMediaGroup', $params);
    }

    public function editText(int $chatId, int $messageId, string $text, ?array $buttons = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];
        if ($buttons !== null) {
            $params['reply_markup'] = $this->markup($buttons);
        } else {
            $params['reply_markup'] = ['inline_keyboard' => []];
        }
        $this->client->request('editMessageText', $params);
    }

    public function editButtons(int $chatId, int $messageId, ?array $buttons): void
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $buttons === null
                ? ['inline_keyboard' => []]
                : $this->markup($buttons),
        ];
        $this->client->request('editMessageReplyMarkup', $params);
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $params = ['callback_query_id' => $callbackId];
        if ($text !== null && $text !== '') {
            $params['text'] = $text;
        }
        $this->client->request('answerCallbackQuery', $params);
    }

    public function download(string $fileId, string $destPath): void
    {
        $this->client->download($fileId, $destPath);
    }

    /**
     * @param list<list<array{text:string, callback_data?:string, url?:string}>> $buttons
     * @return array{inline_keyboard: list<list<array{text:string, callback_data?:string, url?:string}>>}
     */
    private function markup(array $buttons): array
    {
        return ['inline_keyboard' => $buttons];
    }

    private function photoInput(string $photoPath): CURLFile|string
    {
        if (is_file($photoPath)) {
            return new CURLFile($photoPath);
        }

        return $photoPath;
    }
}
