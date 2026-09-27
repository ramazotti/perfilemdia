<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Growth\BenefitOrchestrator;
use PerfilEmDia\Image\IdeaLook;
use PHPUnit\Framework\TestCase;

final class IdeaLookTest extends TestCase
{
    public function testAcolhedorToneAsksForSimpleUnclutteredFrame(): void
    {
        $brief = IdeaLook::brief([
            'tone' => 'acolhedor',
            'profession' => 'Professora',
        ]);

        $this->assertStringContainsString('uncluttered', $brief);
        $this->assertStringContainsString('classroom', $brief);
        $this->assertStringContainsString('white', $brief);
    }

    public function testSurpriseBriefForCalmTeacherUsesSimpleScene(): void
    {
        $brief = BenefitOrchestrator::surpriseBrief([
            'tone' => 'acolhedor',
            'profession' => 'Professora de ensino fundamental',
            'city' => 'Curitiba',
        ], new \DateTimeImmutable('2026-03-10 10:00:00', new \DateTimeZone('America/Sao_Paulo')), 0);

        $this->assertStringContainsString('sala', mb_strtolower($brief['idea']));
        $this->assertNotSame('', $brief['phrase']);
    }
}
