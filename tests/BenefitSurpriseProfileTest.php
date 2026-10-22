<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Growth\BenefitOrchestrator;
use PHPUnit\Framework\TestCase;

final class BenefitSurpriseProfileTest extends TestCase
{
    public function testSurpriseUsesSpiritualScenesForConfessionProfile(): void
    {
        $user = [
            'profession' => 'Site examedeconsciencia.com.br auxilia católicos na confissão',
            'about' => 'Conteúdo para preparar a confissão e examinar a consciência.',
            'brand_style' => 'Visual limpo e contemplativo, tons claros.',
            'tone' => 'acolhedor',
        ];
        $brief = BenefitOrchestrator::surpriseBriefFromTemplates($user, new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')), 0);
        $idea = $brief['idea'];
        $this->assertStringNotContainsString('expediente', mb_strtolower($idea));
        $this->assertTrue(
            str_contains(mb_strtolower($idea), 'oração')
                || str_contains(mb_strtolower($idea), 'consci')
                || str_contains(mb_strtolower($idea), 'reflex')
                || str_contains(mb_strtolower($idea), 'espiritual')
                || str_contains(mb_strtolower($idea), 'fé'),
        );
        $this->assertStringContainsString('Contexto do perfil:', $idea);
    }

    public function testQuoteTemplateUsesBibleVerseForSpiritualProfile(): void
    {
        $user = [
            'about' => 'Ajuda católicos a se prepararem para a confissão.',
            'profession' => 'Exame de consciência',
            'brand_style' => 'Visual limpo.',
        ];
        $previous = $_ENV['OPENROUTER_API_KEY'] ?? null;
        $_ENV['OPENROUTER_API_KEY'] = '';
        $_SERVER['OPENROUTER_API_KEY'] = '';
        putenv('OPENROUTER_API_KEY=');
        try {
            $brief = BenefitOrchestrator::surpriseBrief(
                $user,
                new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')),
                0,
                'story',
                null,
                'quote',
            );
            $this->assertStringContainsString('Texto na imagem:', $brief['idea']);
            $this->assertStringContainsString('Crédito:', $brief['idea']);
            $this->assertStringContainsString('Citação:', $brief['idea']);
        } finally {
            if ($previous === null) {
                unset($_ENV['OPENROUTER_API_KEY'], $_SERVER['OPENROUTER_API_KEY']);
                putenv('OPENROUTER_API_KEY');
            } else {
                $_ENV['OPENROUTER_API_KEY'] = $previous;
                $_SERVER['OPENROUTER_API_KEY'] = $previous;
                putenv('OPENROUTER_API_KEY=' . $previous);
            }
        }
    }

    public function testStoredIdeaRejectedWhenProfileIsSpiritualButIdeaIsGenericWork(): void
    {
        $user = [
            'about' => 'Ajuda católicos a se prepararem para a confissão.',
            'profession' => 'Exame de consciência',
        ];
        $stored = 'Mostre o fim do expediente: tudo no lugar, ambiente sereno.';
        $this->assertFalse(BenefitOrchestrator::storedIdeaFitsProfile($stored, $user));
    }
}
