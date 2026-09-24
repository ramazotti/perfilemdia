<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Ai\OpenRouterAccount;
use PHPUnit\Framework\TestCase;

final class OpenRouterAccountTest extends TestCase
{
    public function testParseReadsMonthlySpendAndDailyLimit(): void
    {
        $parsed = OpenRouterAccount::parse([
            'usage' => 0.7,
            'usage_daily' => 0.7,
            'usage_weekly' => 0.7,
            'usage_monthly' => 0.7,
            'limit' => 10,
            'limit_remaining' => 9.3,
            'limit_reset' => 'daily',
            'label' => 'hidden',
        ]);

        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(0.7, $parsed['monthly'], 0.0001);
        $this->assertEqualsWithDelta(10.0, $parsed['limit'], 0.0001);
        $this->assertSame('daily', $parsed['reset']);
    }

    public function testParseReturnsNullWithoutUsage(): void
    {
        $this->assertNull(OpenRouterAccount::parse(['label' => 'only']));
    }
}
