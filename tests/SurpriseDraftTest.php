<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Config;
use PerfilEmDia\Domain\SurpriseDraft;
use PHPUnit\Framework\TestCase;

final class SurpriseDraftTest extends TestCase
{
    private int $userId;

    protected function setUp(): void
    {
        Config::load();
        $this->userId = random_int(900000, 999999);
        SurpriseDraft::clear($this->userId);
    }

    protected function tearDown(): void
    {
        SurpriseDraft::clear($this->userId);
    }

    public function testSaveLoadAndAppend(): void
    {
        $idea = "Infogr\u{00e1}fico adesig";
        $this->assertTrue(SurpriseDraft::save($this->userId, $idea, 'Frase teste'));
        $draft = SurpriseDraft::load($this->userId);
        $this->assertNotNull($draft);
        $this->assertSame($idea, $draft['idea']);
        $this->assertSame('Frase teste', $draft['phrase']);
        $city = "Incluir Maring\u{00e1}";
        $this->assertTrue(SurpriseDraft::appendComplement($this->userId, $city));
        $draft = SurpriseDraft::load($this->userId);
        $this->assertStringContainsString('Complemento: ' . $city, (string) ($draft['idea'] ?? ''));
    }
}
