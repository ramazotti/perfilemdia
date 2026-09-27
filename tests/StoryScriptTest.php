<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\StoryScript;
use PHPUnit\Framework\TestCase;

final class StoryScriptTest extends TestCase
{
    public function testPartsRespectTwoSeventyCharactersAndTwoFrames(): void
    {
        $caption = str_repeat('palavra ', 80);
        $parts = StoryScript::parts($caption);

        $this->assertGreaterThan(1, count($parts));
        $this->assertLessThanOrEqual(StoryScript::MAX_PARTS, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(StoryScript::MAX_PART_CHARS, mb_strlen($part));
        }

        $long = StoryScript::parts(str_repeat('palavra ', 200));
        $this->assertCount(StoryScript::MAX_PARTS, $long);
        foreach ($long as $part) {
            $this->assertLessThanOrEqual(StoryScript::MAX_PART_CHARS, mb_strlen($part));
        }
    }
}
