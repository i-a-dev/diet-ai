<?php

declare(strict_types=1);

/**
 * メール送信を担当するクラス。
 * MAIL_DRIVER=log のときは data/mail.log に書き出す（開発用）。
 * MAIL_DRIVER=resend のときは Resend API 経由で送信する。
 */
final class MailService
{
    private const RESEND_API_URL = 'https://api.resend.com/emails';
    private const SUBJECT_VERIFY = '【ダイエットアプリ】メールアドレスの確認';
    private const SUBJECT_RESET = '【ダイエットアプリ】パスワード再設定';

    /**
     * メール認証リンクを送信する。
     */
    public function sendVerificationEmail(string $to, string $token): void
    {
        $url = $this->buildFrontendUrl('/auth/verify-email', ['token' => $token]);
        $body = <<<TEXT
ダイエットアプリへご登録ありがとうございます。

以下のリンクをクリックして、メールアドレスの確認を完了してください。
（リンクの有効期限は24時間です）

{$url}

心当たりがない場合は、このメールを無視してください。
TEXT;

        $this->send($to, self::SUBJECT_VERIFY, $body);
    }

    /**
     * パスワード再設定リンクを送信する。
     */
    public function sendPasswordResetEmail(string $to, string $token): void
    {
        $url = $this->buildFrontendUrl('/auth/reset-password', ['token' => $token]);
        $body = <<<TEXT
パスワード再設定のリクエストを受け付けました。

以下のリンクから新しいパスワードを設定してください。
（リンクの有効期限は1時間です）

{$url}

心当たりがない場合は、このメールを無視してください。
TEXT;

        $this->send($to, self::SUBJECT_RESET, $body);
    }

    private function send(string $to, string $subject, string $body): void
    {
        $driver = strtolower(trim((string) (getenv('MAIL_DRIVER') ?: 'log')));
        $from = trim((string) (getenv('MAIL_FROM') ?: 'noreply@diet-ai.local'));

        if ($driver === 'log') {
            $this->logMail($from, $to, $subject, $body);

            return;
        }

        if ($driver === 'resend') {
            try {
                $this->sendViaResend($from, $to, $subject, $body);
            } catch (RuntimeException $exception) {
                if ($this->shouldFallbackToLog($exception)) {
                    $this->logMail($from, $to, $subject, $body);

                    return;
                }

                throw $exception;
            }

            return;
        }

        throw new RuntimeException(
            sprintf('Unsupported MAIL_DRIVER "%s". Use "log" (development) or "resend" (production).', $driver)
        );
    }

    private function shouldFallbackToLog(RuntimeException $exception): bool
    {
        if (!$this->isLocalDevelopment()) {
            return false;
        }

        $message = $exception->getMessage();

        // Resend の無料プランでは登録メール宛（onboarding@resend.dev）のみ送信可
        return str_contains($message, 'Resend API error (403)')
            || str_contains($message, 'testing emails');
    }

    private function isLocalDevelopment(): bool
    {
        $frontendUrl = strtolower(trim((string) (getenv('FRONTEND_URL') ?: 'http://localhost:5173')));

        return str_contains($frontendUrl, 'localhost') || str_contains($frontendUrl, '127.0.0.1');
    }

    private function logMail(string $from, string $to, string $subject, string $body): void
    {
        $path = getenv('MAIL_LOG_PATH') ?: dirname(__DIR__) . '/data/mail.log';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create mail log directory.');
        }

        $entry = sprintf(
            "[%s]\nFrom: %s\nTo: %s\nSubject: %s\n\n%s\n\n%s\n",
            (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s'),
            $from,
            $to,
            $subject,
            $body,
            str_repeat('-', 60)
        );

        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Failed to write mail log.');
        }
    }

    private function sendViaResend(string $from, string $to, string $subject, string $body): void
    {
        $apiKey = trim((string) (getenv('RESEND_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new RuntimeException('RESEND_API_KEY is required when MAIL_DRIVER=resend');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl 拡張が有効になっていません。');
        }

        $payload = json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'text' => $body,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Failed to encode Resend request payload.');
        }

        $ch = curl_init(self::RESEND_API_URL);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize Resend request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
        ]);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException(
                $curlError !== ''
                    ? 'Resend API への接続に失敗しました: ' . $curlError
                    : 'Resend API への接続に失敗しました。'
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode($responseBody, true);
            $message = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : trim($responseBody);

            throw new RuntimeException(
                sprintf('Resend API error (%d): %s', $httpCode, $message !== '' ? $message : 'Unknown error')
            );
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function buildFrontendUrl(string $path, array $query = []): string
    {
        $base = rtrim(trim((string) (getenv('FRONTEND_URL') ?: 'http://localhost:5173')), '/');
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
