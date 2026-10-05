<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PHPUnit\Framework\TestCase;
use PerfilEmDia\Support\Utf8;

final class Utf8Test extends TestCase
{
    public function testCleanStripsInvalidBytes(): void
    {
        $bad = "ok\u{FFFD}text";
        $mixed = "a" . "\xC0\x28" . "b";
        $clean = Utf8::clean($mixed);
        $this->assertStringStartsWith('a', $clean);
        $this->assertStringEndsWith('b', $clean);
        json_encode(['x' => $clean], JSON_THROW_ON_ERROR);
        $this->assertTrue(true);
    }

    public function testCleanDeepOnNestedJsonPayload(): void
    {
        $payload = [
            'messages' => [
                ['role' => 'user', 'content' => "tema\xC0\x28"],
            ],
        ];
        $fixed = Utf8::cleanDeep($payload);
        $this->assertIsString($fixed['messages'][0]['content']);
        json_encode($fixed, JSON_THROW_ON_ERROR);
        $this->assertTrue(true);
    }
}
