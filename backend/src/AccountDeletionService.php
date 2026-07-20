<?php

declare(strict_types=1);

/**
 * 認証済みユーザー自身のアカウント削除を担当する。
 * リクエストの user_id は信用せず、呼び出し側がトークンから解決した ID のみを受け取る。
 */
class AccountDeletionService
{
    /** 最終確認でユーザーに入力させる文言 */
    public const CONFIRMATION_PHRASE = '削除する';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{ok: true, message: string}
     */
    public function deleteAccount(int $userId, string $password, string $confirmation): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('ユーザーが特定できません');
        }

        if (trim($confirmation) !== self::CONFIRMATION_PHRASE) {
            throw new InvalidArgumentException(
                sprintf('確認のため「%s」と入力してください', self::CONFIRMATION_PHRASE)
            );
        }

        if ($password === '') {
            throw new InvalidArgumentException('パスワードを入力してください');
        }

        $user = $this->findUserById($userId);
        if ($user === null) {
            throw new InvalidArgumentException('ユーザーが見つかりません');
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            throw new InvalidArgumentException('パスワードが正しくありません');
        }

        $this->db->beginTransaction();
        try {
            // FK のないユーザー紐づきデータを先に削除（マイグレーションで確認済みのテーブルのみ）
            $this->deleteByUserId('food_registration_events', $userId);
            $this->deleteByUserId('daily_nutrition_summaries', $userId);
            $this->deleteByUserId('meal_entries', $userId);
            $this->deleteByUserId('exercise_entries', $userId);
            $this->deleteByUserId('step_entries', $userId);
            $this->deleteByUserId('weight_entries', $userId);
            $this->deleteByUserId('chat_messages', $userId);
            $this->deleteByUserId('user_profile', $userId);
            $this->deleteByUserId('food_search_aliases', $userId);
            $this->deleteByUserId('contact_inquiries', $userId);

            // users 削除で CASCADE されるが、明示的に無効化してからユーザー行を消す
            $this->deleteByUserId('auth_sessions', $userId);
            $this->deleteByUserId('email_verification_tokens', $userId);
            $this->deleteByUserId('password_reset_tokens', $userId);

            $deleteUser = $this->db->prepare('DELETE FROM users WHERE id = :id');
            $deleteUser->execute(['id' => $userId]);

            if ($deleteUser->rowCount() !== 1) {
                throw new RuntimeException('Failed to delete user row.');
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log(sprintf(
                '[AccountDeletion] failed user_id=%d error=%s',
                $userId,
                $exception->getMessage()
            ));
            throw $exception;
        }

        // 健康情報・チャット本文は記録しない
        error_log(sprintf('[AccountDeletion] completed user_id=%d', $userId));

        return [
            'ok' => true,
            'message' => 'アカウントを削除しました',
        ];
    }

    private function deleteByUserId(string $table, int $userId): void
    {
        // テーブル名はコード内定数のみ。外部入力は絶対に渡さない。
        $allowed = [
            'food_registration_events',
            'daily_nutrition_summaries',
            'meal_entries',
            'exercise_entries',
            'step_entries',
            'weight_entries',
            'chat_messages',
            'user_profile',
            'food_search_aliases',
            'contact_inquiries',
            'auth_sessions',
            'email_verification_tokens',
            'password_reset_tokens',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Unexpected table for account deletion.');
        }

        $statement = $this->db->prepare(
            sprintf('DELETE FROM %s WHERE user_id = :user_id', $table)
        );
        $statement->execute(['user_id' => $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserById(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, email, password_hash FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
