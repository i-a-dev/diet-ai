<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/OfficialSiteBrandResolver.php';
require_once __DIR__ . '/../src/FoodVariantAnalyzer.php';
require_once __DIR__ . '/../src/BraveSearchService.php';
require_once __DIR__ . '/../src/BraveNutritionSearchService.php';
require_once __DIR__ . '/../src/FoodWebSearchPlan.php';
require_once __DIR__ . '/../src/FoodWebSearchPlanInputGuard.php';
require_once __DIR__ . '/../src/WebSearchBudget.php';
require_once __DIR__ . '/../src/AiWebSearchProvider.php';
require_once __DIR__ . '/../src/WebSearchDiagnostics.php';
require_once __DIR__ . '/../src/NutritionSearchQueryBuilder.php';
require_once __DIR__ . '/../src/WebSearchUrlRanker.php';
require_once __DIR__ . '/../src/ProductMatchResult.php';
require_once __DIR__ . '/../src/ProductMatchEvaluator.php';
require_once __DIR__ . '/../src/NutritionPageVariantExtractor.php';
require_once __DIR__ . '/../src/WebSearchResultCache.php';
require_once __DIR__ . '/../src/FoodSearchSubject.php';
require_once __DIR__ . '/../src/FoodSearchSubjectNormalizer.php';
require_once __DIR__ . '/../src/HtmlFetchPlanBuilder.php';
require_once __DIR__ . '/../src/OfficialSiteProfile.php';
require_once __DIR__ . '/../src/OfficialSiteContext.php';
require_once __DIR__ . '/../src/DiscoveryEnvironment.php';
require_once __DIR__ . '/../src/OfficialDiscoveryBudget.php';
require_once __DIR__ . '/../src/DiscoveredPageCandidate.php';
require_once __DIR__ . '/../src/OfficialDiscoveryHttpClient.php';
require_once __DIR__ . '/../src/OfficialPathPatternMatcher.php';
require_once __DIR__ . '/../src/OfficialSiteProfileRepository.php';
require_once __DIR__ . '/../src/OfficialDiscoveryIndexCache.php';
require_once __DIR__ . '/../src/OfficialPageDiscoveryStrategy.php';
require_once __DIR__ . '/../src/OfficialDiscoveryCandidateFactory.php';
require_once __DIR__ . '/../src/RobotsSitemapDiscoveryStrategy.php';
require_once __DIR__ . '/../src/SitemapDiscoveryStrategy.php';
require_once __DIR__ . '/../src/ListingPageDiscoveryStrategy.php';
require_once __DIR__ . '/../src/StructuredDataDiscoveryStrategy.php';
require_once __DIR__ . '/../src/EmbeddedJsonDiscoveryStrategy.php';
require_once __DIR__ . '/../src/SearchEngineDiscoveryStrategy.php';
require_once __DIR__ . '/../src/OfficialPageDiscoveryService.php';
require_once __DIR__ . '/../src/FoodWebSearchPlanService.php';
require_once __DIR__ . '/../src/SearchTiming.php';
require_once __DIR__ . '/../src/SearchRuntimeContext.php';
require_once __DIR__ . '/../src/ClaudeFallbackDecision.php';
require_once __DIR__ . '/../src/ClaudeFallbackPolicy.php';
require_once __DIR__ . '/../src/ParallelHttpClient.php';
require_once __DIR__ . '/../src/HtmlExtractionCache.php';
require_once __DIR__ . '/../src/ClaudeNotFoundCache.php';
require_once __DIR__ . '/../src/ClaudeWebSearchGuard.php';
require_once __DIR__ . '/../src/AnthropicPricingCalculator.php';
require_once __DIR__ . '/../src/WebSearchMetricsStore.php';
require_once __DIR__ . '/../src/AiWebSearchService.php';
require_once __DIR__ . '/../src/CalorieEstimateService.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

echo "Claude source URL ranker alignment tests\n";
echo str_repeat('=', 48) . "\n";

$svc = new CalorieEstimateService();
$ref = new ReflectionClass($svc);
$method = $ref->getMethod('rankClaudeSourceUrlsLikeBrave');
$method->setAccessible(true);

$ranked = $method->invoke(
    $svc,
    [
        'https://nosh.jp/menu',
        'https://nosh.jp/use/menu',
        'https://nosh.jp/menu/detail/1015',
    ],
    'たらのレモンあん',
    'ナッシュ',
    ['ナッシュ たらのレモンあん'],
);

assertTrue($ranked !== [], 'ranked urls not empty');
assertTrue(
    ($ranked[0]['url'] ?? '') === 'https://nosh.jp/menu/detail/1015',
    'detail page should rank above menu list pages for Claude URLs',
);
echo "OK Claude URLs use Brave single_product ranker order\n";

echo str_repeat('=', 48) . "\n";
echo "All tests passed\n";
