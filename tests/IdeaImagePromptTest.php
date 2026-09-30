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

    public function testInfographicUsesTheCheaperTextModel(): void
    {
        $story = IdeaImage::requestOptions("Infogr\u{00e1}fico dos 7 pecados", '9:16');
        $feed = IdeaImage::requestOptions('infografico do jejum', '4:5');
        $photo = IdeaImage::requestOptions('Foto da consulta', '4:5');

        $this->assertSame('openai/gpt-image-2', $story['model']);
        $this->assertSame('medium', $story['quality']);
        $this->assertSame('9:16', $story['aspect_ratio']);
        $this->assertNull($story['output_format']);
        $this->assertSame('3:4', $feed['aspect_ratio']);
        $this->assertNull($photo['model']);
        $this->assertSame('2K', $photo['resolution']);
        $this->assertSame('jpeg', $photo['output_format']);
    }

    public function testPhotoPromptStillForbidsText(): void
    {
        $prompt = IdeaImage::promptFor("Manh\u{00e3} calma na recep\u{00e7}\u{00e3}o", false, '', '4:5');

        $this->assertStringContainsString('No text, letters, numbers, logos, or watermarks.', $prompt);
        $this->assertStringContainsString('4:5', $prompt);
    }
}
