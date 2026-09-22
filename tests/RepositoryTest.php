<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Db;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostStatus;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testUserPostAndTransition(): void
    {
        $users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $posts = new PostRepository($this->pdo);
        $id = $users->create(900001, 900001, 'tester');
        $postId = $posts->create($id, PostStatus::AwaitingApproval, 'quadro em Moema');

        $this->assertTrue($posts->transition($postId, PostStatus::AwaitingApproval, PostStatus::Publishing));
        $this->assertFalse($posts->transition($postId, PostStatus::AwaitingApproval, PostStatus::Published));
        $this->assertSame('PUBLISHING', $posts->find($postId)['status']);
    }

    public function testOauthStateIsSingleUse(): void
    {
        $users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
        $id = $users->create(900002, 900002, null);
        $state = $users->createOauthState($id);

        $this->assertSame($id, $users->consumeOauthState($state));
        $this->assertNull($users->consumeOauthState($state));
    }
}
