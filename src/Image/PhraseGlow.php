<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class PhraseGlow
{
    /**
     * Halo esfumaçado atrás da letra. Texto claro leva halo preto. Texto escuro leva halo branco.
     *
     * @param \GdImage $image
     * @param list<array{0:int,1:int,2:int,3:string,4:string}> $glyphs
     */
    public static function gd($image, array $glyphs, bool $dark): void
    {
        if ($glyphs === []) {
            return;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            return;
        }

        $mask = imagecreatetruecolor($width, $height);
        if ($mask === false) {
            return;
        }
        $black = imagecolorallocate($mask, 0, 0, 0);
        $white = imagecolorallocate($mask, 255, 255, 255);
        if ($black === false || $white === false) {
            imagedestroy($mask);

            return;
        }
        imagefilledrectangle($mask, 0, 0, $width, $height, $black);
        foreach ($glyphs as [$x, $y, $size, $font, $text]) {
            foreach ([[0, 0], [-2, 0], [2, 0], [0, -2], [0, 2]] as [$dx, $dy]) {
                imagettftext($mask, $size, 0, $x + $dx, $y + $dy, $white, $font, $text);
            }
        }

        $smallWidth = max(1, (int) ($width / 2));
        $smallHeight = max(1, (int) ($height / 2));
        $small = imagecreatetruecolor($smallWidth, $smallHeight);
        if ($small === false) {
            imagedestroy($mask);

            return;
        }
        imagecopyresampled($small, $mask, 0, 0, 0, 0, $smallWidth, $smallHeight, $width, $height);
        for ($pass = 0; $pass < 8; $pass++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagecopyresampled($mask, $small, 0, 0, 0, 0, $width, $height, $smallWidth, $smallHeight);
        imagedestroy($small);

        [$red, $green, $blue] = $dark ? [0, 0, 0] : [255, 255, 255];
        $shades = [];
        for ($alpha = 0; $alpha <= 127; $alpha++) {
            $shade = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
            if ($shade !== false) {
                $shades[$alpha] = $shade;
            }
        }
        imagealphablending($image, true);
        for ($py = 0; $py < $height; $py++) {
            for ($px = 0; $px < $width; $px++) {
                $luma = (imagecolorat($mask, $px, $py) >> 16) & 255;
                if ($luma < 2) {
                    continue;
                }
                $alpha = 127 - (int) min(127, (int) round($luma * 1.2));
                $alpha = max(0, min(127, $alpha));
                if (isset($shades[$alpha])) {
                    imagesetpixel($image, $px, $py, $shades[$alpha]);
                }
            }
        }
        imagedestroy($mask);
    }

    /**
     * @param list<array{x:int,y:int,text:string}> $lines
     */
    public static function imagick(\Imagick $image, string $font, int $size, array $lines, bool $center, bool $dark): void
    {
        if ($lines === []) {
            return;
        }
        $layer = new \Imagick();
        $layer->newImage($image->getImageWidth(), $image->getImageHeight(), new \ImagickPixel('transparent'));
        $layer->setImageFormat('png');
        $draw = new \ImagickDraw();
        $draw->setFont($font);
        $draw->setFontSize($size);
        if ($center) {
            $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        }
        $draw->setFillColor(new \ImagickPixel($dark ? '#000000' : '#FFFFFF'));
        foreach ($lines as $line) {
            $layer->annotateImage($draw, $line['x'], $line['y'], 0, $line['text']);
        }
        $layer->blurImage(0, max(6.0, $size * 0.22));
        $layer->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 1.8, \Imagick::CHANNEL_ALPHA);
        $image->compositeImage($layer, \Imagick::COMPOSITE_OVER, 0, 0);
        $layer->clear();
        $layer->destroy();
    }
}
