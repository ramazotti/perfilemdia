<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\IdeaImage;
use PHPUnit\Framework\TestCase;

final class IdeaImagePromptTest extends TestCase
{
    public function testInfographicWordIsRecognizedWithOrWithoutAccent(): void
    {
        $this->assertTrue(IdeaImage::isInfographic("Quero um infogr\u{00e1}fico com 3 passos"));
        $this->assertTrue(IdeaImage::isInfographic('monta um infografico do jejum'));
        $this->assertFalse(IdeaImage::isInfographic('Foto da consulta de hoje'));
    }

    public function testInfographicPromptAsksForTypeInsideThePicture(): void
    {
        $prompt = IdeaImage::promptFor("Infogr\u{00e1}fico: 3 h\u{00e1}bitos do sono", false, '', '9:16');

        $this->assertStringContainsString('infographic', $prompt);
        $this->assertStringContainsString('9:16', $prompt);
        $this->assertStringContainsString('Brazilian Portuguese', $prompt);
        $this->assertStringNotContainsString('No text, letters', $prompt);
    }

    public function testPhotoPromptStillForbidsText(): void
    {
        $prompt = IdeaImage::promptFor("Manh\u{00e3} calma na recep\u{00e7}\u{00e3}o", false, '', '4:5');

        $this->assertStringContainsString('No text, letters, numbers, logos, or watermarks.', $prompt);
        $this->assertStringContainsString('4:5', $prompt);
    }
}
