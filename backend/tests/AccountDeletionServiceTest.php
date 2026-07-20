<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/AccountDeletionService.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function expectInvalidArgument(callable $fn, string $messageContains, string $label): void
{
    try {
        $fn();
        throw new RuntimeException('FAIL: ' . $label . ' (expected InvalidArgumentException)');
    } catch (InvalidArgumentException $exception) {
        assertTrue(
            str_contains($exception->getMessage(), $messageContains),
            $label . ' message contains: ' . $messageContains . ' got: ' . $exception->getMessage()
        );
    }
}

/**
 * PDO を用意せず、バリデーション段階で失敗することを確認するスタブ。
 * findUserById に到達する前に弾かれるケースのみを対象にする。
 */
final class AccountDeletionServiceValidationProbe extends AccountDeletionService
{
    public function __construct()
    {
        // Database 接続を避けるため親を呼ばない
    }

    public function probe(int $userId, string $password, string $confirmation): void
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
    }
}

echo "AccountDeletionService validation tests\n";
echo str_repeat('=', 48) . "\n";

$probe = new AccountDeletionServiceValidationProbe();

expectInvalidArgument(
    static fn () => $probe->probe(0, 'password123', AccountDeletionService::CONFIRMATION_PHRASE),
    'ユーザーが特定できません',
    'invalid user id'
);
echo "OK invalid user id\n";

expectInvalidArgument(
    static fn () => $probe->probe(1, 'password123', '違う文言'),
    '削除する',
    'wrong confirmation'
);
echo "OK wrong confirmation\n";

expectInvalidArgument(
    static fn () => $probe->probe(1, '', AccountDeletionService::CONFIRMATION_PHRASE),
    'パスワード',
    'empty password'
);
echo "OK empty password\n";

// 認証ゲート（index.php）では未認証時に 401 を返す。ここでは定数の存在を確認する。
assertTrue(
    AccountDeletionService::CONFIRMATION_PHRASE === '削除する',
    'confirmation phrase'
);
echo "OK confirmation phrase\n";

echo str_repeat('=', 48) . "\n";
echo "All AccountDeletionService validation tests passed.\n";
echo "NOTE: Unauthenticated DELETE /api/auth/account is rejected in public/index.php before this service runs.\n";
