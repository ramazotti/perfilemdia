<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PDO;
use RuntimeException;

final class PromptExtraRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAccount(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, instagram_account_id, trigger_word, prompt_text, image_path, sort_order
             FROM instagram_prompt_extras
             WHERE instagram_account_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$accountId]);

        return $stmt->fetchAll() ?: [];
    }

    public function countForAccount(int $accountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM instagram_prompt_extras WHERE instagram_account_id = ?');
        $stmt->execute([$accountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForAccount(int $id, int $accountId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, instagram_account_id, trigger_word, prompt_text, image_path, sort_order
             FROM instagram_prompt_extras WHERE id = ? AND instagram_account_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function save(int $accountId, ?int $id, string $trigger, string $text): int
    {
        $trigger = PromptExtras::normalizeTrigger($trigger);
        if ($trigger === '') {
            throw new RuntimeException('Informe a palavra-gatilho.');
        }
        $text = PromptExtras::normalizeText($text);
        if ($text === '') {
            throw new RuntimeException('Informe o texto para a IA.');
        }
        if ($id !== null && $id > 0) {
            $owned = $this->findForAccount($id, $accountId);
            if ($owned === null) {
                throw new RuntimeException('Extra não encontrado.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE instagram_prompt_extras SET trigger_word = ?, prompt_text = ? WHERE id = ? AND instagram_account_id = ?'
            );
            $stmt->execute([$trigger, $text, $id, $accountId]);

            return $id;
        }
        if ($this->countForAccount($accountId) >= PromptExtras::MAX_PER_ACCOUNT) {
            throw new RuntimeException('Limite de ' . PromptExtras::MAX_PER_ACCOUNT . ' extras por conta.');
        }
        $sort = $this->countForAccount($accountId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO instagram_prompt_extras (instagram_account_id, trigger_word, prompt_text, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        try {
            $stmt->execute([$accountId, $trigger, $text, $sort]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new RuntimeException('Já existe um extra com essa palavra-gatilho.');
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $accountId, int $id): void
    {
        $row = $this->findForAccount($id, $accountId);
        if ($row === null) {
            throw new RuntimeException('Extra não encontrado.');
        }
        $this->pdo->prepare('DELETE FROM instagram_prompt_extras WHERE id = ? AND instagram_account_id = ?')
            ->execute([$id, $accountId]);
        $this->unlinkImage((string) ($row['image_path'] ?? ''));
    }

    public function setImagePath(int $accountId, int $id, string $relativePath): void
    {
        if ($this->findForAccount($id, $accountId) === null) {
            throw new RuntimeException('Extra não encontrado.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE instagram_prompt_extras SET image_path = ? WHERE id = ? AND instagram_account_id = ?'
        );
        $stmt->execute([$relativePath, $id, $accountId]);
    }

    public function clearImage(int $accountId, int $id): void
    {
        $row = $this->findForAccount($id, $accountId);
        if ($row === null) {
            return;
        }
        $this->unlinkImage((string) ($row['image_path'] ?? ''));
        $this->pdo->prepare(
            'UPDATE instagram_prompt_extras SET image_path = NULL WHERE id = ? AND instagram_account_id = ?'
        )->execute([$id, $accountId]);
    }

    private function unlinkImage(string $relative): void
    {
        if ($relative === '' || str_contains($relative, '..')) {
            return;
        }
        $path = \PerfilEmDia\Config::root() . '/' . ltrim($relative, '/');
        if (is_file($path)) {
            unlink($path);
        }
    }
}
