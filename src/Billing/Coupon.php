<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

final class Coupon
{
    public static function amountCents(
        int $monthlyCents,
        string $cycle,
        ?string $code,
        string $expectedCode,
        int $percent,
        bool $active,
    ): int {
        $base = $cycle === 'anual' ? $monthlyCents * 10 : $monthlyCents;
        if ($cycle !== 'mensal' || !$active || $percent <= 0) {
            return $base;
        }
        $given = strtoupper(trim((string) $code));
        $expected = strtoupper(trim($expectedCode));
        if ($given === '' || $expected === '' || $given !== $expected) {
            return $base;
        }
        $percent = min(100, $percent);

        return (int) round($base * (100 - $percent) / 100);
    }

    /**
     * @param array<string, mixed>|null $coupon
     * @return array{amount:int,applied:bool}
     */
    public static function evaluate(int $monthlyCents, string $cycle, ?string $code, ?array $coupon, string $now): array
    {
        $full = $cycle === 'anual' ? $monthlyCents * 10 : $monthlyCents;
        $given = strtoupper(trim((string) $code));
        if ($given === '') {
            return ['amount' => $full, 'applied' => false];
        }
        if ($coupon === null || strtoupper((string) $coupon['code']) !== $given) {
            throw new CouponRejected('Cupom não encontrado.');
        }
        if ((int) $coupon['active'] !== 1) {
            throw new CouponRejected('Este cupom não está ativo.');
        }
        $until = $coupon['valid_until'] ?? null;
        if ($until !== null && $until !== '' && $until < $now) {
            throw new CouponRejected('Este cupom venceu.');
        }
        if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) {
            throw new CouponRejected('Este cupom esgotou.');
        }
        if ((int) $coupon['monthly_only'] === 1 && $cycle !== 'mensal') {
            throw new CouponRejected('Este cupom vale só na primeira mensalidade.');
        }
        $percent = min(100, max(0, (int) $coupon['percent']));

        return [
            'amount' => (int) round($full * (100 - $percent) / 100),
            'applied' => $percent > 0,
        ];
    }
}
