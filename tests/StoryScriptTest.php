<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\StoryScript;
use PHPUnit\Framework\TestCase;

final class StoryScriptTest extends TestCase
{
    public function testPartsRespectOneEightyCharactersAndThreeFrames(): void
    {
        $caption = str_repeat('palavra ', 80);
        $parts = StoryScript::parts($caption);

        $this->assertGreaterThan(1, count($parts));
        $this->assertLessThanOrEqual(3, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(180, mb_strlen($part));
        }
    }
}
