<?php

declare(strict_types=1);

/**
 * AI Web 検索の実行モード（AI_WEB_SEARCH_PROVIDER）。
 *
 * - auto: Brave → 自動確定できない場合に Claude Web Search
 * - brave_only: Brave のみ（自動確定不可は estimated_fallback / confirmation）
 * - claude_only: Haiku 計画なしで Claude Web Search 直
 */
final class AiWebSearchProvider
{
    public const AUTO = 'auto';
    public const BRAVE_ONLY = 'brave_only';
    public const CLAUDE_ONLY = 'claude_only';

    /** @var list<string> */
    private const ALLOWED = [self::AUTO, self::BRAVE_ONLY, self::CLAUDE_ONLY];

    public static function resolve(?string $raw = null): string
    {
        $value = strtolower(trim((string) ($raw ?? getenv('AI_WEB_SEARCH_PROVIDER') ?: self::AUTO)));
        if ($value === '') {
            return self::AUTO;
        }

        if (!in_array($value, self::ALLOWED, true)) {
            error_log('[ai_web_search] invalid AI_WEB_SEARCH_PROVIDER=' . $value . ', falling back to auto');

            return self::AUTO;
        }

        return $value;
    }

    public static function allowsClaudeFallback(string $provider): bool
    {
        return $provider === self::AUTO;
    }

    public static function usesBravePipeline(string $provider): bool
    {
        return $provider === self::AUTO || $provider === self::BRAVE_ONLY;
    }
}
