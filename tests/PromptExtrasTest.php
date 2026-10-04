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
}
