<?php

declare(strict_types=1);

/**
 * ブランド単位の公式カタログ一覧キャッシュ。
 */
final class OfficialCatalogCache
{
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly string $cacheDir = '',
    ) {
    }

    private function resolveDir(): string
    {
        if ($this->cacheDir !== '') {
            return $this->cacheDir;
        }

        return sys_get_temp_dir() . '/diet_ai_official_catalog';
    }

    /**
     * @return list<array{url: string, product_name: string, brand_name?: string|null, kcal?: int|null}>|null
     */
    public function get(string $brandKey): ?array
    {
        $path = $this->pathFor($brandKey);
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

        $items = $decoded['items'] ?? null;

        return is_array($items) ? array_values($items) : null;
    }

    /**
     * @param list<array{url: string, product_name: string, brand_name?: string|null, kcal?: int|null}> $items
     */
    public function put(string $brandKey, array $items): void
    {
        $dir = $this->resolveDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = [
            'expires_at' => time() + self::TTL_SECONDS,
            'items' => array_values($items),
        ];
        @file_put_contents($this->pathFor($brandKey), json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function pathFor(string $brandKey): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', mb_strtolower(trim($brandKey))) ?: 'brand';

        return rtrim($this->resolveDir(), '/') . '/catalog_' . $safe . '.json';
    }
}
