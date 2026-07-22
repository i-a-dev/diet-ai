<?php

declare(strict_types=1);

/**
 * Claude not_found のネガティブキャッシュ。
 */
final class ClaudeNotFoundCache
{
    public function __construct(
        private readonly string $cacheDir = '',
        private readonly int $ttlSeconds = 0,
    ) {
    }

    private function ttl(): int
    {
        if ($this->ttlSeconds > 0) {
            return $this->ttlSeconds;
        }
        $raw = getenv('AI_WEB_SEARCH_CLAUDE_NOT_FOUND_TTL_SECONDS');

        return $raw === false || $raw === '' ? 3_600 : max(60, (int) $raw);
    }

    private function dir(): string
    {
        if ($this->cacheDir !== '') {
            return $this->cacheDir;
        }

        return dirname(__DIR__) . '/data/claude_not_found_cache';
    }

    public function has(string $userInput): bool
    {
        $path = $this->path($userInput);
        if (!is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return false;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int) ($decoded['expires_at'] ?? 0) < time()) {
            @unlink($path);

            return false;
        }

        return true;
    }

    public function put(string $userInput): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($this->path($userInput), json_encode([
            'expires_at' => time() + $this->ttl(),
            'status' => 'not_found',
        ], JSON_UNESCAPED_UNICODE));
    }

    private function path(string $userInput): string
    {
        return rtrim($this->dir(), '/') . '/nf_' . hash('sha256', mb_strtolower(trim($userInput))) . '.json';
    }
}
