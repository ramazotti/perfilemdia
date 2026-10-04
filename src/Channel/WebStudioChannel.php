<?php

declare(strict_types=1);

namespace PerfilEmDia\Channel;

use RuntimeException;

/**
 * Saída do PostService na web: mensagens ficam na outbox em vez do Telegram.
 * file_id com prefixo webfile: aponta para arquivo local no servidor.
 */
final class WebStudioChannel implements ChannelInterface
{
    public const FILE_PREFIX = 'webfile:';

    /** @var list<array<string, mixed>> */
    private array $outbox = [];

    public function sendText(int $chatId, string $text, ?array $buttons = null): int
    {
        $id = count($this->outbox) + 1;
        $this->outbox[] = [
            'type' => 'text',
            'id' => $id,
            'text' => $text,
            'buttons' => $this->mapButtons($buttons),
        ];

        return $id;
    }

    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int
    {
        $id = count($this->outbox) + 1;
        $this->outbox[] = [
            'type' => 'photo',
            'id' => $id,
            'path' => $photoPath,
            'caption' => $caption ?? '',
            'buttons' => $this->mapButtons($buttons),
        ];

        return $id;
    }

    public function sendVideo(int $chatId, string $videoPath, ?string $caption, ?array $buttons = null): int
    {
        $id = count($this->outbox) + 1;
        $this->outbox[] = [
            'type' => 'video',
            'id' => $id,
            'path' => $videoPath,
            'caption' => $caption ?? '',
            'buttons' => $this->mapButtons($buttons),
        ];

        return $id;
    }

    /**
     * @param list<string> $photoPaths
     */
    public function sendAlbum(int $chatId, array $photoPaths): void
    {
        $this->outbox[] = [
            'type' => 'album',
            'paths' => $photoPaths,
        ];
    }

    public function editText(int $chatId, int $messageId, string $text, ?array $buttons = null): void
    {
        $this->outbox[] = [
            'type' => 'edit_text',
            'message_id' => $messageId,
            'text' => $text,
            'buttons' => $this->mapButtons($buttons),
        ];
    }

    public function editButtons(int $chatId, int $messageId, ?array $buttons): void
    {
        $this->outbox[] = [
            'type' => 'edit_buttons',
            'message_id' => $messageId,
            'buttons' => $this->mapButtons($buttons),
        ];
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
    }

    public function download(string $fileId, string $destPath): void
    {
        if (!str_starts_with($fileId, self::FILE_PREFIX)) {
            throw new RuntimeException('Arquivo da web inválido.');
        }
        $source = substr($fileId, strlen(self::FILE_PREFIX));
        if ($source === '' || str_contains($source, '..') || !is_file($source)) {
            throw new RuntimeException('Arquivo não encontrado.');
        }
        $dir = dirname($destPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível preparar a pasta.');
        }
        if (!copy($source, $destPath)) {
            throw new RuntimeException('Não foi possível copiar o arquivo.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function drainOutbox(): array
    {
        $items = $this->outbox;
        $this->outbox = [];

        return $items;
    }

    /**
     * @param list<list<array{text:string, callback_data?:string, url?:string}>>|null $buttons
     * @return list<list<array{text:string, data?:string, url?:string}>>
     */
    private function mapButtons(?array $buttons): array
    {
        if ($buttons === null) {
            return [];
        }
        $rows = [];
        foreach ($buttons as $row) {
            $mapped = [];
            foreach ($row as $button) {
                $item = ['text' => (string) ($button['text'] ?? '')];
                if (!empty($button['callback_data'])) {
                    $item['data'] = (string) $button['callback_data'];
                }
                if (!empty($button['url'])) {
                    $item['url'] = (string) $button['url'];
                }
                $mapped[] = $item;
            }
            if ($mapped !== []) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }
}
