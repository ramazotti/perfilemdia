<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\PhotoMark;
use PHPUnit\Framework\TestCase;

final class PhotoMarkTest extends TestCase
{
    public function testStampKeepsACircleAndLeavesTheOppositeCorner(): void
    {
        $photo = tempnam(sys_get_temp_dir(), 'mkp');
        $logo = tempnam(sys_get_temp_dir(), 'mkl');
        $this->assertNotFalse($photo);
        $this->assertNotFalse($logo);

        $canvas = imagecreatetruecolor(480, 640);
        $this->assertNotFalse($canvas);
        $red = imagecolorallocate($canvas, 180, 40, 30);
        imagefilledrectangle($canvas, 0, 0, 480, 640, $red);
        imagejpeg($canvas, $photo, 90);
        imagedestroy($canvas);

        $badge = imagecreatetruecolor(160, 160);
        $this->assertNotFalse($badge);
        $white = imagecolorallocate($badge, 255, 255, 255);
        $ink = imagecolorallocate($badge, 30, 40, 50);
        imagefilledrectangle($badge, 0, 0, 160, 160, $white);
        imagefilledrectangle($badge, 70, 20, 90, 140, $ink);
        imagefilledrectangle($badge, 30, 60, 130, 80, $ink);
        imagejpeg($badge, $logo, 90);
        imagedestroy($badge);

        $this->assertTrue(PhotoMark::stamp($photo, $logo, 'br'));
        $result = imagecreatefromjpeg($photo);
        $this->assertNotFalse($result);
        $corner = imagecolorat($result, 4, 4);
        $this->assertGreaterThan(140, ($corner >> 16) & 255);
        $dark = 0;
        $paper = 0;
        for ($y = 500; $y < 630; $y += 2) {
            for ($x = 330; $x < 470; $x += 2) {
                $color = imagecolorat($result, $x, $y);
                $red = ($color >> 16) & 255;
                $green = ($color >> 8) & 255;
                $blue = $color & 255;
                if ($red < 80 && $green < 90 && $blue < 100) {
                    $dark++;
                }
                if ($red > 235 && $green > 235 && $blue > 235) {
                    $paper++;
                }
            }
        }
        $this->assertGreaterThan(8, $dark);
        $this->assertGreaterThan(8, $paper);
        imagedestroy($result);
        unlink($photo);
        unlink($logo);
    }

    public function testUploadedLogoStaysWideInsteadOfACircle(): void
    {
        $photo = tempnam(sys_get_temp_dir(), 'mkp');
        $logo = tempnam(sys_get_temp_dir(), 'mkl');
        $this->assertNotFalse($photo);
        $this->assertNotFalse($logo);

        $canvas = imagecreatetruecolor(480, 640);
        $this->assertNotFalse($canvas);
        $red = imagecolorallocate($canvas, 180, 40, 30);
        imagefilledrectangle($canvas, 0, 0, 480, 640, $red);
        imagejpeg($canvas, $photo, 90);
        imagedestroy($canvas);

        $badge = imagecreatetruecolor(220, 50);
        $this->assertNotFalse($badge);
        imagealphablending($badge, false);
        imagesavealpha($badge, true);
        $clear = imagecolorallocatealpha($badge, 0, 0, 0, 127);
        imagefilledrectangle($badge, 0, 0, 220, 50, $clear);
        $white = imagecolorallocatealpha($badge, 255, 255, 255, 0);
        imagefilledrectangle($badge, 0, 10, 219, 40, $white);
        imagepng($badge, $logo);
        imagedestroy($badge);

        $this->assertTrue(PhotoMark::stampPlate($photo, $logo, 'br'));
        $result = imagecreatefromjpeg($photo);
        $this->assertNotFalse($result);
        $corner = imagecolorat($result, 4, 4);
        $this->assertGreaterThan(140, ($corner >> 16) & 255);
        $whiteXs = [];
        for ($y = 560; $y < 630; $y += 2) {
            for ($x = 300; $x < 470; $x += 2) {
                $color = imagecolorat($result, $x, $y);
                if ((($color >> 16) & 255) > 230 && (($color >> 8) & 255) > 230 && ($color & 255) > 230) {
                    $whiteXs[] = $x;
                }
            }
        }
        $this->assertNotEmpty($whiteXs);
        $this->assertGreaterThan(70, max($whiteXs) - min($whiteXs));
        imagedestroy($result);
        unlink($photo);
        unlink($logo);
    }
}
