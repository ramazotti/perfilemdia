<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Domain\ScheduleTime;
use PHPUnit\Framework\TestCase;

final class ScheduleTimeTest extends TestCase
{
    public function testTomorrowHourAndDatedTime(): void
    {
        $now = new DateTimeImmutable('2026-09-23 10:00:00', new DateTimeZone('America/Sao_Paulo'));

        $tomorrow = ScheduleTime::parse("amanh\u{00e3} 10h", $now);
        $this->assertSame(ScheduleTime::OK, $tomorrow['status']);
        $this->assertNotNull($tomorrow['at']);
        $this->assertSame('2026-09-24 10:00', $tomorrow['at']->format('Y-m-d H:i'));

        $dated = ScheduleTime::parse('25/09 18:30', $now);
        $this->assertSame(ScheduleTime::OK, $dated['status']);
        $this->assertNotNull($dated['at']);
        $this->assertSame('2026-09-25 18:30', $dated['at']->format('Y-m-d H:i'));
        $this->assertSame('25/09/2026 18:30:00', ScheduleTime::label($dated['at']));
    }

    public function testPastAndFarTimesAreRejected(): void
    {
        $now = new DateTimeImmutable('2026-09-23 10:00:00', new DateTimeZone('America/Sao_Paulo'));

        $this->assertSame(ScheduleTime::PAST, ScheduleTime::parse('hoje 9h', $now)['status']);
        $this->assertSame(ScheduleTime::INVALID, ScheduleTime::parse('quando der', $now)['status']);
        $this->assertSame(ScheduleTime::FAR, ScheduleTime::parse('25/12 9h', $now)['status']);
    }

    public function testClockWithoutDayUsesTheNextFutureMoment(): void
    {
        $now = new DateTimeImmutable('2026-09-23 10:00:00', new DateTimeZone('America/Sao_Paulo'));
        $later = ScheduleTime::parse('21:15', $now);
        $this->assertSame('2026-09-23 21:15', $later['at']?->format('Y-m-d H:i'));

        $rolled = ScheduleTime::parse('8h', $now);
        $this->assertSame('2026-09-24 08:00', $rolled['at']?->format('Y-m-d H:i'));
    }
}