<?php

declare(strict_types=1);

/**
 * メール・パスワード認証と長期セッション管理を担当するクラス。
 */
final class AuthService
{
    /** ログイン状態を維持する日数 */
    public const SESSION_TTL_DAYS = 90;

    /** メール認証リンクの有効期限（時間） */
    public const EMAIL_VERIFICATION_TTL_HOURS = 24;

    /** パスワード再設定リンクの有効期限（時間） */
    public const PASSWORD_RESET_TTL_HOURS = 1;

    private const MIN_PASSWORD_LENGTH = 8;

    private PDO $db;
    private MailService $mailService;

    public function __construct(?PDO $db = null, ?MailService $mailService = null)
    {
        $this->db = $db ?? Database::connection();
        $this->mailService = $mailService ?? new MailService();
    }

    /**
     * @return array{requiresVerification: true, email: string, message: string}
     */
    public function register(string $email, string $password): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $this->validatePassword($password);

        $existingUser = $this->findUserByEmail($normalizedEmail);
        if ($existingUser !== null && $this->isEmailVerified($existingUser)) {
            throw new InvalidArgumentException('このメールアドレスは既に登録されています');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $now = $this->now();
        $isReregister = $existingUser !== null;

        $this->db->beginTransaction();
        try {
            if ($isReregister) {
                $userId = (int) $existingUser['id'];
                $this->db->prepare(
                    'UPDATE users SET password_hash = :password_hash WHERE id = :id'
                )->execute([
                    'password_hash' => $passwordHash,
                    'id' => $userId,
                ]);
                $this->deleteEmailVerificationTokensForUser($userId);
            } else {
                $statement = $this->db->prepare(
                    'INSERT INTO users (email, password_hash, email_verified_at, created_at)
                     VALUES (:email, :password_hash, NULL, :created_at)'
                );
                $statement->execute([
                    'email' => $normalizedEmail,
                    'password_hash' => $passwordHash,
                    'created_at' => $now,
                ]);

                $userId = (int) $this->db->lastInsertId();
                $this->ensureProfileRow($userId);
            }

            $verificationToken = $this->createEmailVerificationToken($userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $this->mailService->sendVerificationEmail($normalizedEmail, $verificationToken);

        return [
            'requiresVerification' => true,
            'email' => $normalizedEmail,
            'message' => $isReregister
                ? '認証が未完了のため、確認メールを再送しました。メール内のリンクから認証を完了してください。'
                : '確認メールを送信しました。メール内のリンクから認証を完了してください。',
        ];
    }

    /**
     * @return array{token: string, user: array{id: int, email: string, emailVerified: bool}}
     */
    public function login(string $email, string $password): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $user = $this->findUserByEmail($normalizedEmail);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw new InvalidArgumentException('メールアドレスまたはパスワードが正しくありません');
        }

        if (!$this->isEmailVerified($user)) {
            throw new InvalidArgumentException(
                'メールアドレスの認証が完了していません。受信トレイの確認メールをご確認ください。'
            );
        }

        $userId = (int) $user['id'];
        $token = $this->createSession($userId);

        return [
            'token' => $token,
            'user' => $this->formatUser($user),
        ];
    }

    /**
     * @return array{token: string, user: array{id: int, email: string, emailVerified: bool}}
     */
    public function verifyEmail(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('認証トークンが無効です');
        }

        $row = $this->findEmailVerificationToken($token);
        if ($row === null) {
            throw new InvalidArgumentException('認証リンクが無効または期限切れです');
        }

        $userId = (int) $row['user_id'];
        $user = $this->findUserById($userId);
        if ($user === null) {
            throw new RuntimeException('User not found for verification.');
        }

        // 既に認証済み（リンクの再クリックや二重リクエスト）でも成功として扱う
        if ($this->isEmailVerified($user)) {
            $sessionToken = $this->createSession($userId);

            return [
                'token' => $sessionToken,
                'user' => $this->formatUser($user),
            ];
        }

        $now = $this->now();

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE users SET email_verified_at = :verified_at WHERE id = :id'
            )->execute([
                'verified_at' => $now,
                'id' => $userId,
            ]);
            $sessionToken = $this->createSession($userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $user = $this->findUserById($userId);
        if ($user === null) {
            throw new RuntimeException('User not found after verification.');
        }

        return [
            'token' => $sessionToken,
            'user' => $this->formatUser($user),
        ];
    }

    /**
     * @return array{message: string}
     */
    public function resendVerificationEmail(string $email): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $user = $this->findUserByEmail($normalizedEmail);

        if ($user === null) {
            return [
                'message' => '登録されている場合、確認メールを再送しました。',
            ];
        }

        if ($this->isEmailVerified($user)) {
            throw new InvalidArgumentException('このメールアドレスは既に認証済みです');
        }

        $userId = (int) $user['id'];
        $this->deleteEmailVerificationTokensForUser($userId);
        $verificationToken = $this->createEmailVerificationToken($userId);
        $this->mailService->sendVerificationEmail($normalizedEmail, $verificationToken);

        return [
            'message' => '確認メールを再送しました。',
        ];
    }

    /**
     * @return array{message: string}
     */
    public function requestPasswordReset(string $email): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $user = $this->findUserByEmail($normalizedEmail);

        if ($user === null) {
            throw new InvalidArgumentException('このメールアドレスは登録されていません。');
        }

        if (!$this->isEmailVerified($user)) {
            throw new InvalidArgumentException(
                'メールアドレスの認証が完了していません。受信トレイの確認メールをご確認ください。'
            );
        }

        $userId = (int) $user['id'];
        $this->deletePasswordResetTokensForUser($userId);
        $resetToken = $this->createPasswordResetToken($userId);
        $this->mailService->sendPasswordResetEmail($normalizedEmail, $resetToken);

        return [
            'message' => 'パスワード再設定用のメールを送信しました。',
        ];
    }

    /**
     * @return array{message: string}
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('再設定トークンが無効です');
        }

        $this->validatePassword($newPassword);
        $row = $this->findPasswordResetToken($token);
        if ($row === null) {
            throw new InvalidArgumentException('再設定リンクが無効または期限切れです');
        }

        $userId = (int) $row['user_id'];
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE users SET password_hash = :password_hash WHERE id = :id'
            )->execute([
                'password_hash' => $passwordHash,
                'id' => $userId,
            ]);
            $this->deletePasswordResetTokensForUser($userId);
            $this->deleteSessionsForUser($userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return [
            'message' => 'パスワードを再設定しました。新しいパスワードでログインしてください。',
        ];
    }

    public function logout(string $token): void
    {
        $tokenHash = $this->hashToken($token);
        $statement = $this->db->prepare('DELETE FROM auth_sessions WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);
    }

    /**
     * @return array{id: int, email: string, emailVerified: bool}|null
     */
    public function resolveUserFromToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $tokenHash = $this->hashToken($token);
        $statement = $this->db->prepare(
            'SELECT u.id, u.email, u.email_verified_at, s.expires_at
             FROM auth_sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :token_hash
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $expiresAt = (string) $row['expires_at'];
        if ($expiresAt < $this->now()) {
            $this->logout($token);

            return null;
        }

        if (!$this->isEmailVerified($row)) {
            return null;
        }

        return $this->formatUser($row);
    }

    private function createEmailVerificationToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))
            ->modify(sprintf('+%d hours', self::EMAIL_VERIFICATION_TTL_HOURS))
            ->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $this->hashToken($token),
            'expires_at' => $expiresAt,
            'created_at' => $this->now(),
        ]);

        return $token;
    }

    private function createPasswordResetToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))
            ->modify(sprintf('+%d hours', self::PASSWORD_RESET_TTL_HOURS))
            ->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $this->hashToken($token),
            'expires_at' => $expiresAt,
            'created_at' => $this->now(),
        ]);

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEmailVerificationToken(string $token): ?array
    {
        $statement = $this->db->prepare(
            'SELECT user_id, expires_at
             FROM email_verification_tokens
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $this->hashToken($token)]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        if ((string) $row['expires_at'] < $this->now()) {
            $this->db->prepare(
                'DELETE FROM email_verification_tokens WHERE token_hash = :token_hash'
            )->execute(['token_hash' => $this->hashToken($token)]);

            return null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPasswordResetToken(string $token): ?array
    {
        $statement = $this->db->prepare(
            'SELECT user_id, expires_at
             FROM password_reset_tokens
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $this->hashToken($token)]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        if ((string) $row['expires_at'] < $this->now()) {
            $this->db->prepare(
                'DELETE FROM password_reset_tokens WHERE token_hash = :token_hash'
            )->execute(['token_hash' => $this->hashToken($token)]);

            return null;
        }

        return $row;
    }

    private function deleteEmailVerificationTokensForUser(int $userId): void
    {
        $this->db->prepare('DELETE FROM email_verification_tokens WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    private function deletePasswordResetTokensForUser(int $userId): void
    {
        $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    private function deleteSessionsForUser(int $userId): void
    {
        $this->db->prepare('DELETE FROM auth_sessions WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    private function createSession(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = $this->hashToken($token);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))
            ->modify(sprintf('+%d days', self::SESSION_TTL_DAYS))
            ->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'INSERT INTO auth_sessions (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => $this->now(),
        ]);

        return $token;
    }

    private function ensureProfileRow(int $userId): void
    {
        $this->db->prepare(
            'INSERT OR IGNORE INTO user_profile (user_id, updated_at) VALUES (:user_id, :updated_at)'
        )->execute([
            'user_id' => $userId,
            'updated_at' => $this->now(),
        ]);
    }

    private function findUserIdByEmail(string $email): ?int
    {
        $user = $this->findUserByEmail($email);

        return $user === null ? null : (int) $user['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByEmail(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, email, password_hash, email_verified_at
             FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserById(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, email, password_hash, email_verified_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, email: string, emailVerified: bool}
     */
    private function formatUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'emailVerified' => $this->isEmailVerified($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isEmailVerified(array $row): bool
    {
        $verifiedAt = $row['email_verified_at'] ?? null;

        return is_string($verifiedAt) && $verifiedAt !== '';
    }

    private function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('有効なメールアドレスを入力してください');
        }

        return $normalized;
    }

    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('パスワードは%d文字以上で入力してください', self::MIN_PASSWORD_LENGTH)
            );
        }
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    }
}
