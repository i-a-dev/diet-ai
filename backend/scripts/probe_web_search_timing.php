<?php

declare(strict_types=1);

/**
 * 対象入力のステージ別実測（Brave+公式。Claude は conditional 既定で通常呼ばない）。
 *
 * Usage (docker):
 *   php scripts/probe_web_search_timing.php
 */

$root = dirname(__DIR__);
require_once $root . '/scripts/EnvLoader.php';
load_project_env(dirname($root) . '/.env');

foreach ([
    'AiWebSearchProvider.php',
    'WebSearchResultCache.php',
    'WebSearchBudget.php',
    'FoodWebSearchPlan.php',
    'FoodWebSearchPlanInputGuard.php',
    'WebSearchDiagnostics.php',
    'FoodVariantAnalyzer.php',
    'NutritionSearchQueryBuilder.php',
    'SearchTiming.php',
    'SearchRuntimeContext.php',
    'BraveSearchService.php',
    'WebSearchUrlRanker.php',
    'ParallelHttpClient.php',
    'NutritionPageExtractor.php',
    'OfficialSiteBrandResolver.php',
    'ProductMatchResult.php',
    'ProductMatchEvaluator.php',
    'NutritionPageVariantExtractor.php',
    'FoodSearchSubject.php',
    'FoodSearchSubjectNormalizer.php',
    'HtmlFetchPlanBuilder.php',
    'OfficialSiteProfile.php',
    'OfficialSiteContext.php',
    'DiscoveryEnvironment.php',
    'OfficialDiscoveryBudget.php',
    'DiscoveredPageCandidate.php',
    'OfficialDiscoveryHttpClient.php',
    'OfficialPathPatternMatcher.php',
    'OfficialSiteProfileRepository.php',
    'OfficialDiscoveryIndexCache.php',
    'OfficialPageDiscoveryStrategy.php',
    'OfficialDiscoveryCandidateFactory.php',
    'RobotsSitemapDiscoveryStrategy.php',
    'SitemapDiscoveryStrategy.php',
    'ListingPageDiscoveryStrategy.php',
    'StructuredDataDiscoveryStrategy.php',
    'EmbeddedJsonDiscoveryStrategy.php',
    'SearchEngineDiscoveryStrategy.php',
    'OfficialPageDiscoveryService.php',
    'FoodWebSearchPlanService.php',
    'ClaudeFallbackDecision.php',
    'ClaudeFallbackPolicy.php',
    'HtmlExtractionCache.php',
    'ClaudeNotFoundCache.php',
    'ClaudeWebSearchGuard.php',
    'AnthropicPricingCalculator.php',
    'WebSearchMetricsStore.php',
    'AiWebSearchService.php',
    'CalorieEstimateService.php',
] as $file) {
    require_once $root . '/src/' . $file;
}

putenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE=conditional');
$input = $argv[1] ?? 'ナッシュ たらの辛旨チリソース';
$apiKey = (string) (getenv('ANTHROPIC_API_KEY') ?: '');

echo "Probe input: {$input}\n";
echo "Claude fallback mode: " . (getenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE') ?: '') . "\n\n";

$service = new CalorieEstimateService();
$t0 = hrtime(true);
$result = $service->estimate($input, 'web');
$totalMs = (int) round((hrtime(true) - $t0) / 1e6);

$timing = is_array($result['search_timing'] ?? null) ? $result['search_timing'] : [];
$stages = is_array($timing['stages'] ?? null) ? $timing['stages'] : [];

echo "Result:\n";
echo json_encode([
    'kcal' => $result['kcal'] ?? null,
    'web_search_status' => $result['web_search_status'] ?? null,
    'identity_confidence' => $result['identity_confidence'] ?? null,
    'source' => $result['source'] ?? null,
    'offer_deep_web_search' => $result['offer_deep_web_search'] ?? null,
    'wall_total_ms' => $totalMs,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "Stages (ms):\n";
ksort($stages);
foreach ($stages as $k => $v) {
    echo sprintf("  %-32s %6d\n", $k, (int) $v);
}

$http = is_array($timing['http_events'] ?? null) ? $timing['http_events'] : [];
echo "\nHTTP events: " . count($http) . "\n";
foreach ($http as $event) {
    echo sprintf(
        "  %-14s %5dms status=%s size=%s cache=%s summary=%s\n",
        (string) ($event['request_type'] ?? ''),
        (int) ($event['duration_ms'] ?? 0),
        var_export($event['http_status'] ?? null, true),
        var_export($event['response_size'] ?? null, true),
        !empty($event['cache_hit']) ? '1' : '0',
        (string) ($event['summary'] ?? ''),
    );
}

echo "\nSecond call (confirmed cache expected):\n";
$t1 = hrtime(true);
$result2 = $service->estimate($input, 'web');
$total2 = (int) round((hrtime(true) - $t1) / 1e6);
echo json_encode([
    'kcal' => $result2['kcal'] ?? null,
    'wall_total_ms' => $total2,
    'stages' => $result2['search_timing']['stages'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
