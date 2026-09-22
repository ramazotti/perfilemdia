<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PDO;

final class PlanRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM plans WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM plans ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM plans WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM plans WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function update(
        int $id,
        string $name,
        string $description,
        int $priceCents,
        int $postsLimit,
        int $trialPriceCents,
        int $trialDays,
        string $features,
        bool $highlighted,
        bool $active,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE plans
             SET name = ?, description = ?, price_cents = ?, posts_limit = ?, trial_price_cents = ?, trial_days = ?, features = ?, highlighted = ?, active = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $description,
            $priceCents,
            $postsLimit,
            max(0, $trialPriceCents),
            max(0, $trialDays),
            $features,
            $highlighted ? 1 : 0,
            $active ? 1 : 0,
            $id,
        ]);
    }
}
