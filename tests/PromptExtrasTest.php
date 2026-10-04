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
