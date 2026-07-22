<?php

declare(strict_types=1);

/**
 * ドメイン×Profile version 単位の公式URLインデックスキャッシュ。
 */
final class OfficialDiscoveryIndexCache
{
    private const DEFAULT_TTL_SECONDS = 43200; // 12h

    public function __construct(
        private readonly string $cacheDir = '',
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * @return list<array{url: string, candidate_name?: string|null, discovery_source?: string, evidence?: list<string>, kcal_hint?: int|null}>|null
     */
    public function get(string $domain, int $profileVersion): ?array
    {
        $path = $this->pathFor($domain, $profileVersion);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $expiresAt = (int) ($decoded['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() > $expiresAt) {
            @unlink($path);

            return null;
        }

        if ((int) ($decoded['profile_version'] ?? -1) !== $profileVersion) {
            @unlink($path);

            return null;
        }

        $items = $decoded['items'] ?? null;

        return is_array($items) ? array_values($items) : null;
    }

    /**
     * @param list<array{url: string, candidate_name?: string|null, discovery_source?: string, evidence?: list<string>, kcal_hint?: int|null}> $items
     */
    public function put(string $domain, int $profileVersion, array $items): void
    {
        $dir = $this->resolveDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = [
            'domain' => mb_strtolower(trim($domain)),
            'profile_version' => $profileVersion,
            'fetched_at' => gmdate('c'),
            'expires_at' => time() + $this->ttlSeconds,
            'items' => array_values($items),
        ];
        @file_put_contents($this->pathFor($domain, $profileVersion), json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function resolveDir(): string
    {
        if ($this->cacheDir !== '') {
            return $this->cacheDir;
        }

        return sys_get_temp_dir() . '/diet_ai_official_discovery';
    }

    private function pathFor(string $domain, int $profileVersion): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', mb_strtolower(trim($domain))) ?: 'domain';

        return rtrim($this->resolveDir(), '/') . '/idx_' . $safe . '_v' . $profileVersion . '.json';
    }
}
