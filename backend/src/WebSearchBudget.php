<?php

declare(strict_types=1);

/**
 * AI Web 検索の API 呼び出し予算を管理する。
 */
final class WebSearchBudget
{
    public const INITIAL_BRAVE_SEARCHES = 2;
    public const ADDITIONAL_BRAVE_SEARCHES = 2;
    public const MAX_TOTAL_BRAVE_SEARCHES = 4;
    public const MAX_HTML_FETCHES = 6;
    public const MAX_CLAUDE_WEB_SEARCHES = 1;

    private int $haikuCalls = 0;
    private int $braveSearchCalls = 0;
    private int $htmlFetchCalls = 0;
    private int $claudeWebSearchCalls = 0;

    /** @var list<string> */
    private array $executedBraveQueries = [];

    /** @var list<string> */
    private array $fetchedUrls = [];

    public function canCallHaiku(): bool
    {
        return $this->haikuCalls < 1;
    }

    public function recordHaikuCall(): void
    {
        $this->haikuCalls++;
    }

    public function canBraveSearch(): bool
    {
        return $this->braveSearchCalls < self::MAX_TOTAL_BRAVE_SEARCHES;
    }

    public function canInitialBraveSearch(): bool
    {
        return $this->braveSearchCalls < self::INITIAL_BRAVE_SEARCHES;
    }

    public function canAdditionalBraveSearch(): bool
    {
        return $this->braveSearchCalls < self::MAX_TOTAL_BRAVE_SEARCHES;
    }

    public function shouldExecuteBraveQuery(string $query): bool
    {
        if (!$this->canBraveSearch()) {
            return false;
        }

        $normalized = $this->normalizeQuery($query);

        return $normalized !== '' && !in_array($normalized, $this->executedBraveQueries, true);
    }

    public function recordBraveSearch(string $query): void
    {
        $this->braveSearchCalls++;
        $normalized = $this->normalizeQuery($query);
        if ($normalized !== '' && !in_array($normalized, $this->executedBraveQueries, true)) {
            $this->executedBraveQueries[] = $normalized;
        }
    }

    public function canFetchHtml(string $url): bool
    {
        if (!$this->hasHtmlFetchBudgetRemaining()) {
            return false;
        }

        $normalized = $this->normalizeUrl($url);

        return $normalized !== '' && !in_array($normalized, $this->fetchedUrls, true);
    }

    public function hasHtmlFetchBudgetRemaining(): bool
    {
        return $this->htmlFetchCalls < self::MAX_HTML_FETCHES;
    }

    public function recordHtmlFetch(string $url): void
    {
        $this->htmlFetchCalls++;
        $normalized = $this->normalizeUrl($url);
        if ($normalized !== '' && !in_array($normalized, $this->fetchedUrls, true)) {
            $this->fetchedUrls[] = $normalized;
        }
    }

    public function canClaudeWebSearch(): bool
    {
        return $this->claudeWebSearchCalls < self::MAX_CLAUDE_WEB_SEARCHES;
    }

    public function recordClaudeWebSearch(): void
    {
        $this->claudeWebSearchCalls++;
    }

    /**
     * @return array{
     *   haikuCalls: int,
     *   braveSearchCalls: int,
     *   htmlFetchCalls: int,
     *   claudeWebSearchCalls: int
     * }
     */
    public function snapshot(): array
    {
        return [
            'haikuCalls' => $this->haikuCalls,
            'braveSearchCalls' => $this->braveSearchCalls,
            'htmlFetchCalls' => $this->htmlFetchCalls,
            'claudeWebSearchCalls' => $this->claudeWebSearchCalls,
        ];
    }

    private function normalizeQuery(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''));
    }

    private function normalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return mb_strtolower($trimmed);
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        $path = rtrim($path, '/') ?: '/';

        return $host . $path;
    }
}
