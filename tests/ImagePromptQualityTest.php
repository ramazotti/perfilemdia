<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Ai\SeriesPlanner;
use PerfilEmDia\Image\IdeaImage;
use PerfilEmDia\Image\IdeaLook;
use PerfilEmDia\Image\IdeaPieces;
use PHPUnit\Framework\TestCase;

/**
 * O pedido que chega ao modelo de imagem não pode se contradizer.
 */
final class ImagePromptQualityTest extends TestCase
{
    private const TEACHER = [
        'tone' => 'acolhedor',
        'profession' => 'Professora de ensino fundamental',
        'city' => 'Curitiba',
        'brand_style' => 'cores pastel',
    ];

    public function testDesignBriefHasPaletteAndTypeButNoScene(): void
    {
        $brief = IdeaLook::design(self::TEACHER);
        $prompt = IdeaImage::promptFor('Checklist: o que levar no primeiro dia de aula', false, $brief, '4:5');

        $this->assertStringContainsString('cores pastel', $prompt);
        $this->assertStringContainsString('Type:', $prompt);
        foreach (['Setting', 'natural light', 'classroom', 'Curitiba', 'Show that real work'] as $scene) {
            $this->assertStringNotContainsString($scene, $prompt, $scene);
        }
    }

    public function testDesignBriefWithoutBrandFallsBackToNichePalette(): void
    {
        $brief = IdeaLook::design(['about' => 'Exame de consciência e confissão']);
        $this->assertStringContainsString('gold', $brief);
    }

    public function testPhotoBriefOnlyUsesTheCityWhenTheIdeaIsAboutAPlace(): void
    {
        $this->assertStringNotContainsString('Curitiba', IdeaLook::brief(self::TEACHER, '', 'mãos segurando um giz'));
        $this->assertStringContainsString('Curitiba', IdeaLook::brief(self::TEACHER, '', 'a fachada da escola'));
    }

    public function testQuotesInAPhotoRequestNeverBecomeTextOnTheImage(): void
    {
        $prompt = IdeaImage::promptFor('Foto simples: homenagem "obrigado voluntários"', false, '', '4:5');

        $this->assertStringNotContainsString('Exact text', $prompt);
        $this->assertStringContainsString('Do not write any words', $prompt);
        $this->assertStringContainsString('People: no identifiable faces', $prompt);
    }

    public function testQuoteCreditIsPrintedApartFromTheNeverPrintedBrief(): void
    {
        $idea = "Cita\u{00e7}\u{00e3}o: Texto na imagem: \u{00ab}Se confessarmos os nossos pecados, ele \u{00e9} fiel.\u{00bb} Cr\u{00e9}dito: 1 Jo\u{00e3}o 1,9. Fundo azul.";
        $prompt = IdeaImage::promptFor($idea, false, '', '4:5');

        $this->assertStringContainsString('credit line under the main text, spelled as written: "1 Jo' . "\u{00e3}" . 'o 1,9"', $prompt);
        $never = substr($prompt, (int) strpos($prompt, 'never printed:'));
        $this->assertStringNotContainsString('1,9', $never);
        $this->assertStringNotContainsString('Se confessarmos', $never);
        $this->assertStringNotContainsString('..', $prompt);
    }

    public function testListedItemsAreTheExactText(): void
    {
        $prompt = IdeaImage::promptFor("Checklist: Item 1: B\u{00ed}blia Item 2: caderno; Item 3: caneta.", false, '', '4:5');

        $this->assertStringContainsString("\"B\u{00ed}blia\" | \"caderno\" | \"caneta\"", $prompt);
        $this->assertStringNotContainsString('write new short ones', $prompt);
    }

    public function testStoryDesignKeepsTextOutOfInstagramBars(): void
    {
        $prompt = IdeaImage::promptFor('Checklist: o que levar', false, '', '9:16');

        $this->assertStringContainsString('top 14%', $prompt);
        $this->assertStringContainsString('bottom 20%', $prompt);
    }

    public function testPlannedSlideGetsItsOwnTextAndFollowsSlideOneStyle(): void
    {
        $idea = "Infogr\u{00e1}fico com 3 slides: como se preparar para a confiss\u{00e3}o";
        $slide = ['title' => 'Faça o exame', 'text' => 'Reserve dez minutos de silêncio.'];
        $prompt = IdeaImage::promptFor($idea, false, '', '4:5', 2, $slide, true);

        $this->assertStringContainsString('headline "Faça o exame"', $prompt);
        $this->assertStringContainsString('body "Reserve dez minutos de silêncio."', $prompt);
        $this->assertStringContainsString('image 1 of this same series', $prompt);
        $this->assertStringContainsString('2/3 small in the bottom-right corner', $prompt);
    }

    public function testStepItemsGoOnePerSlide(): void
    {
        $idea = 'Passo a passo com 3: Passo 1: examine; Passo 2: confesse; Passo 3: cumpra a penitência';
        $this->assertCount(3, IdeaPieces::pieces($idea, '4:5'));
        $this->assertStringContainsString('"confesse"', IdeaImage::promptFor($idea, false, '', '4:5', 2));
    }

    public function testSeriesPlanDecodesOnlyACompletePlan(): void
    {
        $raw = "```json\n{\"slides\":[{\"title\":\"Capa\",\"text\":\"\"},{\"title\":\"Ponto\",\"text\":\"Um\"},{\"title\":\"Fim\",\"text\":\"Dois\"}]}\n```";
        $plan = SeriesPlanner::decode($raw, 3, false);
        $this->assertNotNull($plan);
        $this->assertSame('Ponto', $plan[1]['title']);

        $this->assertNull(SeriesPlanner::decode('{"slides":[{"title":"Só um"}]}', 3, false));
        $this->assertNull(SeriesPlanner::decode('sem json', 3, false));
        $this->assertNull(SeriesPlanner::decode('{"slides":[{"title":""},{"title":"a"},{"title":"b"}]}', 3, false));
    }
}
