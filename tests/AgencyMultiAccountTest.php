<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PerfilEmDia\Billing\PlanAccess;
use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\PostStatus;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Instagram\InstagramAccountLimitException;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class AgencyMultiAccountTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        Config::load();
        $this->pdo = Db::pdo();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testAgencyUserStoresTwoAccountsAndPicksActive(): void
    {
        $users = new UserRepository($this->pdo, Crypto::fromConfig());
        $posts = new PostRepository($this->pdo);
        $userId = $users->create(88044201, 88044201, 'agency');
        $users->update($userId, ['onboarding_step' => 'done']);
        $this->subscribeAgencia($userId);

        $expires = new DateTimeImmutable('+30 days', new DateTimeZone('America/Sao_Paulo'));
        $idA = $users->saveInstagramAccount($userId, 'ig-a-' . $userId, 'loja_a', 'BUSINESS', 'tok-a', $expires, 5);
        $idB = $users->saveInstagramAccount($userId, 'ig-b-' . $userId, 'loja_b', 'BUSINESS', 'tok-b', $expires, 5);

        $this->assertNotSame($idA, $idB);
        $this->assertTrue($users->setActiveInstagramAccount($userId, $idB));
        $active = $users->instagramAccount($userId);
        $this->assertSame('loja_b', $active['username'] ?? '');

        $postId = $posts->create($userId, PostStatus::Generating, 'tema', null, $idB);
        $row = $posts->find($postId);
        $this->assertSame($idB, (int) ($row['instagram_account_id'] ?? 0));

        $access = new PlanAccess($this->pdo);
        $this->assertSame(5, $access->maxInstagramAccounts($userId));
        $this->assertTrue($access->canCreateWithAi($userId));
    }

    public function testEssencialBlocksSecondInstagram(): void
    {
        $users = new UserRepository($this->pdo, Crypto::fromConfig());
        $userId = $users->create(88044202, 88044202, 'ess');
        $this->subscribeEssencial($userId);
        $expires = new DateTimeImmutable('+30 days', new DateTimeZone('America/Sao_Paulo'));
        $users->saveInstagramAccount($userId, 'ig-one', 'unica', 'BUSINESS', 'tok', $expires, 1);

        $this->expectException(InstagramAccountLimitException::class);
        $users->saveInstagramAccount($userId, 'ig-two', 'outra', 'BUSINESS', 'tok2', $expires, 1);
    }

    private function subscribeAgencia(int $userId): void
    {
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'agencia' LIMIT 1")->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO customers (name, email, phone, document, document_type, user_id, status, created_at, updated_at)
             VALUES ('Ag', ?, '11999990000', ?, 'cpf', ?, 'ativo', NOW(), NOW())"
        )->execute(['ag' . $userId . '@test.local', $this->uniqueDocument($userId), $userId]);
        $customerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO subscriptions (customer_id, plan_id, status, cycle, period_kind, price_cents, posts_limit, period_days, current_period_end, created_at, updated_at)
             VALUES (?, ?, 'ativa', 'mensal', 'cheio', 9900, 80, 30, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())"
        )->execute([$customerId, $planId]);
    }

    private function subscribeEssencial(int $userId): void
    {
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'essencial' LIMIT 1")->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO customers (name, email, phone, document, document_type, user_id, status, created_at, updated_at)
             VALUES ('Es', ?, '11999990001', ?, 'cpf', ?, 'ativo', NOW(), NOW())"
        )->execute(['es' . $userId . '@test.local', $this->uniqueDocument($userId + 1000), $userId]);
        $customerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO subscriptions (customer_id, plan_id, status, cycle, period_kind, price_cents, posts_limit, period_days, current_period_end, created_at, updated_at)
             VALUES (?, ?, 'ativa', 'mensal', 'cheio', 2900, 16, 30, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())"
        )->execute([$customerId, $planId]);
    }

    private function uniqueDocument(int $seed): string
    {
        return str_pad((string) (88000000000 + ($seed % 9999999)), 11, '0', STR_PAD_LEFT);
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM subscriptions WHERE customer_id IN (SELECT id FROM customers WHERE email LIKE '%@test.local')");
        $this->pdo->exec("DELETE FROM customers WHERE email LIKE '%@test.local'");
        foreach ([88044201, 88044202] as $telegramId) {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE telegram_user_id = ?');
            $stmt->execute([$telegramId]);
            $userId = $stmt->fetchColumn();
            if ($userId === false) {
                continue;
            }
            $userId = (int) $userId;
            $this->pdo->prepare('DELETE FROM post_media WHERE post_id IN (SELECT id FROM posts WHERE user_id = ?)')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM subscriptions WHERE customer_id IN (SELECT id FROM customers WHERE user_id = ?)')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM customers WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM instagram_accounts WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM oauth_states WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
}
