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
    /** @var list<string> */
    private const INSTAGRAM_PROFILE_FIELDS = [
        'display_name',
        'profession',
        'city',
        'tone',
        'contact_cta',
        'fixed_hashtags',
        'about',
        'brand_style',
        'phrase_style',
        'phrase_color',
        'phrase_place',
        'phrase_size',
        'logo_path',
    ];

    public function __construct(
        private PDO $pdo,
        private readonly Crypto $crypto,
    ) {
    }

    public function bind(PDO $pdo): void
    {
        $this->pdo = $pdo;
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
            'phrase_color',
            'phrase_place',
            'phrase_size',
            'logo_path',
            'idea_daily',
            'idea_sent_on',
            'idea_text',
            'onboarding_step',
            'pending_action',
            'status',
        ];
        $profilePatch = [];
        foreach (self::INSTAGRAM_PROFILE_FIELDS as $column) {
            if (array_key_exists($column, $fields)) {
                $profilePatch[$column] = $fields[$column];
            }
        }
        $accountId = $this->resolveProfileAccountId($id);
        if ($profilePatch !== [] && $accountId !== null) {
            $this->updateInstagramProfile($accountId, $profilePatch);
            foreach (array_keys($profilePatch) as $column) {
                unset($fields[$column]);
            }
        }
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

    public function createOauthState(int $userId, string $connectMode = 'connect'): string
    {
        $mode = $connectMode === 'add' ? 'add' : 'connect';
        $state = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
            ->modify('+24 hours')
            ->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO oauth_states (state, user_id, connect_mode, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$state, $userId, $mode, $expires]);

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

    /**
     * @return array{user_id:int, connect_mode:string}|null
     */
    public function consumeOauthState(string $state): ?array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT user_id, connect_mode, expires_at, used_at FROM oauth_states WHERE state = ? FOR UPDATE'
            );
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
            if ($mark->rowCount() !== 1) {
                return null;
            }
            $mode = (string) ($row['connect_mode'] ?? 'connect');

            return [
                'user_id' => (int) $row['user_id'],
                'connect_mode' => $mode === 'add' ? 'add' : 'connect',
            ];
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
        int $maxAccounts,
    ): int {
        $existing = $this->findInstagramAccountRow($userId, $igUserId);
        if ($existing === null) {
            $count = $this->countInstagramAccounts($userId);
            if ($count >= $maxAccounts) {
                throw new \PerfilEmDia\Instagram\InstagramAccountLimitException($maxAccounts, $userId);
            }
        }

        $enc = $this->crypto->encrypt($accessToken);
        $expires = $expiresAt->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO instagram_accounts
                (user_id, ig_user_id, username, account_type, access_token_enc, token_expires_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                account_type = VALUES(account_type),
                access_token_enc = VALUES(access_token_enc),
                token_expires_at = VALUES(token_expires_at),
                token_refreshed_at = NULL,
                status = ?'
        );
        $stmt->execute([$userId, $igUserId, $username, $accountType, $enc, $expires, 'active', 'active']);

        $accountId = $existing !== null
            ? (int) $existing['id']
            : (int) $this->pdo->lastInsertId();
        if ($accountId <= 0) {
            $row = $this->findInstagramAccountRow($userId, $igUserId);
            $accountId = $row !== null ? (int) $row['id'] : 0;
        }
        if ($accountId <= 0) {
            throw new \RuntimeException('instagram_account_save_failed');
        }

        $this->ensureInstagramProfile($accountId, $userId);
        $this->ensureActiveInstagramAccount($userId, $accountId);

        return $accountId;
    }

    public function instagramAccount(int $userId): ?array
    {
        $user = $this->find($userId);
        $activeId = is_array($user) ? (int) ($user['active_instagram_account_id'] ?? 0) : 0;
        if ($activeId > 0) {
            $active = $this->instagramAccountById($activeId, $userId);
            if ($active !== null) {
                return $active;
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM instagram_accounts WHERE user_id = ? AND status = 'active' ORDER BY connected_at ASC, id ASC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $this->ensureActiveInstagramAccount($userId, (int) $row['id']);

        return $this->hydrateInstagramRow($row);
    }

    public function instagramAccountById(int $accountId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM instagram_accounts WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$accountId, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateInstagramRow($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInstagramAccounts(int $userId, bool $withTokens = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM instagram_accounts WHERE user_id = ? ORDER BY connected_at ASC, id ASC'
        );
        $stmt->execute([$userId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = $withTokens ? $this->hydrateInstagramRow($row) : $this->stripInstagramToken($row);
        }

        return $rows;
    }

    public function countInstagramAccounts(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM instagram_accounts WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function setActiveInstagramAccount(int $userId, int $accountId): bool
    {
        $row = $this->instagramAccountById($accountId, $userId);
        if ($row === null || (string) ($row['status'] ?? '') !== 'active') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE users SET active_instagram_account_id = ?, idea_text = NULL WHERE id = ?'
        );
        $stmt->execute([$accountId, $userId]);

        return true;
    }

    public function disconnectInstagramAccount(int $userId, int $accountId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM instagram_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$accountId, $userId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $user = $this->find($userId);
        if ($user !== null && (int) ($user['active_instagram_account_id'] ?? 0) === $accountId) {
            $next = $this->pdo->prepare(
                "SELECT id FROM instagram_accounts WHERE user_id = ? AND status = 'active' ORDER BY connected_at ASC, id ASC LIMIT 1"
            );
            $next->execute([$userId]);
            $nextId = $next->fetchColumn();
            $this->pdo->prepare('UPDATE users SET active_instagram_account_id = ? WHERE id = ?')
                ->execute([$nextId !== false ? (int) $nextId : null, $userId]);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function userWithInstagramProfile(array $user, ?int $instagramAccountId = null): array
    {
        $accountId = $instagramAccountId ?? (int) ($user['active_instagram_account_id'] ?? 0);
        if ($accountId <= 0) {
            $ig = $this->instagramAccount((int) $user['id']);
            $accountId = $ig !== null ? (int) $ig['id'] : 0;
        }
        if ($accountId <= 0) {
            return $user;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM instagram_profiles WHERE instagram_account_id = ? LIMIT 1');
        $stmt->execute([$accountId]);
        $profile = $stmt->fetch();
        if ($profile === false) {
            if ($this->countInstagramAccounts((int) $user['id']) > 1) {
                $merged = $user;
                foreach (self::INSTAGRAM_PROFILE_FIELDS as $field) {
                    $merged[$field] = $field === 'phrase_style' ? 'classica' : null;
                }

                return $merged;
            }

            return $user;
        }
        $merged = $user;
        foreach (self::INSTAGRAM_PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $profile)) {
                $merged[$field] = $profile[$field];
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function userForPerfil(array $user): array
    {
        return $this->userWithInstagramProfile($user, null);
    }

    public function activeInstagramUsername(int $userId): string
    {
        $ig = $this->instagramAccount($userId);

        return is_array($ig) ? (string) ($ig['username'] ?? '') : '';
    }

    public function resolveProfileAccountId(int $userId): ?int
    {
        $user = $this->find($userId);
        if ($user === null) {
            return null;
        }
        $activeId = (int) ($user['active_instagram_account_id'] ?? 0);
        if ($activeId > 0 && $this->instagramAccountById($activeId, $userId) !== null) {
            return $activeId;
        }
        $ig = $this->instagramAccount($userId);

        return $ig !== null ? (int) $ig['id'] : null;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateInstagramProfile(int $accountId, array $fields): void
    {
        $allowed = array_flip(self::INSTAGRAM_PROFILE_FIELDS);
        $patch = [];
        foreach ($fields as $column => $value) {
            if (isset($allowed[$column])) {
                $patch[$column] = $value;
            }
        }
        if ($patch === []) {
            return;
        }
        $columns = array_keys($patch);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $updates = implode(', ', array_map(static fn (string $c): string => $c . ' = VALUES(' . $c . ')', $columns));
        $sql = 'INSERT INTO instagram_profiles (instagram_account_id, ' . implode(', ', $columns) . ')
                VALUES (?, ' . $placeholders . ')
                ON DUPLICATE KEY UPDATE ' . $updates;
        $values = [$accountId];
        foreach ($columns as $column) {
            $values[] = $patch[$column];
        }
        $this->pdo->prepare($sql)->execute($values);
    }

    public function syncInstagramProfileFromUser(int $userId, int $accountId): void
    {
        $user = $this->find($userId);
        if ($user === null) {
            return;
        }
        $patch = [];
        foreach (self::INSTAGRAM_PROFILE_FIELDS as $column) {
            if (array_key_exists($column, $user)) {
                $patch[$column] = $user[$column];
            }
        }
        $this->updateInstagramProfile($accountId, $patch);
    }

    public function updateInstagramToken(int $accountId, string $accessToken, DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE instagram_accounts
             SET access_token_enc = ?, token_expires_at = ?, token_refreshed_at = NOW(), status = ?
             WHERE id = ?'
        );
        $expires = $expiresAt->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        $stmt->execute([$this->crypto->encrypt($accessToken), $expires, 'active', $accountId]);
    }

    public function markInstagramRevokedByIgUserId(string $igUserId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE instagram_accounts SET status = 'revoked', access_token_enc = '' WHERE ig_user_id = ?"
        );
        $stmt->execute([$igUserId]);
    }

    private function cancelBillingForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT id FROM customers WHERE user_id = ? AND status <> 'excluido'");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $customerId = (int) $row['id'];
            $this->pdo->prepare(
                "UPDATE subscriptions
                 SET status = 'cancelada', renew_token = NULL, gateway_subscription_id = NULL, cancel_at = NULL, updated_at = NOW()
                 WHERE customer_id = ? AND status IN ('ativa', 'inadimplente', 'pendente')"
            )->execute([$customerId]);
            $this->pdo->prepare(
                "UPDATE customers SET status = 'excluido', user_id = NULL, name = 'Excluído', email = '', phone = '', updated_at = NOW() WHERE id = ?"
            )->execute([$customerId]);
        }
    }

    public function markInstagramStatus(int $userId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE instagram_accounts SET status = ? WHERE user_id = ?');
        $stmt->execute([$status, $userId]);
    }

    public function markInstagramStatusById(int $accountId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE instagram_accounts SET status = ? WHERE id = ?');
        $stmt->execute([$status, $accountId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateInstagramRow(array $row): array
    {
        $enc = (string) ($row['access_token_enc'] ?? '');
        if ($enc === '' || (string) ($row['status'] ?? '') !== 'active') {
            unset($row['access_token_enc']);
            $row['access_token'] = null;

            return $row;
        }
        $row['access_token'] = $this->crypto->decrypt($enc);
        unset($row['access_token_enc']);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stripInstagramToken(array $row): array
    {
        unset($row['access_token_enc']);
        $row['access_token'] = null;

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findInstagramAccountRow(int $userId, string $igUserId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM instagram_accounts WHERE user_id = ? AND ig_user_id = ? LIMIT 1');
        $stmt->execute([$userId, $igUserId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function ensureActiveInstagramAccount(int $userId, int $accountId): void
    {
        $user = $this->find($userId);
        if ($user === null) {
            return;
        }
        $active = (int) ($user['active_instagram_account_id'] ?? 0);
        if ($active > 0) {
            $current = $this->instagramAccountById($active, $userId);
            if ($current !== null && (string) ($current['status'] ?? '') === 'active') {
                return;
            }
        }
        $this->pdo->prepare('UPDATE users SET active_instagram_account_id = ? WHERE id = ?')
            ->execute([$accountId, $userId]);
    }

    private function ensureInstagramProfile(int $accountId, int $userId): void
    {
        $check = $this->pdo->prepare('SELECT instagram_account_id FROM instagram_profiles WHERE instagram_account_id = ?');
        $check->execute([$accountId]);
        if ($check->fetch() !== false) {
            return;
        }
        if ($this->countInstagramAccounts($userId) <= 1) {
            $this->syncInstagramProfileFromUser($userId, $accountId);

            return;
        }
        $this->updateInstagramProfile($accountId, [
            'display_name' => null,
            'profession' => null,
            'city' => null,
            'tone' => null,
            'contact_cta' => null,
            'fixed_hashtags' => null,
            'about' => null,
            'brand_style' => null,
            'phrase_style' => 'classica',
            'phrase_color' => null,
            'phrase_place' => null,
            'phrase_size' => null,
            'logo_path' => null,
        ]);
    }

    /**
     * @return list<string> caminhos de mídia para apagar no disco
     */
    public function deleteAccount(int $userId): array
    {
        $this->cancelBillingForUser($userId);
        $paths = [];
        $logo = $this->pdo->prepare('SELECT logo_path FROM users WHERE id = ?');
        $logo->execute([$userId]);
        $logoPath = $logo->fetchColumn();
        if (is_string($logoPath) && $logoPath !== '' && !str_contains($logoPath, '..')) {
            $paths[] = str_starts_with($logoPath, '/')
                ? $logoPath
                : Config::root() . '/' . ltrim($logoPath, '/');
        }
        $profileLogos = $this->pdo->prepare(
            'SELECT ip.logo_path FROM instagram_profiles ip
             INNER JOIN instagram_accounts ia ON ia.id = ip.instagram_account_id
             WHERE ia.user_id = ? AND ip.logo_path IS NOT NULL AND ip.logo_path <> ?'
        );
        $profileLogos->execute([$userId, '']);
        foreach ($profileLogos->fetchAll() as $row) {
            $logoPath = (string) ($row['logo_path'] ?? '');
            if ($logoPath === '' || str_contains($logoPath, '..')) {
                continue;
            }
            $paths[] = str_starts_with($logoPath, '/')
                ? $logoPath
                : Config::root() . '/' . ltrim($logoPath, '/');
        }
        $media = $this->pdo->prepare(
            'SELECT pm.id, pm.original_path, pm.public_name
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
                $name = (string) $row['public_name'];
                $paths[] = Config::root() . '/public/m/' . $name . '.jpg';
                $paths[] = Config::root() . '/public/m/' . $name . '.mp4';
            }
            if (!empty($row['id'])) {
                $mediaId = (int) $row['id'];
                $paths[] = Config::root() . '/storage/media/phrasebase_' . $mediaId . '.jpg';
                $paths[] = Config::root() . '/storage/media/storyclean_' . $mediaId . '.jpg';
            }
        }

        $postIds = $this->pdo->prepare('SELECT id FROM posts WHERE user_id = ?');
        $postIds->execute([$userId]);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $postIds->fetchAll());
        foreach ($ids as $postId) {
            $paths[] = Config::root() . '/storage/media/' . $postId . '_0.jpg';
            $paths[] = Config::root() . '/storage/media/' . $postId . '_ref.jpg';
        }
        $paths[] = Config::root() . '/storage/media/u' . $userId . '_ia_ref.jpg';
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
