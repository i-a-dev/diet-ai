<?php

declare(strict_types=1);

/**
 * AI Web 検索計画のキャッシュ（Claude 計画・抽出済み候補）。
 */
final class WebSearchResultCache
{
    private const DEFAULT_TTL_SECONDS = 86400;

    public function __construct(
        private readonly string $cacheDir = '',
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * @return array{plan?: array<string, mixed>, candidates?: list<array<string, mixed>>}|null
     */
    public function get(string $userInput, ?string $brandName = null, ?string $variantHint = null): ?array
    {
        $path = $this->pathForKey($this->buildKey($userInput, $brandName, $variantHint));
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $savedAt = (int) ($decoded['savedAt'] ?? 0);
        if ($savedAt <= 0 || (time() - $savedAt) > $this->ttlSeconds) {
            @unlink($path);

            return null;
        }

        return $decoded['payload'] ?? null;
    }

    /**
     * @param array{plan?: array<string, mixed>, candidates?: list<array<string, mixed>>} $payload
     */
    public function put(string $userInput, array $payload, ?string $brandName = null, ?string $variantHint = null): void
    {
        $dir = $this->resolveCacheDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $path = $this->pathForKey($this->buildKey($userInput, $brandName, $variantHint));
        $encoded = json_encode([
            'savedAt' => time(),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE);

        if ($encoded !== false) {
            file_put_contents($path, $encoded, LOCK_EX);
        }
    }

    private function buildKey(string $userInput, ?string $brandName, ?string $variantHint): string
    {
        $parts = [
            mb_strtolower(trim($userInput)),
            mb_strtolower(trim((string) $brandName)),
            mb_strtolower(trim((string) $variantHint)),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function pathForKey(string $key): string
    {
        return $this->resolveCacheDir() . '/' . $key . '.json';
    }

    private function resolveCacheDir(): string
    {
        if ($this->cacheDir !== '') {
            return $this->cacheDir;
        }

        return dirname(__DIR__) . '/data/web_search_cache';
    }
}
