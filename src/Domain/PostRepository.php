<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PDO;

final class PostRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, PostStatus $status, ?string $theme, ?string $mediaGroupId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO posts (user_id, status, theme_text, media_group_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $status->value, $theme, $mediaGroupId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addMedia(int $postId, int $position, string $fileId, int $messageId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO post_media (post_id, position, telegram_file_id, telegram_msg_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$postId, $position, $fileId, $messageId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByMediaGroup(string $mediaGroupId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posts WHERE media_group_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$mediaGroupId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function media(int $postId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM post_media WHERE post_id = ? ORDER BY position ASC, id ASC');
        $stmt->execute([$postId]);

        return $stmt->fetchAll();
    }

    public function updateMedia(int $mediaId, array $fields): void
    {
        $allowed = ['original_path', 'public_name', 'width', 'height'];
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
        $values[] = $mediaId;
        $this->pdo->prepare('UPDATE post_media SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values);
    }

    public function transition(int $id, PostStatus $from, PostStatus $to): bool
    {
        $stmt = $this->pdo->prepare('UPDATE posts SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute([$to->value, $id, $from->value]);

        return $stmt->rowCount() === 1;
    }

    public function update(int $id, array $fields): void
    {
        $allowed = [
            'theme_text',
            'caption',
            'alt_text',
            'caption_version',
            'regen_count',
            'last_feedback',
            'media_group_id',
            'preview_message_id',
            'ig_container_id',
            'ig_media_id',
            'ig_permalink',
            'error_code',
            'error_message',
            'attempts',
            'published_at',
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
        $this->pdo->prepare('UPDATE posts SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values);
    }

    public function findPendingForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM posts
             WHERE user_id = ? AND status IN ('AWAITING_THEME', 'AWAITING_APPROVAL', 'AWAITING_FEEDBACK', 'AWAITING_MANUAL_EDIT')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countPublishedInMonth(int $userId, string $monthStart, string $nextMonthStart): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM posts
             WHERE user_id = ? AND status = ? AND published_at >= ? AND published_at < ?'
        );
        $stmt->execute([$userId, PostStatus::Published->value, $monthStart, $nextMonthStart]);

        return (int) $stmt->fetchColumn();
    }

    public function recordAiUsage(int $userId, ?int $postId, string $model, int $inputTokens, int $outputTokens): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_usage (user_id, post_id, model, input_tokens, output_tokens) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $postId, $model, $inputTokens, $outputTokens]);
    }

    public function recordEvent(?int $userId, ?int $postId, string $type, ?array $data = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO events (user_id, post_id, type, data) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $postId, $type, $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectingOlderThanSeconds(int $seconds): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM posts WHERE status = 'COLLECTING' AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$seconds]);

        return $stmt->fetchAll();
    }
}
