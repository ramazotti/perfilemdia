<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class StoryCard
{
    private const BUBBLE_TEXT_MARGIN = 20;

    private const CAIXA_TEXT_MARGIN = 5;

    public static function draw(
        string $jpegPath,
        string $text,
        string $color = PhraseColor::BRANCO,
        string $place = PhrasePlace::MEIO,
        string $size = PhraseSize::NORMAL,
        string $style = PhraseStyle::CAIXA,
    ): void {
        $text = trim($text);
        if ($text === '' || !is_file($jpegPath)) {
            return;
        }
        $color = PhraseColor::normalize($color);
        $place = PhrasePlace::normalize($place);
        $size = PhraseSize::normalize($size);
        $style = $style === '' ? PhraseStyle::CAIXA : PhraseStyle::storyChoice($style);
        $font = self::font();
        if (extension_loaded('imagick') && class_exists(\Imagick::class) && $font !== null) {
            try {
                self::drawImagick($jpegPath, $text, $color, $place, $size, $style, $font);

                return;
            } catch (\Throwable) {
            }
        }
        self::drawGd($jpegPath, $text, $color, $place, $size, $style, $font);
    }

    private static function drawGd(string $jpegPath, string $text, string $color, string $place, string $sizeName, string $style, ?string $font): void
    {
        $image = @imagecreatefromjpeg($jpegPath);
        if ($image === false) {
            return;
        }
        imagealphablending($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        if ($font !== null) {
            $fitted = self::fitBlock($width, $height, $text, $sizeName, static function (int $size, string $line) use ($font): int {
                $box = imagettfbbox($size, 0, $font, $line);

                return is_array($box) ? (int) ($box[2] - $box[0]) : $size * 4;
            });
            $lines = $fitted['lines'];
            $size = $fitted['size'];
            $widths = $fitted['widths'];
        } else {
            $lines = self::lines($text, 28);
            $size = 5;
            $widths = [40];
        }
        $layout = self::layout($width, $height, $widths, $size, $place, $style);
        [$ir, $ig, $ib] = $style === PhraseStyle::BALAO ? [20, 16, 14] : PhraseColor::ink($color);
        $ink = imagecolorallocate($image, $ir, $ig, $ib);
        $marker = false;
        if ($style === PhraseStyle::CAIXA) {
            [$mr, $mg, $mb] = self::markerRgb($color);
            $marker = imagecolorallocatealpha($image, $mr, $mg, $mb, self::markerAlpha());
        }
        if ($ink !== false && $font !== null) {
            $probe = imagettfbbox($size, 0, $font, $lines[0] !== '' ? $lines[0] : 'A');
            $ascent = is_array($probe) ? abs((int) $probe[7]) : (int) ($size * 0.8);
            $textBlock = $layout['lineHeight'] * max(1, count($lines));
            $baseline = $layout['y'] + (int) (($layout['boxH'] - $textBlock) / 2) + $ascent;
            if ($style === PhraseStyle::BALAO) {
                self::paintBubbleGd($image, self::bubble($layout, $widths, $size, count($lines), $baseline, $ascent));
            }
            if ($style === PhraseStyle::CAIXA && $marker !== false) {
                self::paintCaixaGd($image, $layout, $widths, $lines, $size, $font, $baseline, $ascent, $marker);
            }
            $shadow = null;
            if ($style === PhraseStyle::LIMPA) {
                $sum = $ir + $ig + $ib;
                $shadow = imagecolorallocate($image, $sum > 380 ? 12 : 255, $sum > 380 ? 12 : 255, $sum > 380 ? 12 : 255);
            }
            foreach ($lines as $i => $line) {
                $box = imagettfbbox($size, 0, $font, $line);
                $textWidth = is_array($box) ? (int) ($box[2] - $box[0]) : 0;
                $tx = (int) ($layout['x'] + (($layout['boxW'] - $textWidth) / 2));
                $ty = $baseline + ($i * $layout['lineHeight']);
                if ($shadow !== false && $shadow !== null) {
                    imagettftext($image, $size, 0, $tx + 2, $ty + 2, $shadow, $font, $line);
                }
                imagettftext($image, $size, 0, $tx, $ty, $ink, $font, $line);
            }
        }
        imagejpeg($image, $jpegPath, 90);
        imagedestroy($image);
    }

    private static function drawImagick(string $jpegPath, string $text, string $color, string $place, string $sizeName, string $style, string $font): void
    {
        $image = new \Imagick($jpegPath);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $probe = new \ImagickDraw();
        $probe->setFont($font);
        $fitted = self::fitBlock($width, $height, $text, $sizeName, static function (int $size, string $line) use ($probe, $image): int {
            $probe->setFontSize($size);
            $metrics = $image->queryFontMetrics($probe, $line);

            return (int) ($metrics['textWidth'] ?? $size * 4);
        });
        $lines = $fitted['lines'];
        $size = $fitted['size'];
        $widths = $fitted['widths'];
        $layout = self::layout($width, $height, $widths, $size, $place, $style);
        $inkHex = $style === PhraseStyle::BALAO ? '#14100E' : PhraseColor::inkHex($color);
        $probe->setFontSize($size);
        $metrics = $image->queryFontMetrics($probe, $lines[0] !== '' ? $lines[0] : 'A');
        $ascent = (int) ($metrics['ascender'] ?? (int) ($size * 0.8));
        $descent = (int) abs($metrics['descender'] ?? (int) ($size * 0.25));
        $textBlock = $layout['lineHeight'] * max(1, count($lines));
        $baseline = $layout['y'] + (int) (($layout['boxH'] - $textBlock) / 2) + $ascent;
        $cx = (int) ($layout['x'] + ($layout['boxW'] / 2));
        if ($style === PhraseStyle::BALAO) {
            $bubble = self::bubble($layout, $widths, $size, count($lines), $baseline, $ascent);
            $shape = new \ImagickDraw();
            $shape->setFillColor(new \ImagickPixel(sprintf('rgba(255,255,255,%.2f)', self::bubbleOpacity())));
            $shape->roundRectangle(
                $bubble['x'],
                $bubble['y'],
                $bubble['x'] + $bubble['w'],
                $bubble['y'] + $bubble['h'],
                $bubble['radius'],
                $bubble['radius']
            );
            $image->drawImage($shape);
            self::tailImagick($image, $bubble);
        }
        if ($style === PhraseStyle::CAIXA) {
            [$mr, $mg, $mb] = self::markerRgb($color);
            $mark = new \ImagickDraw();
            $mark->setFillColor(new \ImagickPixel(sprintf('rgba(%d,%d,%d,%.2f)', $mr, $mg, $mb, self::markerOpacity())));
            $lastLine = $lines[array_key_last($lines)] ?? 'A';
            $lastMetrics = $image->queryFontMetrics($probe, $lastLine);
            $lastDescent = (int) abs($lastMetrics['descender'] ?? $descent);
            $caixa = self::caixaFrame($layout, $widths, count($lines), $size, $baseline, $ascent, $lastDescent, $layout['lineHeight']);
            $mark->roundRectangle(
                $caixa['x'],
                $caixa['y'],
                $caixa['x'] + $caixa['w'],
                $caixa['y'] + $caixa['h'],
                $caixa['radius'],
                $caixa['radius'],
            );
            $image->drawImage($mark);
        }
        if ($style === PhraseStyle::LIMPA) {
            $rgb = PhraseColor::ink($color);
            $shadowHex = ($rgb[0] + $rgb[1] + $rgb[2]) > 380 ? '#111111' : '#FFFFFF';
            $shadow = new \ImagickDraw();
            $shadow->setFont($font);
            $shadow->setFontSize($size);
            $shadow->setTextAlignment(\Imagick::ALIGN_CENTER);
            $shadow->setFillColor(new \ImagickPixel($shadowHex));
            foreach ($lines as $i => $line) {
                $image->annotateImage($shadow, $cx + 2, $baseline + ($i * $layout['lineHeight']) + 2, 0, $line);
            }
        }
        $ink = new \ImagickDraw();
        $ink->setFont($font);
        $ink->setFontSize($size);
        $ink->setTextAlignment(\Imagick::ALIGN_CENTER);
        $ink->setFillColor(new \ImagickPixel($inkHex));
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
     * @return array{x:int,y:int,boxW:int,boxH:int,padY:int,radius:int,lineHeight:int}
     */
    private static function layout(int $width, int $height, array $widths, int $size, string $place, string $style): array
    {
        $padY = (int) max(8, $size * 0.32);
        $lineHeight = (int) ($size * 1.8);
        $tail = 0;
        $boxW = max(40, $width - (int) max(24, round($width * 0.06)));
        $boxH = (int) max(56, (int) round($height / 3) - $tail);
        $boxH = min($boxH, (int) round($height * 0.38) - $tail);
        $radius = min(22, (int) ($size * 0.55), (int) ($boxW / 2), (int) ($boxH / 2));
        $x = 8;
        $margin = (int) max(6, (int) round($height * 0.02));
        $y = match (PhrasePlace::normalize($place)) {
            PhrasePlace::TOPO => $margin,
            PhrasePlace::RODAPE => max($margin, $height - $boxH - $margin - $tail),
            default => (int) max($margin, ($height - $boxH) / 2),
        };

        return [
            'x' => $x,
            'y' => $y,
            'boxW' => $boxW,
            'boxH' => $boxH,
            'padY' => $padY,
            'radius' => $radius,
            'lineHeight' => $lineHeight,
            'place' => PhrasePlace::normalize($place),
            'tail' => $tail,
        ];
    }

    /**
     * @param array{x:int,y:int,boxW:int,boxH:int,padY:int,radius:int,lineHeight:int,place:string,tail:int} $layout
     * @param list<int> $widths
     * @return array{x:int,y:int,w:int,h:int,radius:int,tail:int,place:string}
     */
    private static function bubble(array $layout, array $widths, int $size, int $lineCount, int $baseline, int $ascent): array
    {
        $padX = (int) max(self::BUBBLE_TEXT_MARGIN, $size * 0.12);
        $padY = (int) max(self::BUBBLE_TEXT_MARGIN, $size * 0.04);
        $textW = max(1, ...$widths);
        $w = $textW + ($padX * 2);
        $textH = $ascent + (int) max(2, $size * 0.2) + ($layout['lineHeight'] * max(0, $lineCount - 1));
        $h = $textH + ($padY * 2);
        $x = (int) ($layout['x'] + (($layout['boxW'] - $w) / 2));
        $y = $baseline - $ascent - $padY;

        return [
            'x' => max(0, $x),
            'y' => max(0, $y),
            'w' => $w,
            'h' => $h,
            'radius' => (int) max(6, min((int) ($h / 2), (int) ($size * 0.42))),
            'tail' => (int) max(8, $size * 0.32),
            'place' => $layout['place'],
        ];
    }

    /**
     * @param \GdImage $image
     * @param array{x:int,y:int,w:int,h:int,radius:int,tail:int,place:string} $bubble
     */
    private static function paintBubbleGd($image, array $bubble): void
    {
        $tail = $bubble['tail'];
        $up = $bubble['place'] === PhrasePlace::RODAPE;
        $lw = $bubble['w'];
        $lh = $bubble['h'] + $tail;
        $layer = imagecreatetruecolor($lw, $lh);
        if ($layer === false) {
            return;
        }
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        $clear = imagecolorallocatealpha($layer, 0, 0, 0, 127);
        if ($clear === false) {
            imagedestroy($layer);

            return;
        }
        imagefilledrectangle($layer, 0, 0, $lw, $lh, $clear);
        imagealphablending($layer, true);
        $white = imagecolorallocate($layer, 255, 255, 255);
        if ($white === false) {
            imagedestroy($layer);

            return;
        }
        $by = $up ? $tail : 0;
        self::round($layer, 0, $by, $bubble['w'], $bubble['h'], $bubble['radius'], $white);
        $mid = (int) ($bubble['w'] / 2);
        $half = (int) max(7, $tail * 0.7);
        if ($up) {
            $points = [$mid - $half, $by, $mid + $half, $by, $mid, 0];
        } else {
            $bottom = $by + $bubble['h'];
            $points = [$mid - $half, $bottom, $mid + $half, $bottom, $mid, $lh - 1];
        }
        imagefilledpolygon($layer, $points, $white);
        self::blendLayer($image, $layer, $bubble['x'], $bubble['y'] - ($up ? $tail : 0), self::bubbleOpacity());
        imagedestroy($layer);
    }

    /**
     * @param \GdImage $dest
     * @param \GdImage $layer
     */
    private static function blendLayer($dest, $layer, int $dx, int $dy, float $opacity): void
    {
        $lw = imagesx($layer);
        $lh = imagesy($layer);
        $dw = imagesx($dest);
        $dh = imagesy($dest);
        for ($y = 0; $y < $lh; $y++) {
            $ty = $dy + $y;
            if ($ty < 0 || $ty >= $dh) {
                continue;
            }
            for ($x = 0; $x < $lw; $x++) {
                $tx = $dx + $x;
                if ($tx < 0 || $tx >= $dw) {
                    continue;
                }
                $px = imagecolorat($layer, $x, $y);
                if ((($px >> 24) & 127) > 100) {
                    continue;
                }
                $dp = imagecolorat($dest, $tx, $ty);
                $r = (int) round(((($px >> 16) & 255) * $opacity) + (((($dp >> 16) & 255) * (1 - $opacity))));
                $g = (int) round(((($px >> 8) & 255) * $opacity) + (((($dp >> 8) & 255) * (1 - $opacity))));
                $b = (int) round((($px & 255) * $opacity) + ((($dp & 255) * (1 - $opacity))));
                $color = imagecolorallocate($dest, $r, $g, $b);
                if ($color !== false) {
                    imagesetpixel($dest, $tx, $ty, $color);
                }
            }
        }
    }

    /**
     * @param array{x:int,y:int,w:int,h:int,radius:int,tail:int,place:string} $bubble
     */
    private static function tailImagick(\Imagick $image, array $bubble): void
    {
        $mid = $bubble['x'] + (int) ($bubble['w'] / 2);
        $half = (int) max(7, $bubble['tail'] * 0.7);
        $tail = $bubble['tail'];
        if ($bubble['place'] === PhrasePlace::RODAPE) {
            $points = [
                ['x' => $mid - $half, 'y' => $bubble['y']],
                ['x' => $mid + $half, 'y' => $bubble['y']],
                ['x' => $mid, 'y' => $bubble['y'] - $tail],
            ];
        } else {
            $bottom = $bubble['y'] + $bubble['h'];
            $points = [
                ['x' => $mid - $half, 'y' => $bottom],
                ['x' => $mid + $half, 'y' => $bottom],
                ['x' => $mid, 'y' => $bottom + $tail],
            ];
        }
        $shape = new \ImagickDraw();
        $shape->setFillColor(new \ImagickPixel(sprintf('rgba(255,255,255,%.2f)', self::bubbleOpacity())));
        $shape->polygon($points);
        $image->drawImage($shape);
    }

    /**
     * @param \GdImage $image
     */
    private static function round($image, int $x, int $y, int $w, int $h, int $r, int $color): void
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
     * @param callable(int, string): int $measure
     * @return array{lines:list<string>,size:int,widths:list<int>}
     */
    private static function fitBlock(int $width, int $height, string $text, string $sizeName, callable $measure): array
    {
        $band = (int) max(140, (int) round($height / 3));
        $wrap = max(34, min(54, (int) round($width / 24)));
        $lines = self::lines($text, $wrap);
        $size = (int) round(self::fit($width, $lines) * PhraseSize::factor($sizeName));
        $size = (int) max(16, $size);
        $widths = [40];
        for ($try = 0; $try < 18; $try++) {
            $lines = self::lines($text, $wrap);
            $widths = [];
            foreach ($lines as $line) {
                $widths[] = max(1, $measure($size, $line));
            }
            $padY = (int) max(10, $size * 0.45);
            $lineHeight = (int) ($size * 1.8);
            $boxH = ($padY * 2) + ($lineHeight * max(1, count($lines)));
            $tooWide = max($widths) > (int) ($width * 0.88);
            $tooTall = $boxH > $band;
            if (!$tooWide && !$tooTall) {
                break;
            }
            if ($tooWide && $wrap < 54) {
                $wrap = min(54, $wrap + 3);

                continue;
            }
            if ($tooTall && $wrap < 54) {
                $wrap = min(54, $wrap + 4);

                continue;
            }
            $next = (int) max(16, (int) ($size * 0.92));
            if ($next === $size) {
                break;
            }
            $size = $next;
        }

        return ['lines' => $lines, 'size' => $size, 'widths' => $widths];
    }

    /**
     * @param list<string> $lines
     */
    private static function fit(int $width, array $lines): int
    {
        $longest = 1;
        foreach ($lines as $line) {
            $longest = max($longest, mb_strlen($line));
        }
        $byWidth = (int) (($width * 0.82) / max(1, $longest * 0.52));

        return (int) max(20, min((int) ($width / 13), $byWidth));
    }

    /**
     * @param list<int> $widths
     * @param array{x:int,y:int,boxW:int,boxH:int,padY:int,radius:int,lineHeight:int,place:string,tail:int} $layout
     * @return array{x:int,y:int,w:int,h:int,radius:int}
     */
    private static function caixaFrame(
        array $layout,
        array $widths,
        int $lineCount,
        int $size,
        int $baseline,
        int $ascent,
        int $lastDescent,
        int $lineHeight,
    ): array {
        [$padX, $padY] = self::caixaPadding($size);
        $maxW = max(1, ...$widths);
        $lineCount = max(1, $lineCount);
        $cx = (int) ($layout['x'] + ($layout['boxW'] / 2));
        $top = $baseline - $ascent - $padY;
        $bottom = $baseline + (($lineCount - 1) * $lineHeight) + $lastDescent + $padY;
        $w = $maxW + ($padX * 2);
        $h = max(1, $bottom - $top);
        $x = (int) ($cx - ($w / 2));

        return [
            'x' => max(0, $x),
            'y' => max(0, $top),
            'w' => $w,
            'h' => $h,
            'radius' => self::caixaCornerRadius($h, $w, $size),
        ];
    }

    /**
     * @param list<string> $lines
     * @param list<int> $widths
     * @param array{x:int,y:int,boxW:int,boxH:int,padY:int,radius:int,lineHeight:int,place:string,tail:int} $layout
     */
    private static function paintCaixaGd(
        $image,
        array $layout,
        array $widths,
        array $lines,
        int $size,
        string $font,
        int $baseline,
        int $ascent,
        int $marker,
    ): void {
        $last = $lines[array_key_last($lines)] ?? 'A';
        $lastBox = imagettfbbox($size, 0, $font, $last);
        $lastDescent = is_array($lastBox) ? max(0, (int) $lastBox[1]) : (int) ($size * 0.25);
        $frame = self::caixaFrame(
            $layout,
            $widths,
            count($lines),
            $size,
            $baseline,
            $ascent,
            $lastDescent,
            $layout['lineHeight'],
        );
        self::round($image, $frame['x'], $frame['y'], $frame['w'], $frame['h'], $frame['radius'], $marker);
    }

    /**
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    private static function lines(string $text, int $wrap): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $next = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($next) > $wrap && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $next;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [$text] : $lines;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function markerRgb(string $color): array
    {
        return PhraseColor::normalize($color) === PhraseColor::PRETO ? [255, 255, 255] : [12, 12, 12];
    }

    private static function markerOpacity(): float
    {
        return 0.90;
    }

    private static function markerAlpha(): int
    {
        return (int) round((1 - self::markerOpacity()) * 127);
    }

    private static function bubbleOpacity(): float
    {
        return 0.90;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function caixaPadding(int $size): array
    {
        return [
            (int) max(self::CAIXA_TEXT_MARGIN, $size * 0.42),
            (int) max(self::CAIXA_TEXT_MARGIN, $size * 0.08),
        ];
    }

    private static function caixaCornerRadius(int $height, int $width, int $fontSize): int
    {
        $height = max(1, $height);
        $width = max(1, $width);

        return (int) max(4, min(
            (int) ($height / 2),
            (int) max(6, round($fontSize * 0.26)),
            (int) ($width / 2),
        ));
    }

    private static function font(): ?string
    {
        $bundled = dirname(__DIR__, 2) . '/resources/fonts/SourceSans3-Semibold.ttf';
        if (is_file($bundled)) {
            return $bundled;
        }
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
