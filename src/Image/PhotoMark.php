<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhotoMark
{
    public static function stamp(string $jpegPath, string $logoPath, string $place): bool
    {
        if (!is_file($jpegPath) || !is_file($logoPath)) {
            return false;
        }
        $place = in_array($place, ['tl', 'tr', 'bl', 'br', 'c'], true) ? $place : 'br';
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                return self::stampImagick($jpegPath, $logoPath, $place);
            } catch (\Throwable) {
                return false;
            }
        }

        return self::stampGd($jpegPath, $logoPath, $place);
    }

    private static function stampImagick(string $jpegPath, string $logoPath, string $place): bool
    {
        $photo = new \Imagick($jpegPath);
        $logo = new \Imagick($logoPath);
        $logo->setImageFormat('png');
        $logo->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
        $side = min($logo->getImageWidth(), $logo->getImageHeight());
        $logo->cropImage(
            $side,
            $side,
            (int) (($logo->getImageWidth() - $side) / 2),
            (int) (($logo->getImageHeight() - $side) / 2),
        );
        $logo->setImagePage(0, 0, 0, 0);

        $width = $photo->getImageWidth();
        $height = $photo->getImageHeight();
        [$inner, $white, $ring, $total] = self::sizes($width, $height);
        $logo->resizeImage($inner, $inner, \Imagick::FILTER_LANCZOS, 1, true);

        $mask = new \Imagick();
        $mask->newImage($inner, $inner, new \ImagickPixel('transparent'));
        $mask->setImageFormat('png');
        $cut = new \ImagickDraw();
        $cut->setFillColor(new \ImagickPixel('white'));
        $cut->circle($inner / 2, $inner / 2, $inner / 2, 1);
        $mask->drawImage($cut);
        $logo->compositeImage($mask, \Imagick::COMPOSITE_DSTIN, 0, 0);

        $badge = new \Imagick();
        $badge->newImage($total, $total, new \ImagickPixel('transparent'));
        $badge->setImageFormat('png');
        $disk = new \ImagickDraw();
        $center = $total / 2;
        $disk->setFillColor(new \ImagickPixel('#D2D2D2'));
        $disk->circle($center, $center, $center, 1);
        $disk->setFillColor(new \ImagickPixel('white'));
        $disk->circle($center, $center, $center, $ring + 1);
        $badge->drawImage($disk);
        $badge->compositeImage($logo, \Imagick::COMPOSITE_OVER, $ring + $white, $ring + $white);

        [$x, $y] = self::origin($width, $height, $total, $place);
        $photo->compositeImage($badge, \Imagick::COMPOSITE_OVER, $x, $y);
        $photo->setImageFormat('jpeg');
        $photo->setImageCompressionQuality(90);
        $photo->writeImage($jpegPath);
        $photo->clear();
        $logo->clear();
        $badge->clear();

        return true;
    }

    private static function stampGd(string $jpegPath, string $logoPath, string $place): bool
    {
        $photo = @imagecreatefromjpeg($jpegPath);
        $raw = file_get_contents($logoPath);
        $logo = is_string($raw) ? @imagecreatefromstring($raw) : false;
        if ($photo === false || $logo === false) {
            return false;
        }

        $logoWidth = imagesx($logo);
        $logoHeight = imagesy($logo);
        $side = min($logoWidth, $logoHeight);
        $square = imagecreatetruecolor($side, $side);
        imagecopy(
            $square,
            $logo,
            0,
            0,
            (int) (($logoWidth - $side) / 2),
            (int) (($logoHeight - $side) / 2),
            $side,
            $side,
        );

        $width = imagesx($photo);
        $height = imagesy($photo);
        [$inner, $white, $ring, $total] = self::sizes($width, $height);
        $scaled = imagecreatetruecolor($inner, $inner);
        imagecopyresampled($scaled, $square, 0, 0, 0, 0, $inner, $inner, $side, $side);

        [$x0, $y0] = self::origin($width, $height, $total, $place);
        $cx = $x0 + ($total / 2);
        $cy = $y0 + ($total / 2);
        $outerR = ($total / 2) - 0.5;
        $whiteR = $outerR - $ring;
        $innerR = $whiteR - $white;
        $gray = imagecolorallocate($photo, 210, 210, 210);
        $paper = imagecolorallocate($photo, 255, 255, 255);

        for ($y = 0; $y < $total; $y++) {
            for ($x = 0; $x < $total; $x++) {
                $dx = $x0 + $x + 0.5 - $cx;
                $dy = $y0 + $y + 0.5 - $cy;
                $distance = hypot($dx, $dy);
                if ($distance > $outerR) {
                    continue;
                }
                $px = $x0 + $x;
                $py = $y0 + $y;
                if ($distance > $whiteR) {
                    imagesetpixel($photo, $px, $py, $gray);
                    continue;
                }
                if ($distance > $innerR) {
                    imagesetpixel($photo, $px, $py, $paper);
                    continue;
                }
                $lx = (int) round((($dx + $innerR) / max(1, $innerR * 2)) * ($inner - 1));
                $ly = (int) round((($dy + $innerR) / max(1, $innerR * 2)) * ($inner - 1));
                $lx = max(0, min($inner - 1, $lx));
                $ly = max(0, min($inner - 1, $ly));
                imagesetpixel($photo, $px, $py, imagecolorat($scaled, $lx, $ly));
            }
        }

        imagejpeg($photo, $jpegPath, 90);
        imagedestroy($photo);
        imagedestroy($logo);
        imagedestroy($square);
        imagedestroy($scaled);

        return true;
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}
     */
    private static function sizes(int $width, int $height): array
    {
        $short = min($width, $height);
        $inner = (int) max(36, min(210, $short * 0.14));
        $white = (int) max(4, round($inner * 0.055));
        $ring = (int) max(2, round($inner * 0.018));

        return [$inner, $white, $ring, $inner + (2 * $white) + (2 * $ring)];
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function origin(int $width, int $height, int $total, string $place): array
    {
        $margin = (int) max(16, min($width, $height) * 0.04);
        $x = match ($place) {
            'tl', 'bl' => $margin,
            'tr', 'br' => $width - $total - $margin,
            default => (int) (($width - $total) / 2),
        };
        $y = match ($place) {
            'tl', 'tr' => $margin,
            'bl', 'br' => $height - $total - $margin,
            default => (int) (($height - $total) / 2),
        };

        return [max(0, $x), max(0, $y)];
    }

    public static function stampPlate(string $jpegPath, string $logoPath, string $place): bool
    {
        if (!is_file($jpegPath) || !is_file($logoPath)) {
            return false;
        }
        $place = in_array($place, ['tl', 'tr', 'bl', 'br', 'c'], true) ? $place : 'br';
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                return self::plateImagick($jpegPath, $logoPath, $place);
            } catch (\Throwable) {
                return self::plateGd($jpegPath, $logoPath, $place);
            }
        }

        return self::plateGd($jpegPath, $logoPath, $place);
    }

    private static function plateImagick(string $jpegPath, string $logoPath, string $place): bool
    {
        $photo = new \Imagick($jpegPath);
        $logo = new \Imagick($logoPath);
        $logo->setImageFormat('png');
        $logo->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
        $width = $photo->getImageWidth();
        $height = $photo->getImageHeight();
        $maxW = (int) max(48, $width * 0.28);
        $maxH = (int) max(48, $height * 0.22);
        $logo->resizeImage($maxW, $maxH, \Imagick::FILTER_LANCZOS, 1, true);
        $boxW = $logo->getImageWidth();
        $boxH = $logo->getImageHeight();
        [$x, $y] = self::plateOrigin($width, $height, $boxW, $boxH, $place);
        $photo->compositeImage($logo, \Imagick::COMPOSITE_OVER, $x, $y);
        $photo->setImageFormat('jpeg');
        $photo->setImageCompressionQuality(90);
        $photo->writeImage($jpegPath);
        $photo->clear();
        $logo->clear();

        return true;
    }

    private static function plateGd(string $jpegPath, string $logoPath, string $place): bool
    {
        $photo = @imagecreatefromjpeg($jpegPath);
        $raw = file_get_contents($logoPath);
        $logo = is_string($raw) ? @imagecreatefromstring($raw) : false;
        if ($photo === false || $logo === false) {
            if ($photo !== false) {
                imagedestroy($photo);
            }

            return false;
        }
        $logoWidth = imagesx($logo);
        $logoHeight = imagesy($logo);
        if ($logoWidth < 1 || $logoHeight < 1) {
            imagedestroy($photo);
            imagedestroy($logo);

            return false;
        }
        $width = imagesx($photo);
        $height = imagesy($photo);
        $maxW = (int) max(48, $width * 0.28);
        $maxH = (int) max(48, $height * 0.22);
        $scale = min($maxW / $logoWidth, $maxH / $logoHeight);
        $boxW = max(1, (int) round($logoWidth * $scale));
        $boxH = max(1, (int) round($logoHeight * $scale));
        $scaled = imagecreatetruecolor($boxW, $boxH);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $clear = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $boxW, $boxH, $clear);
        imagealphablending($scaled, true);
        imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $boxW, $boxH, $logoWidth, $logoHeight);
        [$x, $y] = self::plateOrigin($width, $height, $boxW, $boxH, $place);
        imagealphablending($photo, true);
        imagecopy($photo, $scaled, $x, $y, 0, 0, $boxW, $boxH);
        imagejpeg($photo, $jpegPath, 90);
        imagedestroy($photo);
        imagedestroy($logo);
        imagedestroy($scaled);

        return true;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function plateOrigin(int $width, int $height, int $boxW, int $boxH, string $place): array
    {
        $margin = (int) max(12, min($width, $height) * 0.04);
        $x = match ($place) {
            'tl', 'bl' => $margin,
            'tr', 'br' => $width - $boxW - $margin,
            default => (int) (($width - $boxW) / 2),
        };
        $y = match ($place) {
            'tl', 'tr' => $margin,
            'bl', 'br' => $height - $boxH - $margin,
            default => (int) (($height - $boxH) / 2),
        };

        return [max(0, $x), max(0, $y)];
    }
}
