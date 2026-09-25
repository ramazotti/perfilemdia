<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhotoPhrase
{
    public static function draw(string $jpegPath, string $phrase, string $style = PhraseStyle::CLASSICA, string $color = PhraseColor::BRANCO, string $place = PhrasePlace::RODAPE): void
    {
        $phrase = trim($phrase);
        if ($phrase === '' || !is_file($jpegPath)) {
            return;
        }
        $style = PhraseStyle::normalize($style);
        $color = PhraseColor::normalize($color);
        $place = PhrasePlace::normalize($place);
        if ($style === PhraseStyle::BALAO || $style === PhraseStyle::CAIXA) {
            $font = self::font($style);
            if (extension_loaded('imagick') && class_exists(\Imagick::class) && $font !== null) {
                try {
                    self::drawPlateImagick($jpegPath, $phrase, $font, $style, $color, $place);

                    return;
                } catch (\Throwable) {
                }
            }
            self::drawPlateGd($jpegPath, $phrase, $font, $style, $color, $place);

            return;
        }
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
                    self::drawScriptImagick($jpegPath, $phrase, $font, $color, $place);
                } else {
                    self::drawImagick($jpegPath, $phrase, $font, $color, $place);
                }

                return;
            } catch (\Throwable) {
            }
        }
        if ($script) {
            self::drawScriptGd($jpegPath, $phrase, $font, $color, $place);

            return;
        }
        self::drawGd($jpegPath, $phrase, $font, $color, $place);
    }

    private static function drawImagick(string $jpegPath, string $phrase, string $font, string $color, string $place): void
    {
        $image = new \Imagick($jpegPath);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $lines = self::lines($phrase, 22);
        $size = self::fitSize($width, $lines, 18);
        $band = (int) max($size * 2.6, $height * 0.30);
        self::fadeImagick($image, $width, $height, $band, PhraseColor::lightWash($color), $place);

        $lineHeight = (int) ($size * 1.18);
        $block = $lineHeight * count($lines);
        $start = self::textOrigin($height, $size, $block, $place);
        $center = (int) ($width / 2);
        $probe = new \ImagickDraw();
        $probe->setFont($font);
        $probe->setFontSize($size);
        $metrics = $image->queryFontMetrics($probe, $lines[0]);
        $ascent = (int) ($metrics['ascender'] ?? (int) ($size * 0.8));
        $ruleY = max(8, $start - $ascent - (int) max(12, $size * 0.34));
        self::ruleImagick($image, $center, $ruleY, (int) max(56, $width * 0.18), PhraseColor::ruleHex($color));

        $shadow = new \ImagickDraw();
        $fill = new \ImagickDraw();
        foreach ([$shadow, $fill] as $draw) {
            $draw->setFont($font);
            $draw->setFontSize($size);
            $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        }
        $shadow->setFillColor(new \ImagickPixel(PhraseColor::lightWash($color) ? 'rgba(255,255,255,0.55)' : 'rgba(20,10,4,0.55)'));
        $fill->setFillColor(new \ImagickPixel(PhraseColor::inkHex($color)));
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

    private static function drawScriptImagick(string $jpegPath, string $phrase, string $font, string $color, string $place): void
    {
        $image = new \Imagick($jpegPath);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $lines = self::lines($phrase, 18);
        $size = self::fitSize($width, $lines, 12);
        $lineHeight = (int) ($size * 1.35);
        $center = (int) ($width / 2);
        $band = (int) max($size * 2.4, $height * 0.28);
        self::fadeImagick($image, $width, $height, $band, PhraseColor::lightWash($color), $place);
        $y = self::textOrigin($height, $size, $lineHeight * count($lines), $place);

        $shadow = new \ImagickDraw();
        $fill = new \ImagickDraw();
        foreach ([$shadow, $fill] as $draw) {
            $draw->setFont($font);
            $draw->setFontSize($size);
            $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        }
        $shadow->setFillColor(new \ImagickPixel(PhraseColor::lightWash($color) ? 'rgba(255,255,255,0.55)' : 'rgba(20,12,8,0.55)'));
        $fill->setFillColor(new \ImagickPixel(PhraseColor::inkHex($color)));
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

    private static function fadeImagick(\Imagick $image, int $width, int $height, int $band, bool $light, string $place): void
    {
        $solid = $light ? 'white' : 'black';
        $place = PhrasePlace::normalize($place);
        $y = self::washOrigin($height, $band, $place);
        if ($place === PhrasePlace::MEIO) {
            $half = (int) max(1, (int) ($band / 2));
            self::shadeImagick($image, $width, $half, 'gradient:transparent-' . $solid, $y);
            self::shadeImagick($image, $width, $band - $half, 'gradient:' . $solid . '-transparent', $y + $half);

            return;
        }
        $spec = $place === PhrasePlace::TOPO
            ? 'gradient:' . $solid . '-transparent'
            : 'gradient:transparent-' . $solid;
        self::shadeImagick($image, $width, $band, $spec, $y);
    }

    private static function shadeImagick(\Imagick $image, int $width, int $band, string $spec, int $y): void
    {
        if ($band < 1) {
            return;
        }
        $overlay = new \Imagick();
        $overlay->newPseudoImage($width, $band, $spec);
        $overlay->setImageFormat('png');
        $overlay->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.78, \Imagick::CHANNEL_ALPHA);
        $image->compositeImage($overlay, \Imagick::COMPOSITE_OVER, 0, $y);
        $overlay->clear();
    }

    private static function ruleImagick(\Imagick $image, int $center, int $y, int $span, string $hex): void
    {
        $draw = new \ImagickDraw();
        $draw->setStrokeColor(new \ImagickPixel($hex));
        $draw->setStrokeWidth(2);
        $draw->line($center - (int) ($span / 2), $y, $center + (int) ($span / 2), $y);
        $image->drawImage($draw);
    }

    private static function drawGd(string $jpegPath, string $phrase, ?string $font, string $color, string $place): void
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
        $from = self::washOrigin($height, $band, $place);
        self::washGd($image, $width, $from, $from + $band, PhraseColor::lightWash($color), $place);
        if ($font === null) {
            [$ir, $ig, $ib] = PhraseColor::ink($color);
            $white = imagecolorallocate($image, $ir, $ig, $ib);
            imagestring($image, 5, 12, $from + 16, $phrase, $white);
            imagejpeg($image, $jpegPath, 90);
            imagedestroy($image);

            return;
        }

        $lineHeight = (int) ($size * 1.28);
        $block = $lineHeight * count($lines);
        $y = self::textOrigin($height, $size, $block, $place);
        $box = imagettfbbox($size, 0, $font, $lines[0]);
        $ascent = is_array($box) ? abs((int) $box[7]) : (int) ($size * 0.8);
        $ruleY = max(8, $y - $ascent - (int) max(10, $size * 0.34));
        $span = (int) max(48, $width * 0.18);
        $rule = PhraseColor::lightWash($color) ? [42, 36, 28] : [228, 194, 122];
        $gold = imagecolorallocate($image, $rule[0], $rule[1], $rule[2]);
        imagefilledrectangle($image, (int) (($width - $span) / 2), $ruleY, (int) (($width + $span) / 2), $ruleY + 2, $gold);

        [$ir, $ig, $ib] = PhraseColor::ink($color);
        $light = PhraseColor::lightWash($color);
        $shadow = imagecolorallocatealpha($image, $light ? 255 : 20, $light ? 255 : 10, $light ? 255 : 4, 40);
        $ink = imagecolorallocate($image, $ir, $ig, $ib);
        $stroke = imagecolorallocate($image, $light ? 255 : 42, $light ? 255 : 22, $light ? 255 : 8);
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

    private static function drawScriptGd(string $jpegPath, string $phrase, ?string $font, string $color, string $place): void
    {
        if ($font === null) {
            self::drawGd($jpegPath, $phrase, self::font(PhraseStyle::CLASSICA), $color, $place);

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
        $band = (int) max(72, $height * 0.28);
        $from = self::washOrigin($height, $band, $place);
        self::washGd($image, $width, $from, $from + $band, PhraseColor::lightWash($color), $place);
        $y = self::textOrigin($height, $size, $lineHeight * count($lines), $place);
        $light = PhraseColor::lightWash($color);
        [$ir, $ig, $ib] = PhraseColor::ink($color);
        $shadow = imagecolorallocatealpha($image, $light ? 255 : 20, $light ? 255 : 12, $light ? 255 : 8, 50);
        $ink = imagecolorallocate($image, $ir, $ig, $ib);
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

    private static function drawPlateGd(string $jpegPath, string $phrase, ?string $font, string $style, string $color, string $place): void
    {
        $image = @imagecreatefromjpeg($jpegPath);
        if ($image === false) {
            return;
        }
        imagealphablending($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        $bubble = $style === PhraseStyle::BALAO;
        $lines = self::lines($phrase, $bubble ? 28 : 18, $bubble ? 4 : 5);
        $size = $font !== null ? self::fitSize($width, $lines, $bubble ? 14 : 16) : 5;
        $widths = [40];
        if ($font !== null) {
            for ($try = 0; $try < 5; $try++) {
                $widths = [];
                foreach ($lines as $line) {
                    $box = imagettfbbox($size, 0, $font, $line);
                    $widths[] = is_array($box) ? (int) ($box[2] - $box[0]) : $size * 4;
                }
                $padX = (int) max(14, $size * ($bubble ? 0.72 : 0.95));
                if (max($widths) + ($padX * 2) <= $width - 20) {
                    break;
                }
                $size = (int) max(16, (int) ($size * 0.86));
            }
        }
        $layout = self::plateLayout($width, $height, $widths, $size, $style, $place);
        $light = PhraseColor::normalize($color) === PhraseColor::PRETO;
        [$pr, $pg, $pb] = $light ? [255, 255, 255] : [12, 12, 12];
        $fill = imagecolorallocate($image, $pr, $pg, $pb);
        if ($fill === false) {
            imagedestroy($image);

            return;
        }
        if ($bubble) {
            $shadow = imagecolorallocatealpha($image, 0, 0, 0, 78);
            if ($shadow !== false) {
                self::fillRound($image, $layout['x'] + 3, $layout['y'] + 5, $layout['boxW'], $layout['boxH'], $layout['radius'], $shadow);
            }
        }
        self::fillRound($image, $layout['x'], $layout['y'], $layout['boxW'], $layout['boxH'], $layout['radius'], $fill);
        if ($bubble && $layout['tail'] > 0) {
            self::fillTailGd($image, $layout, $fill);
        }
        [$ir, $ig, $ib] = PhraseColor::ink($color);
        $ink = imagecolorallocate($image, $ir, $ig, $ib);
        if ($ink === false) {
            imagejpeg($image, $jpegPath, 90);
            imagedestroy($image);

            return;
        }
        if ($font === null) {
            imagestring($image, 5, $layout['x'] + 12, $layout['y'] + 12, $phrase, $ink);
        } else {
            $probe = imagettfbbox($size, 0, $font, $lines[0] !== '' ? $lines[0] : 'A');
            $ascent = is_array($probe) ? abs((int) $probe[7]) : (int) ($size * 0.8);
            $baseline = $layout['y'] + $layout['padY'] + $ascent;
            foreach ($lines as $i => $line) {
                $box = imagettfbbox($size, 0, $font, $line);
                $textWidth = is_array($box) ? (int) ($box[2] - $box[0]) : 0;
                $tx = (int) ($layout['x'] + (($layout['boxW'] - $textWidth) / 2));
                imagettftext($image, $size, 0, $tx, $baseline + ($i * $layout['lineHeight']), $ink, $font, $line);
            }
        }
        imagejpeg($image, $jpegPath, 90);
        imagedestroy($image);
    }

    private static function drawPlateImagick(string $jpegPath, string $phrase, string $font, string $style, string $color, string $place): void
    {
        $image = new \Imagick($jpegPath);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $bubble = $style === PhraseStyle::BALAO;
        $lines = self::lines($phrase, $bubble ? 28 : 18, $bubble ? 4 : 5);
        $size = self::fitSize($width, $lines, $bubble ? 14 : 16);
        $probe = new \ImagickDraw();
        $probe->setFont($font);
        $widths = [40];
        for ($try = 0; $try < 5; $try++) {
            $probe->setFontSize($size);
            $widths = [];
            foreach ($lines as $line) {
                $metrics = $image->queryFontMetrics($probe, $line);
                $widths[] = (int) ($metrics['textWidth'] ?? $size * 4);
            }
            $padX = (int) max(14, $size * ($bubble ? 0.72 : 0.95));
            if (max($widths) + ($padX * 2) <= $width - 20) {
                break;
            }
            $size = (int) max(16, (int) ($size * 0.86));
        }
        $layout = self::plateLayout($width, $height, $widths, $size, $style, $place);
        $plate = PhraseColor::normalize($color) === PhraseColor::PRETO ? '#FFFFFF' : '#111111';
        if ($bubble) {
            $shadow = new \ImagickDraw();
            $shadow->setFillColor(new \ImagickPixel('rgba(0,0,0,0.35)'));
            $shadow->roundRectangle(
                $layout['x'] + 3,
                $layout['y'] + 5,
                $layout['x'] + $layout['boxW'] + 3,
                $layout['y'] + $layout['boxH'] + 5,
                $layout['radius'],
                $layout['radius']
            );
            $image->drawImage($shadow);
        }
        $shape = new \ImagickDraw();
        $shape->setFillColor(new \ImagickPixel($plate));
        if ($layout['radius'] < 3) {
            $shape->rectangle($layout['x'], $layout['y'], $layout['x'] + $layout['boxW'], $layout['y'] + $layout['boxH']);
        } else {
            $shape->roundRectangle(
                $layout['x'],
                $layout['y'],
                $layout['x'] + $layout['boxW'],
                $layout['y'] + $layout['boxH'],
                $layout['radius'],
                $layout['radius']
            );
        }
        if ($bubble && $layout['tail'] > 0) {
            [$x1, $y1, $x2, $y2, $x3, $y3] = self::tailPoints($layout);
            $shape->polygon([
                ['x' => $x1, 'y' => $y1],
                ['x' => $x2, 'y' => $y2],
                ['x' => $x3, 'y' => $y3],
            ]);
        }
        $image->drawImage($shape);
        $probe->setFontSize($size);
        $metrics = $image->queryFontMetrics($probe, $lines[0] !== '' ? $lines[0] : 'A');
        $ascent = (int) ($metrics['ascender'] ?? (int) ($size * 0.8));
        $baseline = $layout['y'] + $layout['padY'] + $ascent;
        $ink = new \ImagickDraw();
        $ink->setFont($font);
        $ink->setFontSize($size);
        $ink->setTextAlignment(\Imagick::ALIGN_CENTER);
        $ink->setFillColor(new \ImagickPixel(PhraseColor::inkHex($color)));
        $cx = (int) ($layout['x'] + ($layout['boxW'] / 2));
        foreach ($lines as $i => $line) {
            $image->annotateImage($ink, $cx, $baseline + ($i * $layout['lineHeight']), 0, $line);
        }
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(90);
        $image->writeImage($jpegPath);
        $image->clear();
    }

    /**
     * @param list<int> $widths
     * @return array{size:int,lineHeight:int,padX:int,padY:int,boxW:int,boxH:int,radius:int,tail:int,tailUp:bool,x:int,y:int}
     */
    private static function plateLayout(int $imgW, int $imgH, array $widths, int $size, string $style, string $place): array
    {
        $bubble = $style === PhraseStyle::BALAO;
        $padX = (int) max(14, $size * ($bubble ? 0.72 : 0.95));
        $padY = (int) max(12, $size * ($bubble ? 0.5 : 0.72));
        $lineHeight = (int) ($size * ($bubble ? 1.22 : 1.38));
        $maxW = 1;
        foreach ($widths as $w) {
            $maxW = max($maxW, $w);
        }
        $boxW = min($imgW - 16, $maxW + ($padX * 2));
        $boxH = ($padY * 2) + ($lineHeight * max(1, count($widths)));
        $tail = $bubble ? (int) max(14, (int) ($size * 0.5)) : 0;
        $tailUp = PhrasePlace::normalize($place) === PhrasePlace::RODAPE;
        $total = $boxH + $tail;
        $radius = $bubble ? (int) max(16, (int) ($size * 0.7)) : 2;
        $radius = min($radius, (int) ($boxW / 2), (int) ($boxH / 2));
        $x = (int) max(8, ($imgW - $boxW) / 2);
        $y = match (PhrasePlace::normalize($place)) {
            PhrasePlace::TOPO => (int) max(10, $imgH * 0.05),
            PhrasePlace::MEIO => (int) (($imgH - $total) / 2),
            default => $imgH - $total - (int) max(12, $imgH * 0.045),
        };
        $y = max(6, min($y, max(6, $imgH - $total - 6)));
        $boxY = $tailUp ? $y + $tail : $y;

        return [
            'size' => $size,
            'lineHeight' => $lineHeight,
            'padX' => $padX,
            'padY' => $padY,
            'boxW' => $boxW,
            'boxH' => $boxH,
            'radius' => $radius,
            'tail' => $tail,
            'tailUp' => $tailUp,
            'x' => $x,
            'y' => $boxY,
        ];
    }

    /**
     * @param \GdImage $image
     */
    private static function fillRound($image, int $x, int $y, int $w, int $h, int $r, int $color): void
    {
        if ($r < 3) {
            imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $color);

            return;
        }
        $r = min($r, (int) ($w / 2), (int) ($h / 2));
        imagefilledrectangle($image, $x + $r, $y, $x + $w - $r, $y + $h, $color);
        imagefilledrectangle($image, $x, $y + $r, $x + $w, $y + $h - $r, $color);
        imagefilledellipse($image, $x + $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($image, $x + $w - $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($image, $x + $r, $y + $h - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($image, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, $color);
    }

    /**
     * @param \GdImage $image
     * @param array{x:int,y:int,boxW:int,boxH:int,tail:int,tailUp:bool} $layout
     */
    private static function fillTailGd($image, array $layout, int $color): void
    {
        [$x1, $y1, $x2, $y2, $x3, $y3] = self::tailPoints($layout);
        imagefilledpolygon($image, [$x1, $y1, $x2, $y2, $x3, $y3], $color);
    }

    /**
     * @param array{x:int,y:int,boxW:int,boxH:int,tail:int,tailUp:bool} $layout
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int}
     */
    private static function tailPoints(array $layout): array
    {
        $cx = (int) ($layout['x'] + ($layout['boxW'] / 2));
        $half = (int) max(12, $layout['tail'] * 0.9);
        $inset = (int) max(8, $layout['tail'] * 0.45);
        if ($layout['tailUp']) {
            $base = $layout['y'] + $inset;
            $tip = $layout['y'] - $layout['tail'];
        } else {
            $base = $layout['y'] + $layout['boxH'] - $inset;
            $tip = $layout['y'] + $layout['boxH'] + $layout['tail'];
        }

        return [$cx - $half, $base, $cx + $half, $base, $cx, $tip];
    }

    /**
     * @param \GdImage $image
     */
    private static function washGd($image, int $width, int $from, int $to, bool $light, string $place): void
    {
        $span = max(1, $to - $from - 1);
        $place = PhrasePlace::normalize($place);
        [$r, $g, $b] = $light ? [255, 255, 255] : [14, 8, 4];
        $mid = ($from + $to) / 2;
        $half = max(1, ($to - $from) / 2);
        for ($y = $from; $y < $to; $y++) {
            $t = ($y - $from) / $span;
            $strength = match ($place) {
                PhrasePlace::TOPO => 1 - $t,
                PhrasePlace::MEIO => max(0, 1 - (abs($y - $mid) / $half)),
                default => $t * $t,
            };
            $alpha = (int) round(127 - (108 * $strength));
            $wash = imagecolorallocatealpha($image, $r, $g, $b, max(0, min(127, $alpha)));
            imageline($image, 0, $y, $width, $y, $wash);
        }
    }

    private static function washOrigin(int $height, int $band, string $place): int
    {
        return match (PhrasePlace::normalize($place)) {
            PhrasePlace::TOPO => 0,
            PhrasePlace::MEIO => (int) (($height - $band) / 2),
            default => max(0, $height - $band),
        };
    }

    private static function textOrigin(int $height, int $size, int $block, string $place): int
    {
        return match (PhrasePlace::normalize($place)) {
            PhrasePlace::TOPO => (int) max($size + 8, $height * 0.16),
            PhrasePlace::MEIO => (int) (($height - $block) / 2) + $size,
            default => $height - (int) max(18, $height * 0.045) - $block + $size,
        };
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
    private static function lines(string $phrase, int $width, int $max = 3): array
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

        return array_slice($lines, 0, $max);
    }

    private static function font(string $style): ?string
    {
        $file = match (PhraseStyle::normalize($style)) {
            PhraseStyle::CURSIVA => 'GreatVibes-Regular.ttf',
            PhraseStyle::LIMPA => 'SourceSans3-Bold.ttf',
            PhraseStyle::FORTE => 'Anton-Regular.ttf',
            PhraseStyle::BALAO => 'SourceSans3-Semibold.ttf',
            PhraseStyle::CAIXA => 'CourierPrime-Bold.ttf',
            default => 'LibreBaskerville-Bold.ttf',
        };
        $bundled = dirname(__DIR__, 2) . '/resources/fonts/' . $file;
        if (is_file($bundled)) {
            return $bundled;
        }
        if (PhraseStyle::normalize($style) === PhraseStyle::CAIXA) {
            foreach ([
                '/System/Library/Fonts/Supplemental/Courier New Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf',
                '/usr/share/fonts/liberation/LiberationMono-Bold.ttf',
            ] as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
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
