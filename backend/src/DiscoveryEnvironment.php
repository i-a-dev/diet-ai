<?php

declare(strict_types=1);

/**
 * 公式ページ探索の実行環境（Brave結果・テスト用HTTP差し替え）。
 */
final class DiscoveryEnvironment
{
    /**
     * @param list<array{title?: string, url?: string, description?: string, extra_snippets?: list<string>}> $searchResults
     * @param callable(string): ?string|null $httpFetcher
     */
    public function __construct(
        public readonly array $searchResults = [],
        private $httpFetcher = null,
    ) {
    }

    public function hasCustomHttpFetcher(): bool
    {
        return $this->httpFetcher !== null;
    }

    public function fetch(string $url): ?string
    {
        if ($this->httpFetcher === null) {
            return null;
        }

        return ($this->httpFetcher)($url);
    }
}
