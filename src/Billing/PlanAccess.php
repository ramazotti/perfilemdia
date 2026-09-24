<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PlanAccess
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{scope:string,limit:int,from:string,until:string,days:int,plan:string,slug:string,next_cents:int,kind:string,blocked:string}|null
     */
    public function window(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.status, s.period_kind, s.posts_limit, s.period_days, s.period_started_at, s.current_period_end,
                    s.comp_forever, s.comp_until, s.price_cents, p.name AS plan_name, p.slug AS plan_slug, p.posts_limit AS plan_posts
             FROM customers c
             INNER JOIN subscriptions s ON s.customer_id = c.id
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE c.user_id = ? AND s.status IN (\'ativa\', \'inadimplente\')
             ORDER BY s.id DESC
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $now = new DateTimeImmutable('now');
        $end = (string) ($row['current_period_end'] ?? '');
        $kind = (string) $row['period_kind'];
        $days = (int) $row['period_days'];
        $base = [
            'limit' => 0,
            'from' => $now->format('Y-m-d H:i:s'),
            'until' => $end,
            'days' => $days > 0 ? $days : 3,
            'plan' => (string) $row['plan_name'],
            'slug' => (string) $row['plan_slug'],
            'next_cents' => (int) $row['price_cents'],
            'kind' => $kind,
            'blocked' => '',
        ];
        $forever = (int) ($row['comp_forever'] ?? 0) === 1;
        $compUntil = (string) ($row['comp_until'] ?? '');
        $comped = $forever || ($compUntil !== '' && $compUntil >= $now->format('Y-m-d H:i:s'));
        if (!$comped && ((string) $row['status'] === 'inadimplente' || $end === '' || $end < $now->format('Y-m-d H:i:s'))) {
            $base['scope'] = 'encerrado';
            $base['blocked'] = (string) $row['status'] === 'inadimplente' ? 'recusado' : 'fim';

            return $base;
        }
        if ($kind === 'teste') {
            $started = (string) ($row['period_started_at'] ?? '');
            if ($started === '') {
                $started = (new DateTimeImmutable($end))->modify('-' . max(1, $days) . ' days')->format('Y-m-d H:i:s');
            }
            $limit = (int) $row['posts_limit'];
            if ($limit <= 0) {
                $limit = Trial::posts((int) $row['plan_posts'], max(1, $days));
            }
            $base['scope'] = 'teste';
            $base['limit'] = $limit;
            $base['from'] = $started;

            return $base;
        }

        $tz = new DateTimeZone('America/Sao_Paulo');
        $local = new DateTimeImmutable('now', $tz);
        $limit = (int) $row['posts_limit'] > 0 ? (int) $row['posts_limit'] : (int) $row['plan_posts'];
        $base['scope'] = 'mes';
        $base['limit'] = $limit;
        $base['from'] = $local->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $base['until'] = $local->modify('first day of next month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        return $base;
    }

    public function canEditPhoto(int $userId): bool
    {
        return $this->planAllows($userId, ['profissional', 'estudio']);
    }

    public function canPublishVideo(int $userId): bool
    {
        return $this->planAllows($userId, ['profissional', 'estudio']);
    }

    public function canCreateWithAi(int $userId): bool
    {
        return $this->planAllows($userId, ['estudio']);
    }

    /**
     * @param list<string> $slugs
     */
    private function planAllows(int $userId, array $slugs): bool
    {
        $window = $this->window($userId);

        return $window !== null && $window['blocked'] === '' && in_array($window['slug'], $slugs, true);
    }
}
