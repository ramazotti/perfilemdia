<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PerfilEmDia\Config;
use PerfilEmDia\Security\Crypto;

final class UserRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
    ) {
    }

    public function findByTelegramId(int $telegramUserId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE telegram_user_id = ? AND status <> ?');
        $stmt->execute([$telegramUserId, 'deleted']);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function aiVideoEnabled(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT ai_video FROM customers WHERE user_id = ? AND status <> 'excluido' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        return (int) $value === 1;
    }

    public function create(int $telegramUserId, int $chatId, ?string $username): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (telegram_user_id, telegram_chat_id, telegram_username) VALUES (?, ?, ?)'
        );
        $stmt->execute([$telegramUserId, $chatId, $username]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = [
            'telegram_chat_id',
            'telegram_username',
            'display_name',
            'profession',
            'city',
            'tone',
            'contact_cta',
            'fixed_hashtags',
            'about',
            'brand_style',
            'phrase_style',
            'logo_path',
            'idea_daily',
            'idea_sent_on',
            'idea_text',
            'onboarding_step',
            'pending_action',
            'status',
        ];
        $set = [];
        $values = [];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }
            $set[] = $column . ' = ?';
            $values[] = $fields[$column];
        }
        if ($set === []) {
            return;
        }
        $values[] = $id;
        $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($values);
    }

    public function createOauthState(int $userId): string
    {
        $state = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
            ->modify('+24 hours')
            ->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO oauth_states (state, user_id, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$state, $userId, $expires]);

        return $state;
    }

    public function peekOauthState(string $state): ?int
    {
        $stmt = $this->pdo->prepare('SELECT user_id, expires_at, used_at FROM oauth_states WHERE state = ?');
        $stmt->execute([$state]);
        $row = $stmt->fetch();
        if ($row === false || $row['used_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return (int) $row['user_id'];
    }

    public function consumeOauthState(string $state): ?int
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare('SELECT user_id, expires_at, used_at FROM oauth_states WHERE state = ? FOR UPDATE');
            $stmt->execute([$state]);
            $row = $stmt->fetch();
            if ($row === false || $row['used_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return null;
            }
            $mark = $this->pdo->prepare('UPDATE oauth_states SET used_at = NOW() WHERE state = ? AND used_at IS NULL');
            $mark->execute([$state]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $mark->rowCount() === 1 ? (int) $row['user_id'] : null;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function saveInstagramAccount(
        int $userId,
        string $igUserId,
        string $username,
        ?string $accountType,
        string $accessToken,
        DateTimeImmutable $expiresAt,
    ): void {
        $enc = $this->crypto->encrypt($accessToken);
        $expires = $expiresAt->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO instagram_accounts
                (user_id, ig_user_id, username, account_type, access_token_enc, token_expires_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ig_user_id = VALUES(ig_user_id),
                username = VALUES(username),
                account_type = VALUES(account_type),
                access_token_enc = VALUES(access_token_enc),
                token_expires_at = VALUES(token_expires_at),
                token_refreshed_at = NULL,
                status = ?'
        );
        $stmt->execute([$userId, $igUserId, $username, $accountType, $enc, $expires, 'active', 'active']);
    }

    public function instagramAccount(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM instagram_accounts WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['access_token'] = $this->crypto->decrypt((string) $row['access_token_enc']);
        unset($row['access_token_enc']);

        return $row;
    }

    public function updateInstagramToken(int $userId, string $accessToken, DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE instagram_accounts
             SET access_token_enc = ?, token_expires_at = ?, token_refreshed_at = NOW(), status = ?
             WHERE user_id = ?'
        );
        $expires = $expiresAt->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        $stmt->execute([$this->crypto->encrypt($accessToken), $expires, 'active', $userId]);
    }

    public function markInstagramRevokedByIgUserId(string $igUserId): void
    {
        $stmt = $this->pdo->prepare("UPDATE instagram_accounts SET status = 'revoked' WHERE ig_user_id = ?");
        $stmt->execute([$igUserId]);
    }

    public function markInstagramStatus(int $userId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE instagram_accounts SET status = ? WHERE user_id = ?');
        $stmt->execute([$status, $userId]);
    }

    /**
     * @return list<string> caminhos de mídia para apagar no disco
     */
    public function deleteAccount(int $userId): array
    {
        $paths = [];
        $logo = $this->pdo->prepare('SELECT logo_path FROM users WHERE id = ?');
        $logo->execute([$userId]);
        $logoPath = $logo->fetchColumn();
        if (is_string($logoPath) && $logoPath !== '' && !str_contains($logoPath, '..')) {
            $paths[] = str_starts_with($logoPath, '/')
                ? $logoPath
                : Config::root() . '/' . ltrim($logoPath, '/');
        }
        $media = $this->pdo->prepare(
            'SELECT pm.original_path, pm.public_name
             FROM post_media pm
             INNER JOIN posts p ON p.id = pm.post_id
             WHERE p.user_id = ?'
        );
        $media->execute([$userId]);
        foreach ($media->fetchAll() as $row) {
            if (!empty($row['original_path'])) {
                $paths[] = (string) $row['original_path'];
            }
            if (!empty($row['public_name'])) {
                $paths[] = (string) $row['public_name'];
            }
        }

        $postIds = $this->pdo->prepare('SELECT id FROM posts WHERE user_id = ?');
        $postIds->execute([$userId]);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $postIds->fetchAll());
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare("DELETE FROM post_media WHERE post_id IN ($in)")->execute($ids);
            $this->pdo->prepare("DELETE FROM posts WHERE id IN ($in)")->execute($ids);
        }
        $this->pdo->prepare('DELETE FROM oauth_states WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM instagram_accounts WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM ai_usage WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM events WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare(
            'DELETE tm FROM ticket_messages tm INNER JOIN tickets t ON t.id = tm.ticket_id WHERE t.user_id = ?'
        )->execute([$userId]);
        $this->pdo->prepare('DELETE FROM tickets WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        return $paths;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function instagramAccountsExpiringWithinDays(int $days): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM instagram_accounts
             WHERE status = ? AND token_expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute(['active', $days]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['access_token'] = $this->crypto->decrypt((string) $row['access_token_enc']);
            unset($row['access_token_enc']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dueDailyIdeas(string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users
             WHERE idea_daily = 1 AND status = ? AND onboarding_step = ?
               AND (idea_sent_on IS NULL OR idea_sent_on < ?)
             LIMIT 40'
        );
        $stmt->execute(['active', 'done', $today]);

        return $stmt->fetchAll();
    }
}
