<?php

declare(strict_types=1);

/**
 * Anthropic 利用料金の概算（料金はここに集約）。
 */
final class AnthropicPricingCalculator
{
    /** 概算 USD / MTok（環境変数で上書き可） */
    public function estimateUsd(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheCreationTokens = 0,
        int $webSearchRequests = 0,
    ): float {
        $inputPerMTok = (float) (getenv('ANTHROPIC_PRICE_INPUT_PER_MTOK') ?: 3.0);
        $outputPerMTok = (float) (getenv('ANTHROPIC_PRICE_OUTPUT_PER_MTOK') ?: 15.0);
        $cacheReadPerMTok = (float) (getenv('ANTHROPIC_PRICE_CACHE_READ_PER_MTOK') ?: 0.30);
        $cacheCreatePerMTok = (float) (getenv('ANTHROPIC_PRICE_CACHE_WRITE_PER_MTOK') ?: 3.75);
        $webSearchPerRequest = (float) (getenv('ANTHROPIC_PRICE_WEB_SEARCH_PER_REQUEST') ?: 0.01);

        $usd = ($inputTokens / 1_000_000) * $inputPerMTok
            + ($outputTokens / 1_000_000) * $outputPerMTok
            + ($cacheReadTokens / 1_000_000) * $cacheReadPerMTok
            + ($cacheCreationTokens / 1_000_000) * $cacheCreatePerMTok
            + ($webSearchRequests * $webSearchPerRequest);

        return round($usd, 6);
    }
}
