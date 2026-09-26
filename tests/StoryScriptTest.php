<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\StoryCard;
use PerfilEmDia\Image\StoryScript;
use PHPUnit\Framework\TestCase;

final class StoryScriptTest extends TestCase
{
    public function testStoryKeepsOnlyTheProse(): void
    {
        $caption = "O consultório abriu cedo e a sala já estava pronta.\n\n"
            . "A agenda de hoje está aberta. #dentista #consultorio\n\n"
            . "Acesse o WhatsApp (11) 99999-0000";

        $body = StoryScript::body($caption, 'Acesse o WhatsApp (11) 99999-0000');

        $this->assertStringContainsString('consultório', $body);
        $this->assertStringContainsString('agenda', $body);
        $this->assertStringNotContainsString('#', $body);
        $this->assertStringNotContainsString('Acesse', $body);
        $this->assertStringNotContainsString('WhatsApp', $body);
    }

    public function testTheStoryTextIsNotCut(): void
    {
        $short = 'Sala pronta para o próximo horário.';
        $this->assertCount(1, StoryScript::parts($short));
        $this->assertSame($short, StoryScript::parts($short)[0]);

        $prose = trim(str_repeat('A consulta de hoje mostrou um detalhe novo no prato. ', 5));
        $parts = StoryScript::parts($prose . "\n\n#saude\n\nAcesse o site");
        $this->assertGreaterThan(1, count($parts));
        $this->assertSame($prose, implode(' ', $parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(180, mb_strlen($part));
        }

        $fits = str_repeat('palavra ', 20);
        $fits = trim($fits);
        $this->assertLessThanOrEqual(180, mb_strlen($fits));
        $this->assertCount(1, StoryScript::parts($fits));
        $this->assertSame($fits, StoryScript::parts($fits)[0]);
        $joined = implode(' ', $parts);
        $this->assertStringNotContainsString('#', $joined);
        $this->assertStringNotContainsString('Acesse', $joined);
    }

    public function testTheBlockCoversAboutOneThird(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sty');
        $this->assertNotFalse($path);
        $image = imagecreatetruecolor(320, 600);
        $this->assertNotFalse($image);
        $green = imagecolorallocate($image, 40, 90, 50);
        imagefilledrectangle($image, 0, 0, 320, 600, $green);
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        StoryCard::draw($path, 'Sala pronta para o próximo horário.', 'branco', 'topo', 'normal', 'caixa');
        $drawn = imagecreatefromjpeg($path);
        $this->assertNotFalse($drawn);
        $darkRows = [];
        for ($y = 0; $y < 600; $y++) {
            $px = imagecolorat($drawn, 160, $y);
            $sum = (($px >> 16) & 255) + (($px >> 8) & 255) + ($px & 255);
            if ($sum < 140) {
                $darkRows[] = $y;
            }
        }
        $this->assertNotEmpty($darkRows);
        $span = max($darkRows) - min($darkRows);
        $this->assertLessThan(220, $span);
        $this->assertLessThan(200, min($darkRows));
        $side = imagecolorat($drawn, 4, (int) $darkRows[0]);
        $sideSum = (($side >> 16) & 255) + (($side >> 8) & 255) + ($side & 255);
        $this->assertGreaterThan(150, $sideSum);
        imagedestroy($drawn);
        unlink($path);
    }

    public function testTheCardSitsOnThePhoto(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sty');
        $this->assertNotFalse($path);
        $image = imagecreatetruecolor(320, 400);
        $this->assertNotFalse($image);
        $green = imagecolorallocate($image, 40, 90, 50);
        imagefilledrectangle($image, 0, 0, 320, 400, $green);
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        StoryCard::draw($path, 'Sala pronta para o próximo horário.', 'branco');
        $drawn = imagecreatefromjpeg($path);
        $this->assertNotFalse($drawn);
        $dark = 0;
        for ($y = 120; $y < 280; $y += 2) {
            for ($x = 30; $x < 290; $x += 2) {
                $px = imagecolorat($drawn, $x, $y);
                $sum = (($px >> 16) & 255) + (($px >> 8) & 255) + ($px & 255);
                if ($sum < 140) {
                    $dark++;
                }
            }
        }
        $this->assertGreaterThan(40, $dark);
        $corner = imagecolorat($drawn, 2, 2);
        $cornerSum = (($corner >> 16) & 255) + (($corner >> 8) & 255) + ($corner & 255);
        $this->assertGreaterThan(150, $cornerSum);
        imagedestroy($drawn);
        unlink($path);
    }

    public function testTheBalloonHugsTheTextAndStaysTranslucent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sty');
        $this->assertNotFalse($path);
        $image = imagecreatetruecolor(320, 600);
        $this->assertNotFalse($image);
        $green = imagecolorallocate($image, 40, 90, 50);
        imagefilledrectangle($image, 0, 0, 320, 600, $green);
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        StoryCard::draw($path, 'Sala pronta para o proximo horario.', 'branco', 'meio', 'normal', 'balao');
        $drawn = imagecreatefromjpeg($path);
        $this->assertNotFalse($drawn);
        $lightRows = [];
        for ($y = 0; $y < 600; $y++) {
            $px = imagecolorat($drawn, 160, $y);
            $sum = (($px >> 16) & 255) + (($px >> 8) & 255) + ($px & 255);
            if ($sum > 400 && $sum < 720) {
                $lightRows[] = $y;
            }
        }
        $this->assertNotEmpty($lightRows);
        $span = max($lightRows) - min($lightRows);
        $this->assertLessThan(160, $span);
        $sample = $lightRows[(int) (count($lightRows) / 2)];
        $side = imagecolorat($drawn, 4, $sample);
        $sideSum = (($side >> 16) & 255) + (($side >> 8) & 255) + ($side & 255);
        $this->assertLessThan(250, $sideSum);
        imagedestroy($drawn);
        unlink($path);
    }

    public function testAGeneratedStoryStopsAtThreePages(): void
    {
        $long = trim(str_repeat('A consulta de hoje mostrou um detalhe novo no prato. ', 16));
        $this->assertGreaterThan(540, mb_strlen($long));
        $parts = StoryScript::parts($long);
        $this->assertCount(3, $parts);
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(180, mb_strlen($part));
        }
        $this->assertStringStartsWith('A consulta', implode(' ', $parts));
        $this->assertLessThan(mb_strlen($long), mb_strlen(implode(' ', $parts)));
    }
}
