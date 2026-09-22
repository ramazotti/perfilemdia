<?php

declare(strict_types=1);

namespace PerfilEmDia\Channel;

interface ChannelInterface
{
    /**
     * @param list<list<array{text:string, callback_data?:string, url?:string}>>|null $buttons
     */
    public function sendText(int $chatId, string $text, ?array $buttons = null): int;

    /**
     * @param list<list<array{text:string, callback_data?:string, url?:string}>>|null $buttons
     */
    public function sendPhoto(int $chatId, string $photoPath, ?string $caption, ?array $buttons = null): int;

    /**
     * @param list<string> $photoPaths
     */
    public function sendAlbum(int $chatId, array $photoPaths): void;

    /**
     * @param list<list<array{text:string, callback_data?:string, url?:string}>>|null $buttons
     */
    public function editText(int $chatId, int $messageId, string $text, ?array $buttons = null): void;

    /**
     * @param list<list<array{text:string, callback_data?:string, url?:string}>>|null $buttons
     */
    public function editButtons(int $chatId, int $messageId, ?array $buttons): void;

    public function answerCallback(string $callbackId, ?string $text = null): void;

    public function download(string $fileId, string $destPath): void;
}
