<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

final class Trial
{
    public const MONTH_DAYS = 30;

    /**
     * @param array<string, mixed> $plan
     */
    public static function applies(array $plan, string $cycle): bool
    {
        return $cycle === 'mensal'
            && (int) ($plan['trial_days'] ?? 0) > 0
            && (int) ($plan['trial_price_cents'] ?? 0) > 0;
    }

    public static function posts(int $monthlyPosts, int $days): int
    {
        if ($monthlyPosts <= 0 || $days <= 0) {
            return 0;
        }
        if ($days >= self::MONTH_DAYS) {
            return $monthlyPosts;
        }
        $posts = (int) round($monthlyPosts * $days / self::MONTH_DAYS);

        return max(1, $posts);
    }
}
