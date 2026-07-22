<?php

declare(strict_types=1);

/**
 * AI Web 検索計画のキャッシュ（Claude 計画・抽出済み候補）。
 */
final class WebSearchResultCache
{
    private const DEFAULT_TTL_SECONDS = 86400;

    /** 候補スキーマ変更時に上げて古いキャッシュを無効化する */
    private const CACHE_SCHEMA_VERSION = 'v6';

    public function __construct(
        private readonly string $cacheDir = '',
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    public function isEnabled(): bool
    {
        $raw = getenv('AI_WEB_SEARCH_CACHE_ENABLED');
        if ($raw === false || $raw === '') {
            return true;
        }

        return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * 自動確定済みの強い結果だけキャッシュする。
     *
     * @param array<string, mixed> $response
     */
    public function shouldCacheResponse(array $response): bool
    {
        if (($response['needs_confirmation'] ?? false) === true) {
            return false;
        }

        $status = (string) ($response['web_search_status'] ?? '');
        if (in_array($status, [
            'needs_variant_confirmation',
            'identity_ambiguous',
            'variant_ambiguous',
            'estimated_fallback',
            'no_web_search',
            'not_found',
        ], true)) {
            return false;
        }

        if ((int) ($response['kcal'] ?? 0) <= 0) {
            return false;
        }

        if (($response['identity_confidence'] ?? '') !== 'high') {
            return false;
        }

        if (($response['verification_confidence'] ?? 'medium') === 'low') {
            return false;
        }

        if ($status !== '' && $status !== 'confirmed') {
            return false;
        }

        return true;
    }

    /**
     * @return array{plan?: array<string, mixed>, candidates?: list<array<string, mixed>>}|null
     */
    public function get(
        string $userInput,
        ?string $brandName = null,
        ?string $variantHint = null,
        string $provider = AiWebSearchProvider::AUTO,
    ): ?array {
        if (!$this->isEnabled()) {
            return null;
        }

        $path = $this->pathForKey($this->buildKey($userInput, $brandName, $variantHint, $provider));
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

        $payload = $decoded['payload'] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        $response = $payload['response'] ?? null;
        if (is_array($response) && !$this->shouldCacheResponse($response)) {
            @unlink($path);

            return null;
        }

        return $payload;
    }

    /**
     * @param array{plan?: array<string, mixed>, candidates?: list<array<string, mixed>>, response?: array<string, mixed>} $payload
     */
    public function put(
        string $userInput,
        array $payload,
        ?string $brandName = null,
        ?string $variantHint = null,
        string $provider = AiWebSearchProvider::AUTO,
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $response = $payload['response'] ?? null;
        if (is_array($response) && !$this->shouldCacheResponse($response)) {
            return;
        }

        $dir = $this->resolveCacheDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $path = $this->pathForKey($this->buildKey($userInput, $brandName, $variantHint, $provider));
        $encoded = json_encode([
            'savedAt' => time(),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE);

        if ($encoded !== false) {
            file_put_contents($path, $encoded, LOCK_EX);
        }
    }

    private function buildKey(
        string $userInput,
        ?string $brandName,
        ?string $variantHint,
        string $provider,
    ): string {
        $parts = [
            self::CACHE_SCHEMA_VERSION,
            AiWebSearchProvider::resolve($provider),
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
