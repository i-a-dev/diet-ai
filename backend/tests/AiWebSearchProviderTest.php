<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/AiWebSearchProvider.php';
require_once __DIR__ . '/../src/WebSearchResultCache.php';
require_once __DIR__ . '/../src/WebSearchBudget.php';
require_once __DIR__ . '/../src/FoodWebSearchPlan.php';
require_once __DIR__ . '/../src/FoodWebSearchPlanInputGuard.php';
require_once __DIR__ . '/../src/WebSearchDiagnostics.php';
require_once __DIR__ . '/../src/FoodVariantAnalyzer.php';
require_once __DIR__ . '/../src/NutritionSearchQueryBuilder.php';
require_once __DIR__ . '/../src/BraveSearchService.php';
require_once __DIR__ . '/../src/WebSearchUrlRanker.php';
require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/OfficialSiteBrandResolver.php';
require_once __DIR__ . '/../src/ProductMatchResult.php';
require_once __DIR__ . '/../src/ProductMatchEvaluator.php';
require_once __DIR__ . '/../src/NutritionPageVariantExtractor.php';
require_once __DIR__ . '/../src/FoodWebSearchPlanService.php';
require_once __DIR__ . '/../src/AiWebSearchService.php';
require_once __DIR__ . '/../src/CalorieEstimateService.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

echo "AiWebSearchProvider tests\n";
echo str_repeat('=', 48) . "\n";

assertTrue(AiWebSearchProvider::resolve('auto') === 'auto', 'resolve auto');
assertTrue(AiWebSearchProvider::resolve('BRAVE_ONLY') === 'brave_only', 'resolve brave_only case');
assertTrue(AiWebSearchProvider::resolve('claude_only') === 'claude_only', 'resolve claude_only');
assertTrue(AiWebSearchProvider::resolve('nope') === 'auto', 'invalid falls back to auto');
assertTrue(AiWebSearchProvider::allowsClaudeFallback('auto'), 'auto allows claude');
assertTrue(!AiWebSearchProvider::allowsClaudeFallback('brave_only'), 'brave_only blocks claude');
assertTrue(!AiWebSearchProvider::allowsClaudeFallback('claude_only'), 'claude_only not via brave pipeline fallback');
assertTrue(AiWebSearchProvider::usesBravePipeline('brave_only'), 'brave_only uses brave');
assertTrue(!AiWebSearchProvider::usesBravePipeline('claude_only'), 'claude_only skips brave');
echo "OK provider resolve / flags\n";

$cacheDir = sys_get_temp_dir() . '/diet_ai_web_search_cache_provider_' . getmypid();
@mkdir($cacheDir, 0775, true);
$cache = new WebSearchResultCache($cacheDir, 3600);
$payload = ['response' => ['kcal' => 100, 'source' => 'brave_html']];
$cache->put('テスト商品', $payload, provider: 'auto');
assertTrue($cache->get('テスト商品', provider: 'auto') !== null, 'auto cache hit');
assertTrue($cache->get('テスト商品', provider: 'brave_only') === null, 'brave_only does not see auto cache');
$cache->put('テスト商品', ['response' => ['kcal' => 200, 'source' => 'brave_html']], provider: 'brave_only');
$braveHit = $cache->get('テスト商品', provider: 'brave_only');
assertTrue(($braveHit['response']['kcal'] ?? 0) === 200, 'brave_only cache isolated');
echo "OK cache key includes provider (v5)\n";

$calorie = new CalorieEstimateService();
$ref = new ReflectionClass($calorie);
$method = $ref->getMethod('resolveAiWebSearchService');
$method->setAccessible(true);

$autoSvc = $method->invoke($calorie, AiWebSearchProvider::AUTO);
$autoFallback = (new ReflectionClass($autoSvc))->getProperty('claudeWebSearchFallback');
$autoFallback->setAccessible(true);
assertTrue($autoFallback->getValue($autoSvc) !== null, 'auto has Claude fallback');
$autoProvider = (new ReflectionClass($autoSvc))->getProperty('searchProvider');
$autoProvider->setAccessible(true);
assertTrue($autoProvider->getValue($autoSvc) === 'auto', 'auto searchProvider');

$braveSvc = $method->invoke($calorie, AiWebSearchProvider::BRAVE_ONLY);
$braveFallback = (new ReflectionClass($braveSvc))->getProperty('claudeWebSearchFallback');
$braveFallback->setAccessible(true);
assertTrue($braveFallback->getValue($braveSvc) === null, 'brave_only has null Claude fallback');
$braveProvider = (new ReflectionClass($braveSvc))->getProperty('searchProvider');
$braveProvider->setAccessible(true);
assertTrue($braveProvider->getValue($braveSvc) === 'brave_only', 'brave_only searchProvider');
echo "OK CalorieEstimateService wires provider into AiWebSearchService\n";

$flow = $ref->getMethod('estimateWithClaudeOnlyWebSearch');
assertTrue($flow->isPrivate(), 'claude_only helper exists');
echo "OK claude_only direct helper is wired\n";

foreach (glob($cacheDir . '/*.json') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);

echo str_repeat('=', 48) . "\n";
echo "All tests passed\n";
