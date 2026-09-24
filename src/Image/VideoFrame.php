<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class VideoFrame
{
    public function capture(string $videoPath, string $jpegPath): bool
    {
        $bin = $this->ffmpeg();
        if ($bin === null || !is_file($videoPath)) {
            return false;
        }

        $dir = dirname($jpegPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $command = escapeshellarg($bin)
            . ' -y -ss 1 -i ' . escapeshellarg($videoPath)
            . ' -frames:v 1 -q:v 3 ' . escapeshellarg($jpegPath)
            . ' 2>/dev/null';
        exec($command, $output, $code);

        return $code === 0 && is_file($jpegPath) && (int) filesize($jpegPath) > 0;
    }

    private function ffmpeg(): ?string
    {
        if (!function_exists('exec') || !function_exists('shell_exec')) {
            return null;
        }

        $which = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
