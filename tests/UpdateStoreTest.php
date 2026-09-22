<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Db;
use PerfilEmDia\Telegram\UpdateStore;
use PHPUnit\Framework\TestCase;

final class UpdateStoreTest extends TestCase
{
    private \PDO $pdo;
    private UpdateStore $store;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
        $this->store = new UpdateStore($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testRememberSameUpdateIdReturnsFalseSecondTime(): void
    {
        $payload = '{"update_id":930001}';
        $this->assertTrue($this->store->remember(930001, $payload));
        $this->assertFalse($this->store->remember(930001, $payload));
    }
}
