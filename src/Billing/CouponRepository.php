<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PDO;

final class CouponRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM coupons ORDER BY id DESC');

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1');
        $stmt->execute([strtoupper(trim($code))]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function save(
        ?int $id,
        string $code,
        int $percent,
        ?int $maxUses,
        ?string $validUntil,
        bool $monthlyOnly,
        bool $active,
    ): void {
        $code = strtoupper(trim($code));
        $percent = min(100, max(0, $percent));
        if ($id === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO coupons (code, percent, max_uses, used_count, valid_until, monthly_only, active, created_at)
                 VALUES (?, ?, ?, 0, ?, ?, ?, NOW())'
            );
            $stmt->execute([$code, $percent, $maxUses, $validUntil, $monthlyOnly ? 1 : 0, $active ? 1 : 0]);

            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE coupons
             SET code = ?, percent = ?, max_uses = ?, valid_until = ?, monthly_only = ?, active = ?
             WHERE id = ?'
        );
        $stmt->execute([$code, $percent, $maxUses, $validUntil, $monthlyOnly ? 1 : 0, $active ? 1 : 0, $id]);
    }

    public function consume(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE coupons
             SET used_count = used_count + 1
             WHERE id = ?
               AND active = 1
               AND (max_uses IS NULL OR used_count < max_uses)
               AND (valid_until IS NULL OR valid_until >= NOW())'
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }
}
