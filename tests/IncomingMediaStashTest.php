<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Domain\IncomingMediaStash;
use PHPUnit\Framework\TestCase;

final class IncomingMediaStashTest extends TestCase
{
    private int $userId = 880001;

    protected function tearDown(): void
    {
        IncomingMediaStash::clear($this->userId);
    }

    public function testRoundTripPhotoMessage(): void
    {
        $message = [
            'message_id' => 42,
            'caption' => 'Obra na zona sul',
            'photo' => [
                ['file_id' => 'small', 'width' => 90, 'height' => 90],
                ['file_id' => 'big', 'width' => 1280, 'height' => 720, 'file_size' => 1000],
            ],
        ];
        $this->assertTrue(IncomingMediaStash::save($this->userId, $message));
        $this->assertTrue(IncomingMediaStash::has($this->userId));
        $loaded = IncomingMediaStash::load($this->userId);
        $this->assertIsArray($loaded);
        $round = IncomingMediaStash::toMessage($loaded);
        $this->assertSame(42, $round['message_id']);
        $this->assertSame('Obra na zona sul', $round['caption']);
        $this->assertSame('big', $round['photo'][1]['file_id']);
        IncomingMediaStash::clear($this->userId);
        $this->assertFalse(IncomingMediaStash::has($this->userId));
    }
}
