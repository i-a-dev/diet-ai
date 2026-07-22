<?php

declare(strict_types=1);

/**
 * 検索実行時のデッドライン・モード・予算コンテキスト。
 */
final class SearchRuntimeContext
{
    public function __construct(
        public readonly SearchTiming $timing = new SearchTiming(),
        public readonly bool $allowExpensiveFallback = false,
        public readonly string $claudeFallbackMode = 'conditional',
        public readonly int $totalDeadlineMs = 12_000,
        public readonly int $connectTimeoutMs = 2_000,
        public readonly int $requestTimeoutMs = 4_000,
        public readonly int $maxResponseBytes = 1_500_000,
        public readonly int $maxRedirects = 3,
        public readonly int $maxParallelFetches = 3,
        public readonly int $maxParallelPerHost = 2,
    ) {
    }

    public static function fromEnvironment(bool $allowExpensiveFallback = false): self
    {
        $mode = strtolower(trim((string) (getenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE') ?: 'conditional')));
        if (!in_array($mode, ['off', 'conditional', 'manual', 'always'], true)) {
            $mode = 'conditional';
        }

        return new self(
            timing: new SearchTiming(),
            allowExpensiveFallback: $allowExpensiveFallback,
            claudeFallbackMode: $mode,
            totalDeadlineMs: self::envInt('AI_WEB_SEARCH_TOTAL_DEADLINE_MS', 12_000),
            connectTimeoutMs: self::envInt('AI_WEB_SEARCH_CONNECT_TIMEOUT_MS', 2_000),
            requestTimeoutMs: self::envInt('AI_WEB_SEARCH_REQUEST_TIMEOUT_MS', 4_000),
            maxResponseBytes: self::envInt('AI_WEB_SEARCH_MAX_RESPONSE_BYTES', 1_500_000),
            maxRedirects: self::envInt('AI_WEB_SEARCH_MAX_REDIRECTS', 3),
            maxParallelFetches: self::envInt('AI_WEB_SEARCH_MAX_PARALLEL_FETCHES', 3),
            maxParallelPerHost: self::envInt('AI_WEB_SEARCH_MAX_PARALLEL_PER_HOST', 2),
        );
    }

    private static function envInt(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }

        return max(0, (int) $raw);
    }
}
