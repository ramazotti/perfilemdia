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
        $brief = BenefitOrchestrator::surpriseBrief($user, new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')), 0);
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
