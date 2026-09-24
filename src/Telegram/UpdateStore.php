<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

use PDO;
use PDOException;

final class UpdateStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tableName = 'telegram_updates',
    ) {
        if (!in_array($this->tableName, ['telegram_updates', 'contact_updates'], true)) {
            throw new \InvalidArgumentException('Tabela de updates invalida.');
        }
    }

    public function remember(int $updateId, string $payloadJson): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . $this->tableName . ' (update_id, payload) VALUES (?, ?)'
            );
            $stmt->execute([$updateId, $payloadJson]);

            return true;
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function markProcessed(int $updateId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->tableName . ' SET processed_at = NOW() WHERE update_id = ?'
        );
        $stmt->execute([$updateId]);
    }

    public function forget(int $updateId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->tableName . ' WHERE update_id = ? AND processed_at IS NULL'
        );
        $stmt->execute([$updateId]);
    }

    public function markError(int $updateId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->tableName . '
             SET attempts = attempts + 1, last_error = ?
             WHERE update_id = ?'
        );
        $stmt->execute([mb_substr($error, 0, 65000), $updateId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stuck(int $olderThanSeconds = 120, int $maxAttempts = 3): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->tableName . '
             WHERE processed_at IS NULL
               AND attempts < ?
               AND received_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY update_id ASC'
        );
        $stmt->execute([$maxAttempts, $olderThanSeconds]);

        return $stmt->fetchAll();
    }

    private function isDuplicate(PDOException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062;
    }
}
