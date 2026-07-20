<?php

declare(strict_types=1);

/**
 * お問い合わせ受付を担当する。
 * 送信先メール・APIキーは環境変数のみから読む。
 */
final class ContactInquiryService
{
    public const MAX_SUBJECT_LENGTH = 100;
    public const MAX_BODY_LENGTH = 2000;
    public const MAX_PER_HOUR = 5;

    /** @var list<string> */
    public const CATEGORIES = [
        'app_usage',
        'bug',
        'billing',
        'account',
        'ai_coach',
        'other',
    ];

    private PDO $db;
    private MailService $mailService;

    public function __construct(?PDO $db = null, ?MailService $mailService = null)
    {
        $this->db = $db ?? Database::connection();
        $this->mailService = $mailService ?? new MailService();
    }

    /**
     * @param array{
     *   category?: mixed,
     *   subject?: mixed,
     *   body?: mixed,
     *   replyEmail?: mixed,
     *   honeypot?: mixed
     * } $input
     * @return array{ok: true, message: string}
     */
    public function submit(int $userId, array $input): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('ユーザーが特定できません');
        }

        // スパムボット対策用 honeypot（フロントは非表示フィールド）。埋まっていれば成功風に返す
        $honeypot = trim((string) ($input['honeypot'] ?? ''));
        if ($honeypot !== '') {
            return [
                'ok' => true,
                'message' => 'お問い合わせを受け付けました',
            ];
        }

        $category = trim((string) ($input['category'] ?? ''));
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('お問い合わせ種別を選択してください');
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('件名を入力してください');
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('件名は%d文字以内で入力してください', self::MAX_SUBJECT_LENGTH)
            );
        }

        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            throw new InvalidArgumentException('お問い合わせ内容を入力してください');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('お問い合わせ内容は%d文字以内で入力してください', self::MAX_BODY_LENGTH)
            );
        }

        $replyEmail = strtolower(trim((string) ($input['replyEmail'] ?? '')));
        if ($replyEmail === '' || !filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('有効な返信先メールアドレスを入力してください');
        }

        if ($this->countRecentInquiries($userId) >= self::MAX_PER_HOUR) {
            throw new InvalidArgumentException(
                '送信回数の上限に達しました。しばらく時間をおいてから再度お試しください。'
            );
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO contact_inquiries (user_id, category, subject, body, reply_email, created_at)
                 VALUES (:user_id, :category, :subject, :body, :reply_email, :created_at)'
            );
            $statement->execute([
                'user_id' => $userId,
                'category' => $category,
                'subject' => $subject,
                'body' => $body,
                'reply_email' => $replyEmail,
                'created_at' => $now,
            ]);
            $inquiryId = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log(sprintf(
                '[ContactInquiry] persist failed user_id=%d error=%s',
                $userId,
                $exception->getMessage()
            ));
            throw new RuntimeException('お問い合わせの保存に失敗しました。しばらくしてから再度お試しください。');
        }

        try {
            $this->mailService->sendContactInquiryNotification(
                $inquiryId,
                $userId,
                $category,
                $subject,
                $body,
                $replyEmail
            );
        } catch (Throwable $exception) {
            // 本文はログに出さない
            error_log(sprintf(
                '[ContactInquiry] notify failed inquiry_id=%d user_id=%d error=%s',
                $inquiryId,
                $userId,
                $exception->getMessage()
            ));
            throw new RuntimeException('お問い合わせの送信に失敗しました。しばらくしてから再度お試しください。');
        }

        return [
            'ok' => true,
            'message' => 'お問い合わせを受け付けました。返信までしばらくお待ちください。',
        ];
    }

    /**
     * バリデーションのみ（DB 不要の単体テスト用）。
     *
     * @param array<string, mixed> $input
     * @return array{category: string, subject: string, body: string, replyEmail: string}
     */
    public static function validateInput(array $input): array
    {
        $honeypot = trim((string) ($input['honeypot'] ?? ''));
        if ($honeypot !== '') {
            return [
                'category' => 'other',
                'subject' => '',
                'body' => '',
                'replyEmail' => '',
            ];
        }

        $category = trim((string) ($input['category'] ?? ''));
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('お問い合わせ種別を選択してください');
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('件名を入力してください');
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('件名は%d文字以内で入力してください', self::MAX_SUBJECT_LENGTH)
            );
        }

        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            throw new InvalidArgumentException('お問い合わせ内容を入力してください');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('お問い合わせ内容は%d文字以内で入力してください', self::MAX_BODY_LENGTH)
            );
        }

        $replyEmail = strtolower(trim((string) ($input['replyEmail'] ?? '')));
        if ($replyEmail === '' || !filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('有効な返信先メールアドレスを入力してください');
        }

        return [
            'category' => $category,
            'subject' => $subject,
            'body' => $body,
            'replyEmail' => $replyEmail,
        ];
    }

    private function countRecentInquiries(int $userId): int
    {
        $since = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))
            ->modify('-1 hour')
            ->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM contact_inquiries
             WHERE user_id = :user_id AND created_at >= :since'
        );
        $statement->execute([
            'user_id' => $userId,
            'since' => $since,
        ]);

        return (int) $statement->fetchColumn();
    }
}
