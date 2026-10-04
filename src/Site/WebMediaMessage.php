<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PerfilEmDia\Channel\WebStudioChannel;

final class WebMediaMessage
{
    /**
     * @return array<string, mixed>
     */
    public static function fromUpload(string $absolutePath, string $mime, ?string $caption = null): array
    {
        $fileId = WebStudioChannel::FILE_PREFIX . $absolutePath;
        $size = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;
        $message = [
            'message_id' => random_int(1, 2_000_000_000),
        ];
        if ($caption !== null && trim($caption) !== '') {
            $message['caption'] = trim($caption);
        }
        if (str_starts_with($mime, 'video/')) {
            $message['video'] = [
                'file_id' => $fileId,
                'mime_type' => $mime,
                'file_size' => $size,
                'width' => 0,
                'height' => 0,
                'duration' => 0,
            ];

            return $message;
        }
        $message['photo'] = [[
            'file_id' => $fileId,
            'file_size' => $size,
            'width' => 0,
            'height' => 0,
        ]];

        return $message;
    }
}
