<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Config;
use PerfilEmDia\Image\IdeaReference;
use PHPUnit\Framework\TestCase;

final class IdeaReferenceTest extends TestCase
{
    private int $userId = 88001;

    protected function tearDown(): void
    {
        IdeaReference::clearStash($this->userId);
        $postRef = IdeaReference::postPath(99001);
        if (is_file($postRef)) {
            unlink($postRef);
        }
    }

    public function testAdoptMovesStashToPostReference(): void
    {
        $stash = IdeaReference::stashPath($this->userId);
        $dir = dirname($stash);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($stash, 'fake-jpeg');

        $dest = IdeaReference::adoptStash($this->userId, 99001);

        $this->assertSame(IdeaReference::postPath(99001), $dest);
        $this->assertFileExists($dest);
        $this->assertFileDoesNotExist($stash);
    }
}
