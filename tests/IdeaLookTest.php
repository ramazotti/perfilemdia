<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\IdeaImage;
use PerfilEmDia\Image\IdeaLook;
use PHPUnit\Framework\TestCase;

final class IdeaLookTest extends TestCase
{
    public function testToneChangesTheLook(): void
    {
        $warm = IdeaLook::brief(['tone' => 'acolhedor', 'profession' => 'escola']);
        $sharp = IdeaLook::brief(['tone' => 'profissional', 'profession' => 'escola']);

        $this->assertStringContainsString('welcoming', $warm);
        $this->assertStringContainsString('professional', $sharp);
        $this->assertStringContainsString('escola', $warm);
    }

    public function testAReferencePhotoStaysTheScene(): void
    {
        $prompt = IdeaImage::promptFor(
            'Uma escola com um olhar para o ceu.',
            true,
            IdeaLook::brief(['tone' => 'acolhedor']),
            '9:16',
        );

        $this->assertStringContainsString('same people', $prompt);
        $this->assertStringContainsString('not a new wide shot', $prompt);
        $this->assertStringContainsString('feeling, not a new place', $prompt);
        $this->assertStringContainsString('welcoming', $prompt);
        $this->assertStringContainsString('9:16', $prompt);
        $this->assertStringNotContainsString('only as a reference', $prompt);
    }

    public function testAFreshPhotoStillFollowsTheTone(): void
    {
        $prompt = IdeaImage::promptFor(
            'Consulta da manha.',
            false,
            IdeaLook::brief(['tone' => 'tecnico']),
            '',
        );

        $this->assertStringContainsString('cared-for', $prompt);
        $this->assertStringContainsString('precise', $prompt);
        $this->assertStringNotContainsString('9:16', $prompt);
    }
}
