<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\IdeaImage;
use PerfilEmDia\Image\IdeaPieces;
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

    public function testWordsPickAFormatAndASlideCount(): void
    {
        $slides = IdeaImage::pieces("Infogr\u{00e1}fico com 3 slides sobre o jejum", '4:5');
        $this->assertCount(3, $slides);
        $this->assertSame('infographic', $slides[0]['kind']);
        $this->assertSame(1, $slides[0]['index']);
        $this->assertSame(3, $slides[2]['count']);

        $cover = IdeaImage::promptFor("Infogr\u{00e1}fico com 3 slides sobre o jejum", false, '', '4:5', 1);
        $close = IdeaImage::promptFor("Infogr\u{00e1}fico com 3 slides sobre o jejum", false, '', '4:5', 3);
        $this->assertStringContainsString('cover', $cover);
        $this->assertStringContainsString('closing', $close);
        $this->assertStringContainsString('1/3', $cover);

        $this->assertSame('quote', IdeaImage::pieces("Uma cita\u{00e7}\u{00e3}o sobre o sil\u{00ea}ncio", '9:16')[0]['kind']);
        $this->assertSame('compare', IdeaImage::pieces('Comparativo entre jejum e refeicao', '4:5')[0]['kind']);
        $this->assertSame('checklist', IdeaImage::pieces('Checklist da consulta', '4:5')[0]['kind']);
        $this->assertCount(1, IdeaImage::pieces('Passo a passo da consulta', '4:5'));
        $this->assertCount(4, IdeaImage::pieces('Passo a passo com 4 etapas', '4:5'));
        $this->assertCount(2, IdeaImage::pieces('Antes e depois do tratamento', '4:5'));
        $this->assertCount(3, IdeaImage::pieces("Infogr\u{00e1}ficos em sequ\u{00ea}ncia", '4:5'));
        $this->assertCount(3, IdeaImage::pieces('Story com 5 telas', '9:16'));
        $this->assertCount(10, IdeaImage::pieces('Carrossel com 12 slides', '4:5'));
        $this->assertCount(3, IdeaImage::pieces("Tr\u{00ea}s slides sobre a ora\u{00e7}\u{00e3}o", '4:5'));

        $quote = IdeaImage::requestOptions("Cita\u{00e7}\u{00e3}o do dia", '9:16');
        $this->assertSame('openai/gpt-image-2', $quote['model']);
        $this->assertTrue(IdeaImage::isDesigned('Checklist da semana'));
        $this->assertFalse(IdeaImage::isDesigned('Foto da consulta de hoje'));
    }

    public function testRandomCuesCoverTheFormats(): void
    {
        $feed = IdeaPieces::cues('4:5');
        $story = IdeaPieces::cues('9:16');
        $this->assertSame('', $feed[0]);
        $this->assertFalse(IdeaImage::isDesigned('preparar a confissao'));
        foreach (array_slice($feed, 1) as $cue) {
            $this->assertTrue(IdeaImage::isDesigned($cue . ' preparar a confissao'), $cue);
        }
        $this->assertCount(3, IdeaImage::pieces($feed[2] . ' o exame', '4:5'));
        $this->assertCount(3, IdeaImage::pieces($story[2] . ' o exame', '9:16'));
        $this->assertCount(2, IdeaImage::pieces('Antes e depois: a confissao', '4:5'));
        $this->assertSame('quote', IdeaImage::pieces('Citacao: o perdao', '4:5')[0]['kind']);
    }

    public function testAssignmentIsNotCopiedOntoTheImage(): void
    {
        $idea = "Passo a passo: Mostre o bastidor, antes de ficar pronto. Para Site examedeconsciencia.com.br. Visual: limpo e contemplativo.";
        $prompt = IdeaImage::promptFor($idea, false, '', '4:5');

        $this->assertStringContainsString('never printed', $prompt);
        $this->assertStringContainsString('No sentence from the assignment may appear', $prompt);
        $this->assertStringContainsString('Mostre o bastidor, antes de ficar pronto.', $prompt);
        $this->assertStringNotContainsString('The request: Passo a passo:', $prompt);
        $this->assertStringNotContainsString('taken from the request', $prompt);
        $this->assertStringContainsString('Do not print the format name', $prompt);
    }

    public function testQuotedWordsAreTheOnlyExactText(): void
    {
        $prompt = IdeaImage::promptFor('Cita' . "\u{00e7}\u{00e3}" . 'o: use s' . "\u{00f3}" . ' a frase "Hoje, de perto."', false, '', '9:16');

        $this->assertStringContainsString('Exact text on the image', $prompt);
        $this->assertStringContainsString('"Hoje, de perto."', $prompt);
    }

    public function testPhotoPromptStillForbidsText(): void
    {
        $prompt = IdeaImage::promptFor("Manh\u{00e3} calma na recep\u{00e7}\u{00e3}o", false, '', '4:5');

        $this->assertStringContainsString('No text, letters, numbers, logos, or watermarks.', $prompt);
        $this->assertStringContainsString('4:5', $prompt);
    }
}
