<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

final class SpeechDuration
{
    public static function seconds(string $bytes, string $format): ?int
    {
        if ($bytes === '') {
            return null;
        }
        $seconds = match ($format) {
            'wav' => self::wav($bytes),
            'ogg' => self::ogg($bytes),
            'mp3' => self::mp3($bytes),
            'm4a' => self::mp4($bytes),
            default => null,
        };
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return $seconds;
    }

    private static function wav(string $bytes): ?int
    {
        if (strlen($bytes) < 44 || !str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WAVE') {
            return null;
        }
        $offset = 12;
        $length = strlen($bytes);
        $byteRate = null;
        $dataSize = null;
        while ($offset + 8 <= $length) {
            $id = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4));
            $chunk = is_array($size) ? (int) $size[1] : 0;
            $offset += 8;
            if ($id === 'fmt ' && $chunk >= 16 && $offset + 12 <= $length) {
                $rate = unpack('V', substr($bytes, $offset + 8, 4));
                $byteRate = is_array($rate) ? (int) $rate[1] : 0;
            }
            if ($id === 'data') {
                $dataSize = $chunk;
                break;
            }
            $offset += $chunk + ($chunk % 2);
        }
        if ($byteRate === null || $byteRate < 1 || $dataSize === null) {
            return null;
        }

        return intdiv($dataSize, $byteRate);
    }

    private static function ogg(string $bytes): ?int
    {
        $last = null;
        $from = 0;
        $length = strlen($bytes);
        while (($at = strpos($bytes, 'OggS', $from)) !== false) {
            if ($at + 14 <= $length) {
                $granule = unpack('P', substr($bytes, $at + 6, 8));
                if (is_array($granule)) {
                    $last = (int) $granule[1];
                }
            }
            $from = $at + 4;
        }
        if ($last === null || $last < 1) {
            return null;
        }
        $rate = str_contains($bytes, 'OpusHead') ? 48000 : 44100;

        return intdiv($last, $rate);
    }

    private static function mp3(string $bytes): ?int
    {
        $rates = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
        $length = strlen($bytes);
        $limit = min($length - 4, 8192);
        for ($i = 0; $i <= $limit; $i++) {
            $b1 = ord($bytes[$i]);
            $b2 = ord($bytes[$i + 1]);
            if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) {
                continue;
            }
            $version = ($b2 >> 3) & 0x03;
            $layer = ($b2 >> 1) & 0x03;
            if ($version !== 3 || $layer !== 1) {
                continue;
            }
            $index = (ord($bytes[$i + 2]) >> 4) & 0x0F;
            $kbps = $rates[$index] ?? 0;
            if ($kbps < 1) {
                continue;
            }

            return intdiv($length * 8, $kbps * 1000);
        }

        return null;
    }

    private static function mp4(string $bytes): ?int
    {
        $at = strpos($bytes, 'mvhd');
        if ($at === false) {
            return null;
        }
        $versionAt = $at + 4;
        if ($versionAt >= strlen($bytes)) {
            return null;
        }
        $version = ord($bytes[$versionAt]);
        if ($version === 0) {
            if ($at + 24 > strlen($bytes)) {
                return null;
            }
            $scale = unpack('N', substr($bytes, $at + 16, 4));
            $duration = unpack('N', substr($bytes, $at + 20, 4));
        } else {
            if ($at + 36 > strlen($bytes)) {
                return null;
            }
            $scale = unpack('N', substr($bytes, $at + 24, 4));
            $duration = unpack('J', substr($bytes, $at + 28, 8));
        }
        $scaleValue = is_array($scale) ? (int) $scale[1] : 0;
        $durationValue = is_array($duration) ? (int) $duration[1] : 0;
        if ($scaleValue < 1 || $durationValue < 1) {
            return null;
        }

        return intdiv($durationValue, $scaleValue);
    }
}
