<?php

declare(strict_types=1);

/**
 * HTML 抽出結果キャッシュ（URL単位）。
 */
final class HtmlExtractionCache
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
        $raw = getenv('AI_WEB_SEARCH_HTML_CACHE_TTL_SECONDS');

        return $raw === false || $raw === '' ? 21_600 : max(60, (int) $raw);
    }

    private function dir(): string
    {
        if ($this->cacheDir !== '') {
            return $this->cacheDir;
        }

        return dirname(__DIR__) . '/data/html_extraction_cache';
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function get(string $url, string $productKey): ?array
    {
        $path = $this->path($url, $productKey);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        if ((int) ($decoded['expires_at'] ?? 0) < time()) {
            @unlink($path);

            return null;
        }
        $items = $decoded['items'] ?? null;

        return is_array($items) ? $items : null;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function put(string $url, string $productKey, array $items): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($this->path($url, $productKey), json_encode([
            'expires_at' => time() + $this->ttl(),
            'items' => array_values($items),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function path(string $url, string $productKey): string
    {
        $hash = hash('sha256', mb_strtolower(trim($url)) . '|' . mb_strtolower(trim($productKey)));

        return rtrim($this->dir(), '/') . '/html_' . $hash . '.json';
    }
}
