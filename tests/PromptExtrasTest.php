<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Domain\PromptExtras;
use PHPUnit\Framework\TestCase;

final class PromptExtrasTest extends TestCase
{
    public function testMatchesTriggerCaseInsensitive(): void
    {
        $this->assertTrue(PromptExtras::matches('Post sobre a ADESIG em Maringá', 'adesig'));
        $this->assertFalse(PromptExtras::matches('Consultoria geral', 'adesig'));
    }

    public function testFormatsMatchedBlocksForCaption(): void
    {
        $text = PromptExtras::formatForCaption([
            ['trigger_word' => 'adesig', 'prompt_text' => 'Consultoria em gestão.'],
        ]);
        $this->assertStringContainsString('[adesig]', $text);
        $this->assertStringContainsString('Consultoria em gestão.', $text);
    }

    public function testMissingImageReturnsNull(): void
    {
        $this->assertNull(PromptExtras::firstImageAbsolute([
            ['image_path' => 'storage/prompt_extras/inexistente.png'],
        ]));
    }

    public function testResolveUsesProfileWithoutThemeTrigger(): void
    {
        $defs = [
            ['id' => 1, 'trigger_word' => 'adesig', 'prompt_text' => 'Consultoria ADESIG.'],
        ];
        $resolved = PromptExtras::resolve($defs, 'Infográfico sobre Maringá', "Consultoria em gestão ADESIG\n@adesig_oficial");
        $this->assertCount(1, $resolved);
        $this->assertSame('adesig', $resolved[0]['trigger_word']);
    }

    public function testResolveSingleExtraWithoutAnyMatch(): void
    {
        $defs = [
            ['id' => 1, 'trigger_word' => 'marca', 'prompt_text' => 'Tom da marca.'],
        ];
        $resolved = PromptExtras::resolve($defs, 'Post do dia', '');
        $this->assertCount(1, $resolved);
    }

    public function testExpandLinkedExtrasIncludesCompanionTrigger(): void
    {
        $defs = [
            ['id' => 1, 'trigger_word' => 'adesig', 'prompt_text' => 'Parceiro sigsistem no mesmo grupo.'],
            ['id' => 2, 'trigger_word' => 'sigsistem', 'prompt_text' => 'Software SIG.'],
        ];
        $resolved = PromptExtras::resolve($defs, 'Infográfico em Maringá', 'Trabalho da ADESIG');
        $triggers = array_column($resolved, 'trigger_word');
        $this->assertContains('adesig', $triggers);
        $this->assertContains('sigsistem', $triggers);
    }

    public function testCollectsAllMatchedImages(): void
    {
        $root = dirname(__DIR__);
        $dir = $root . '/storage/prompt_extras';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $a = $dir . '/test-a.png';
        $b = $dir . '/test-b.png';
        file_put_contents($a, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        copy($a, $b);
        $paths = PromptExtras::allImageAbsolutes([
            ['image_path' => 'storage/prompt_extras/test-a.png'],
            ['image_path' => 'storage/prompt_extras/test-b.png'],
        ]);
        $this->assertCount(2, $paths);
        @unlink($a);
        @unlink($b);
    }
}
