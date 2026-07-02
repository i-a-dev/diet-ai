<?php

declare(strict_types=1);

/**
 * チャット履歴の保存・取得を担当するクラス。
 */
final class ChatMessageRepository
{
    /** 画面に読み込む最大件数 */
    public const DISPLAY_LIMIT = 100;

    /** AI API に渡す会話履歴の最大件数 */
    public const API_CONTEXT_LIMIT = 20;

    /** 保存期間（日）。これより古いメッセージは削除する */
    public const RETENTION_DAYS = 90;

    /** 保存件数の上限（期間内でも超えたら古い順に削除） */
    public const MAX_STORED_MESSAGES = 500;

    private PDO $db;
    private int $userId;

    public function __construct(int $userId, ?PDO $db = null)
    {
        $this->userId = $userId;
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<int, array{id: int, role: string, content: string, createdAt: string}>
     */
    public function listForDisplay(?int $limit = null): array
    {
        $limit = $limit ?? self::DISPLAY_LIMIT;
        if ($limit < 1) {
            $limit = 1;
        }

        $statement = $this->db->prepare(
            'SELECT id, role, content, created_at
             FROM chat_messages
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = array_reverse($statement->fetchAll());

        return array_map([$this, 'formatRow'], $rows);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function listForApiContext(): array
    {
        $statement = $this->db->prepare(
            'SELECT role, content
             FROM chat_messages
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', self::API_CONTEXT_LIMIT, PDO::PARAM_INT);
        $statement->execute();

        $rows = array_reverse($statement->fetchAll());

        return array_map(
            static fn (array $row): array => [
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
            ],
            $rows
        );
    }

    /**
     * @return array{id: int, role: string, content: string, createdAt: string}
     */
    public function add(string $role, string $content): array
    {
        if (!in_array($role, ['user', 'assistant'], true)) {
            throw new InvalidArgumentException('role must be user or assistant');
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            throw new InvalidArgumentException('content is required');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO chat_messages (user_id, role, content, created_at)
             VALUES (:user_id, :role, :content, :created_at)'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'role' => $role,
            'content' => $trimmed,
            'created_at' => $now,
        ]);

        $this->prune();

        return [
            'id' => (int) $this->db->lastInsertId(),
            'role' => $role,
            'content' => $trimmed,
            'createdAt' => $now,
        ];
    }

    public function count(): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM chat_messages WHERE user_id = :user_id');
        $statement->execute(['user_id' => $this->userId]);

        return (int) $statement->fetchColumn();
    }

    private function prune(): void
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))
            ->modify(sprintf('-%d days', self::RETENTION_DAYS))
            ->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'DELETE FROM chat_messages WHERE user_id = :user_id AND created_at < :cutoff'
        );
        $statement->execute(['user_id' => $this->userId, 'cutoff' => $cutoff]);

        $overflow = $this->count() - self::MAX_STORED_MESSAGES;
        if ($overflow <= 0) {
            return;
        }

        $deleteStatement = $this->db->prepare(
            'DELETE FROM chat_messages
             WHERE id IN (
               SELECT id FROM chat_messages
               WHERE user_id = :user_id
               ORDER BY id ASC
               LIMIT :limit
             )'
        );
        $deleteStatement->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
        $deleteStatement->bindValue(':limit', $overflow, PDO::PARAM_INT);
        $deleteStatement->execute();
    }

    /**
     * @param array{id: int|string, role: string, content: string, created_at: string} $row
     * @return array{id: int, role: string, content: string, createdAt: string}
     */
    private function formatRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'role' => (string) $row['role'],
            'content' => (string) $row['content'],
            'createdAt' => (string) $row['created_at'],
        ];
    }
}
