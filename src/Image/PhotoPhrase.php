<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhotoPhrase
{
    public static function draw(string $jpegPath, string $phrase, string $style = PhraseStyle::CLASSICA): void
    {
        $phrase = trim($phrase);
        if ($phrase === '' || !is_file($jpegPath)) {
            return;
        }
        $style = PhraseStyle::normalize($style);
        if ($style === PhraseStyle::FORTE) {
            $phrase = mb_strtoupper($phrase, 'UTF-8');
        }
        $script = $style === PhraseStyle::CURSIVA;
        $font = self::font($style);
        if ($script && $font === null) {
            $style = PhraseStyle::CLASSICA;
            $script = false;
            $font = self::font($style);
        }
        if (extension_loaded('imagick') && class_exists(\Imagick::class) && $font !== null) {
            try {
                if ($script) {
                    self::drawScriptImagick($jpegPath, $phrase, $font);
                } else {
                    self::drawImagick($jpegPath, $phrase, $font);
                }

                return;
            } catch (\Throwable) {
            }
        }
        if ($script) {
            self::drawScriptGd($jpegPath, $phrase, $font);

            return;
        }
        self::drawGd($jpegPath, $phrase, $font);
    }

    private static function drawImagick(string $jpegPath, string $phrase, string $font): void
    {
        $image = new \Imagick($jpegPath);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $lines = self::lines($phrase, 22);
        $size = self::fitSize($width, $lines, 18);
        $band = (int) max($size * 2.6, $height * 0.30);
        self::fadeImagick($image, $width, $height, $band);

        $lineHeight = (int) ($size * 1.18);
        $block = $lineHeight * count($lines);
        $bottomPad = (int) max(18, $height * 0.045);
        $start = $height - $bottomPad - $block + $size;
        $center = (int) ($width / 2);
        $probe = new \ImagickDraw();
        $probe->setFont($font);
        $probe->setFontSize($size);
        $metrics = $image->queryFontMetrics($probe, $lines[0]);
        $ascent = (int) ($metrics['ascender'] ?? (int) ($size * 0.8));
        $ruleY = $start - $ascent - (int) max(12, $size * 0.34);
        self::ruleImagick($image, $center, $ruleY, (int) max(56, $width * 0.18));

        $shadow = new \ImagickDraw();
        $fill = new \ImagickDraw();
        foreach ([$shadow, $fill] as $draw) {
            $draw->setFont($font);
            $draw->setFontSize($size);
            $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        }
        $shadow->setFillColor(new \ImagickPixel('rgba(20,10,4,0.55)'));
        $fill->setFillColor(new \ImagickPixel('#F6F0E3'));
        foreach ($lines as $i => $line) {
            $y = $start + ($i * $lineHeight);
            $image->annotateImage($shadow, $center + 2, $y + 3, 0, $line);
            $image->annotateImage($fill, $center, $y, 0, $line);
        }

        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(90);
        $image->writeImage($jpegPath);
        $image->clear();
    }

    private static function drawScriptImagick(string $jpegPath, string $phrase, string $font): void
    {
        $image = new \Imagick($jpegPath);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $lines = self::lines($phrase, 18);
        $size = self::fitSize($width, $lines, 12);
        $lineHeight = (int) ($size * 1.35);
        $center = (int) ($width / 2);
        $y = (int) max($size + 8, $height * 0.18);

        $shadow = new \ImagickDraw();
        $fill = new \ImagickDraw();
        foreach ([$shadow, $fill] as $draw) {
            $draw->setFont($font);
            $draw->setFontSize($size);
            $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        }
        $shadow->setFillColor(new \ImagickPixel('rgba(20,12,8,0.55)'));
        $fill->setFillColor(new \ImagickPixel('#FFFFFF'));
        foreach ($lines as $line) {
            $image->annotateImage($shadow, $center + 2, $y + 3, 0, $line);
            $image->annotateImage($fill, $center, $y, 0, $line);
            $y += $lineHeight;
        }

        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(90);
        $image->writeImage($jpegPath);
        $image->clear();
    }

    private static function fadeImagick(\Imagick $image, int $width, int $height, int $band): void
    {
        $overlay = new \Imagick();
        $overlay->newPseudoImage($width, $band, 'gradient:transparent-black');
        $overlay->setImageFormat('png');
        $overlay->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.72, \Imagick::CHANNEL_ALPHA);
        $image->compositeImage($overlay, \Imagick::COMPOSITE_OVER, 0, $height - $band);
        $overlay->clear();
    }

    private static function ruleImagick(\Imagick $image, int $center, int $y, int $span): void
    {
        $draw = new \ImagickDraw();
        $draw->setStrokeColor(new \ImagickPixel('#E4C27A'));
        $draw->setStrokeWidth(2);
        $draw->line($center - (int) ($span / 2), $y, $center + (int) ($span / 2), $y);
        $image->drawImage($draw);
    }

    private static function drawGd(string $jpegPath, string $phrase, ?string $font): void
    {
        $image = @imagecreatefromjpeg($jpegPath);
        if ($image === false) {
            return;
        }
        imagealphablending($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        $lines = self::lines($phrase, 22);
        $size = $font !== null ? self::fitSize($width, $lines, 16) : 5;
        $band = (int) max(72, $height * 0.30);
        $top = $height - $band;
        for ($y = $top; $y < $height; $y++) {
            $t = ($y - $top) / max(1, $band - 1);
            $alpha = (int) round(127 - (100 * ($t * $t)));
            $color = imagecolorallocatealpha($image, 14, 8, 4, max(0, min(127, $alpha)));
            imageline($image, 0, $y, $width, $y, $color);
        }
        if ($font === null) {
            $white = imagecolorallocate($image, 246, 240, 227);
            imagestring($image, 5, 12, $top + 16, $phrase, $white);
            imagejpeg($image, $jpegPath, 90);
            imagedestroy($image);

            return;
        }

        $lineHeight = (int) ($size * 1.28);
        $block = $lineHeight * count($lines);
        $bottomPad = (int) max(14, $height * 0.045);
        $y = $height - $bottomPad - $block + $size;
        $box = imagettfbbox($size, 0, $font, $lines[0]);
        $ascent = is_array($box) ? abs((int) $box[7]) : (int) ($size * 0.8);
        $ruleY = $y - $ascent - (int) max(10, $size * 0.34);
        $span = (int) max(48, $width * 0.18);
        $gold = imagecolorallocate($image, 228, 194, 122);
        imagefilledrectangle($image, (int) (($width - $span) / 2), $ruleY, (int) (($width + $span) / 2), $ruleY + 2, $gold);

        $shadow = imagecolorallocatealpha($image, 20, 10, 4, 40);
        $ink = imagecolorallocate($image, 246, 240, 227);
        $stroke = imagecolorallocate($image, 42, 22, 8);
        foreach ($lines as $line) {
            $box = imagettfbbox($size, 0, $font, $line);
            $textWidth = is_array($box) ? (int) ($box[2] - $box[0]) : 0;
            $x = (int) (($width - $textWidth) / 2);
            imagettftext($image, $size, 0, $x + 2, $y + 3, $shadow, $font, $line);
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                imagettftext($image, $size, 0, $x + $dx, $y + $dy, $stroke, $font, $line);
            }
            imagettftext($image, $size, 0, $x, $y, $ink, $font, $line);
            $y += $lineHeight;
        }
        imagejpeg($image, $jpegPath, 90);
        imagedestroy($image);
    }

    private static function drawScriptGd(string $jpegPath, string $phrase, ?string $font): void
    {
        if ($font === null) {
            self::drawGd($jpegPath, $phrase, self::font(PhraseStyle::CLASSICA));

            return;
        }
        $image = @imagecreatefromjpeg($jpegPath);
        if ($image === false) {
            return;
        }
        imagealphablending($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        $lines = self::lines($phrase, 18);
        $size = self::fitSize($width, $lines, 12);
        $lineHeight = (int) ($size * 1.35);
        $y = (int) max($size + 8, $height * 0.18);
        $shadow = imagecolorallocatealpha($image, 20, 12, 8, 50);
        $ink = imagecolorallocate($image, 255, 255, 255);
        foreach ($lines as $line) {
            $box = imagettfbbox($size, 0, $font, $line);
            $textWidth = is_array($box) ? (int) ($box[2] - $box[0]) : 0;
            $x = (int) (($width - $textWidth) / 2);
            imagettftext($image, $size, 0, $x + 2, $y + 3, $shadow, $font, $line);
            imagettftext($image, $size, 0, $x, $y, $ink, $font, $line);
            $y += $lineHeight;
        }
        imagejpeg($image, $jpegPath, 90);
        imagedestroy($image);
    }

    /**
     * @param list<string> $lines
     */
    private static function fitSize(int $width, array $lines, int $divisor): int
    {
        $longest = 1;
        foreach ($lines as $line) {
            $longest = max($longest, mb_strlen($line));
        }
        $byWidth = (int) (($width * 0.86) / max(1, $longest * 0.55));

        return (int) max(18, min((int) ($width / $divisor), $byWidth));
    }

    /**
     * @return list<string>
     */
    private static function lines(string $phrase, int $width): array
    {
        $words = preg_split('/\s+/u', $phrase) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $next = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($next) > $width && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $next;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return array_slice($lines, 0, 3);
    }

    private static function font(string $style): ?string
    {
        $file = match (PhraseStyle::normalize($style)) {
            PhraseStyle::CURSIVA => 'GreatVibes-Regular.ttf',
            PhraseStyle::LIMPA => 'SourceSans3-Bold.ttf',
            PhraseStyle::FORTE => 'Anton-Regular.ttf',
            default => 'LibreBaskerville-Bold.ttf',
        };
        $bundled = dirname(__DIR__, 2) . '/resources/fonts/' . $file;
        if (is_file($bundled)) {
            return $bundled;
        }
        $paths = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSerif-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/System/Library/Fonts/Supplemental/Georgia Bold.ttf',
            '/System/Library/Fonts/Supplemental/Times New Roman Bold.ttf',
            '/Library/Fonts/Arial.ttf',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
