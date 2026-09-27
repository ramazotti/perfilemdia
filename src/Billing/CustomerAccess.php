<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use DateTimeImmutable;
use PDO;
use PerfilEmDia\Site\Layout;

final class CustomerAccess
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function urlForUser(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM customers WHERE user_id = ? AND status <> 'excluido' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return '';
        }

        return $this->urlForCustomer((int) $id);
    }

    public function urlForCustomer(int $customerId): string
    {
        return Layout::absolute('minha-conta/acesso/' . $this->issue($customerId));
    }

    public function issue(int $customerId): string
    {
        $this->pdo->prepare('DELETE FROM customer_access_tokens WHERE customer_id = ?')->execute([$customerId]);
        $raw = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+12 hours'))->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO customer_access_tokens (customer_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$customerId, hash('sha256', $raw), $expires]);

        return $raw;
    }

    public function consume(string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT customer_id, expires_at FROM customer_access_tokens WHERE token_hash = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$hash]);
            $row = $stmt->fetch();
            if ($row === false) {
                if ($own) {
                    $this->pdo->rollBack();
                }

                return null;
            }
            if ((string) $row['expires_at'] < (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')) {
                if ($own) {
                    $this->pdo->rollBack();
                }

                return null;
            }
            $customerId = (int) $row['customer_id'];
            $del = $this->pdo->prepare('DELETE FROM customer_access_tokens WHERE token_hash = ?');
            $del->execute([$hash]);
            if ($del->rowCount() !== 1) {
                if ($own) {
                    $this->pdo->rollBack();
                }

                return null;
            }
            if ($own) {
                $this->pdo->commit();
            }

            return $customerId;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
