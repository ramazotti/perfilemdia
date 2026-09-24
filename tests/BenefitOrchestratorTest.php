<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Growth\BenefitOrchestrator;
use PHPUnit\Framework\TestCase;

final class BenefitOrchestratorTest extends TestCase
{
    public function testChildrensDaySuggestsAConcretePhoto(): void
    {
        $now = new DateTimeImmutable('2026-10-12 09:00:00', new DateTimeZone('America/Sao_Paulo'));
        $text = BenefitOrchestrator::suggestion([
            'profession' => 'loja',
            'city' => 'Maringa',
            'brand_style' => 'verde e bege',
        ], $now);

        $this->assertStringContainsString('crian', $text);
        $this->assertStringContainsString('loja', $text);
        $this->assertStringContainsString('Maringa', $text);
        $this->assertStringContainsString('verde e bege', $text);
        $date = BenefitOrchestrator::commemorative($now);
        $this->assertNotNull($date);
        $this->assertSame('Dia das crianças', $date['name']);
    }

    public function testOrdinaryDayStillHasAnIdea(): void
    {
        $now = new DateTimeImmutable('2026-09-23 09:00:00', new DateTimeZone('America/Sao_Paulo'));
        $this->assertNull(BenefitOrchestrator::commemorative($now));
        $message = BenefitOrchestrator::ideaMessage(['profession' => ''], $now);
        $this->assertStringContainsString('Ideia para o post de hoje.', $message);
    }

    public function testStoryIsOnlyForASingleMedia(): void
    {
        $this->assertTrue(BenefitOrchestrator::storyFits(1));
        $this->assertFalse(BenefitOrchestrator::storyFits(2));
    }

    public function testInsightsUseFriendlyLabels(): void
    {
        $text = BenefitOrchestrator::formatInsights([
            'data' => [
                ['name' => 'reach', 'total_value' => ['value' => 1200]],
                ['name' => 'views', 'total_value' => ['value' => 40]],
            ],
        ]);

        $this->assertStringContainsString('Alcance: 1.200', $text);
        $this->assertStringContainsString('Visualizações: 40', $text);
    }

    public function testProfessionPagesStayOnKnownJobs(): void
    {
        $this->assertNotNull(BenefitOrchestrator::profession('dentista'));
        $this->assertNull(BenefitOrchestrator::profession('padre'));
        $this->assertArrayHasKey('loja', BenefitOrchestrator::professions());
    }
}
