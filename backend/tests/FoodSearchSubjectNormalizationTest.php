<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/OfficialSiteBrandResolver.php';
require_once __DIR__ . '/../src/WebSearchBudget.php';
require_once __DIR__ . '/../src/FoodWebSearchPlan.php';
require_once __DIR__ . '/../src/FoodWebSearchPlanInputGuard.php';
require_once __DIR__ . '/../src/FoodVariantAnalyzer.php';
require_once __DIR__ . '/../src/NutritionSearchQueryBuilder.php';
require_once __DIR__ . '/../src/FoodSearchSubject.php';
require_once __DIR__ . '/../src/FoodSearchSubjectNormalizer.php';
require_once __DIR__ . '/../src/ProductMatchResult.php';
require_once __DIR__ . '/../src/ProductMatchEvaluator.php';
require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/NutritionPageVariantExtractor.php';
require_once __DIR__ . '/../src/WebSearchUrlRanker.php';
require_once __DIR__ . '/../src/BraveSearchService.php';
require_once __DIR__ . '/../src/WebSearchResultCache.php';
require_once __DIR__ . '/../src/WebSearchDiagnostics.php';
require_once __DIR__ . '/../src/AiWebSearchProvider.php';
require_once __DIR__ . '/../src/HtmlFetchPlanBuilder.php';
require_once __DIR__ . '/../src/OfficialCatalogCandidate.php';
require_once __DIR__ . '/../src/OfficialCatalogProvider.php';
require_once __DIR__ . '/../src/OfficialCatalogCache.php';
require_once __DIR__ . '/../src/NoshCatalogProvider.php';
require_once __DIR__ . '/../src/GenericSitemapCatalogProvider.php';
require_once __DIR__ . '/../src/GenericListingPageCatalogProvider.php';
require_once __DIR__ . '/../src/OfficialCatalogDiscovery.php';
require_once __DIR__ . '/../src/FoodWebSearchPlanService.php';
require_once __DIR__ . '/../src/AiWebSearchService.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'FAIL: %s (expected %s, got %s)',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertFalse(bool $condition, string $message): void
{
    assertTrue(!$condition, $message);
}

echo "FoodSearchSubject normalization regression tests\n";
echo str_repeat('=', 48) . "\n";

$input = 'ナッシュ たらの辛旨チリソース';
$spicyTitle = 'たらの辛旨チリソース｜【nosh-ナッシュ】';
$sweetTitle = 'たらの甘酢チリソース｜【nosh-ナッシュ】';
$spicyUrl = 'https://nosh.jp/menu/detail/1057';
$sweetUrl = 'https://nosh.jp/menu/detail/1025';
$spicyKcal = 327;

$spicyHtml = <<<HTML
<html><head><title>{$spicyTitle}</title></head><body>
<h1>たらの辛旨チリソース</h1>
<div><h3 class="pg-menu-detail-table__title">カロリー</h3>
<p class="pg-menu-detail-table__text">{$spicyKcal}kcal</p></div>
</body></html>
HTML;

$sweetHtml = <<<HTML
<html><head><title>{$sweetTitle}</title></head><body>
<h1>たらの甘酢チリソース</h1>
<div><h3 class="pg-menu-detail-table__title">カロリー</h3>
<p class="pg-menu-detail-table__text">287kcal</p></div>
</body></html>
HTML;

// --- subject separation ---
$normalizer = new FoodSearchSubjectNormalizer();
$subject = $normalizer->normalize($input);
assertSame($input, $subject->rawInput, 'testSearchSubjectSeparatesNoshBrandFromProductName raw');
assertSame('ナッシュ', $subject->brandName, 'testSearchSubjectSeparatesNoshBrandFromProductName brand');
assertSame('たらの辛旨チリソース', $subject->productName, 'testSearchSubjectSeparatesNoshBrandFromProductName product');
echo "OK testSearchSubjectSeparatesNoshBrandFromProductName\n";

// --- fallback plan ---
$analyzer = new FoodVariantAnalyzer();
$plan = FoodWebSearchPlan::fallbackFromSubject($subject, $analyzer);
assertSame('ナッシュ', $plan->brandName, 'testFallbackPlanPreservesResolvedBrand');
assertSame('たらの辛旨チリソース', $plan->normalizedProductName, 'testFallbackPlanUsesBrandFreeProductName');
assertSame('single_product', $plan->searchMode, 'fallback searchMode');
assertFalse($plan->likelyHasVariants, 'fallback likelyHasVariants false');
echo "OK testFallbackPlanPreservesResolvedBrand\n";
echo "OK testFallbackPlanUsesBrandFreeProductName\n";

// --- queries ---
$builder = new NutritionSearchQueryBuilder();
$queries = $builder->buildSearchQueries($input, $plan);
$joined = implode("\n", $queries);
assertTrue(str_contains($joined, 'site:nosh.jp'), 'has site query');
foreach ($queries as $query) {
    if (str_starts_with($query, 'site:nosh.jp')) {
        assertFalse(
            (bool) preg_match('/site:nosh\.jp(?:\/[^\s]*)?\s+ナッシュ\b/u', $query),
            'testNoshOfficialQueryDoesNotRequireBrandTokenAfterSiteFilter: ' . $query,
        );
    }
}
assertTrue(str_contains($joined, $input) || str_contains($joined, 'ナッシュ たらの辛旨チリソース カロリー'), 'raw input query present');
assertFalse((bool) preg_match('/サイズ カロリー/u', $joined), 'testSpecificSingleProductDoesNotGenerateSizeQuery');
echo "OK testNoshOfficialQueryDoesNotRequireBrandTokenAfterSiteFilter\n";
echo "OK testSpecificSingleProductDoesNotGenerateSizeQuery\n";

// --- variant risk ---
$analysis = $analyzer->analyzeInput($subject->productName);
assertSame('low', $analysis['variant_risk'], 'testSpecificSingleProductDefaultsToLowVariantRisk');
echo "OK testSpecificSingleProductDefaultsToLowVariantRisk\n";

// --- ranker ---
$ranker = new WebSearchUrlRanker();
$ranked = $ranker->rank([
    ['title' => $sweetTitle, 'url' => $sweetUrl, 'description' => '甘酢'],
    ['title' => $spicyTitle, 'url' => $spicyUrl, 'description' => '辛旨'],
], $plan->normalizedProductName, $plan->brandName, 'single_product');
assertSame($spicyUrl, $ranked[0]['url'] ?? null, 'testRankerPlacesExactSpicyChiliProductAboveSweetAndSourProduct');
assertTrue(($ranked[0]['score'] ?? 0) > ($ranked[1]['score'] ?? 0), 'spicy score higher');
assertTrue(($ranked[1]['title_match']['has_distinct_cores'] ?? false) === true, 'testRankerPenalizesDistinctCoreConflict');
echo "OK testRankerPlacesExactSpicyChiliProductAboveSweetAndSourProduct\n";
echo "OK testRankerPenalizesDistinctCoreConflict\n";

// brand in raw input must not penalize exact official page
$rankedWithBrandRaw = $ranker->rank([
    ['title' => $spicyTitle, 'url' => $spicyUrl, 'description' => '辛旨'],
], 'たらの辛旨チリソース', 'ナッシュ', 'single_product');
assertTrue(($rankedWithBrandRaw[0]['title_match']['has_exact_phrase'] ?? false) === true, 'testOfficialExactProductPageIsNotPenalizedByRawInputBrand');
assertTrue(($rankedWithBrandRaw[0]['score'] ?? 0) >= 300, 'exact official page score stays high');
echo "OK testOfficialExactProductPageIsNotPenalizedByRawInputBrand\n";

// --- identity ---
$extractor = new NutritionPageExtractor();
assertSame(
    'high',
    $extractor->assessProductIdentity('たらの辛旨チリソース', 'たらの辛旨チリソース', 'ナッシュ'),
    'testIdentityIsHighWhenOnlyBrandPrefixDiffers',
);
echo "OK testIdentityIsHighWhenOnlyBrandPrefixDiffers\n";

// --- fixture accept ---
$variantExtractor = new NutritionPageVariantExtractor();
$spicyItems = $variantExtractor->extractFromHtml(
    $spicyHtml,
    'たらの辛旨チリソース',
    'ナッシュ',
    'none',
    [],
    $spicyUrl,
);
assertTrue(count($spicyItems) === 1, 'testNoshSpicyChiliFixtureIsAccepted count');
assertSame($spicyKcal, $spicyItems[0]['kcal'] ?? null, 'testNoshSpicyChiliFixtureIsAccepted kcal');
assertSame(ProductMatchResult::DECISION_ACCEPTED, $spicyItems[0]['matchDecision'] ?? null, 'testNoshSpicyChiliFixtureIsAccepted decision');
echo "OK testNoshSpicyChiliFixtureIsAccepted\n";

// --- e2e with fakes ---
final class NoshPlanService extends FoodWebSearchPlanService
{
    public function __construct(private readonly FoodWebSearchPlan $plan)
    {
        parent::__construct();
    }

    public function createPlan(string $userInput, string $apiKey, WebSearchBudget $budget): FoodWebSearchPlan|string|null
    {
        $budget->recordHaikuCall();

        return $this->plan;
    }
}

final class NoshBrave extends BraveSearchService
{
    public function search(string $query, int $count = 10): array
    {
        return [
            'ok' => true,
            'http_code' => 200,
            'error' => null,
            'urls' => [$GLOBALS['sweetUrl'], $GLOBALS['spicyUrl']],
            'results' => [
                ['title' => $GLOBALS['sweetTitle'], 'url' => $GLOBALS['sweetUrl'], 'description' => '甘酢'],
                ['title' => $GLOBALS['spicyTitle'], 'url' => $GLOBALS['spicyUrl'], 'description' => '辛旨'],
            ],
        ];
    }
}

final class NoshPages extends NutritionPageExtractor
{
    public function fetchPageHtml(string $url): ?string
    {
        return match ($url) {
            $GLOBALS['spicyUrl'] => $GLOBALS['spicyHtml'],
            $GLOBALS['sweetUrl'] => $GLOBALS['sweetHtml'],
            default => null,
        };
    }
}

$GLOBALS['sweetUrl'] = $sweetUrl;
$GLOBALS['spicyUrl'] = $spicyUrl;
$GLOBALS['sweetTitle'] = $sweetTitle;
$GLOBALS['spicyTitle'] = $spicyTitle;
$GLOBALS['spicyHtml'] = $spicyHtml;
$GLOBALS['sweetHtml'] = $sweetHtml;

$cacheDir = sys_get_temp_dir() . '/diet_ai_nosh_subject_' . getmypid();
@mkdir($cacheDir, 0775, true);
putenv('AI_WEB_SEARCH_CACHE_ENABLED=false');

$fetched = [];
$pages = new class($fetched) extends NutritionPageExtractor {
    /** @param list<string> $fetched */
    public function __construct(private array &$fetched)
    {
    }

    public function fetchPageHtml(string $url): ?string
    {
        $this->fetched[] = $url;

        return match ($url) {
            $GLOBALS['spicyUrl'] => $GLOBALS['spicyHtml'],
            $GLOBALS['sweetUrl'] => $GLOBALS['sweetHtml'],
            default => null,
        };
    }
};

$service = new AiWebSearchService(
    planService: new NoshPlanService($plan),
    queryBuilder: new NutritionSearchQueryBuilder(),
    braveSearch: new NoshBrave(),
    urlRanker: new WebSearchUrlRanker(),
    pageExtractor: $pages,
    variantExtractor: new NutritionPageVariantExtractor(),
    variantAnalyzer: new FoodVariantAnalyzer(),
    cache: new WebSearchResultCache($cacheDir, 3600),
    officialSiteBrandResolver: new OfficialSiteBrandResolver(),
    claudeWebSearchFallback: static function (): array {
        throw new RuntimeException('Claude should not be required for this fixture');
    },
    searchProvider: AiWebSearchProvider::AUTO,
);

$result = $service->search($input, 'test-key');
assertSame('confirmed', $result['web_search_status'] ?? null, 'testNoshSpicyChiliEndToEndReturnsConfirmedResult status');
assertSame(false, $result['needs_confirmation'] ?? true, 'needs_confirmation false');
assertSame($spicyKcal, $result['kcal'] ?? null, 'testNoshSpicyChiliEndToEndReturnsConfirmedResult kcal');
assertSame('high', $result['identity_confidence'] ?? null, 'identity high');
assertTrue(in_array($sweetUrl, $fetched, true) || in_array($spicyUrl, $fetched, true), 'fetched at least one');
assertTrue(in_array($spicyUrl, $fetched, true), 'spicy page evaluated');
echo "OK testNoshSpicyChiliEndToEndReturnsConfirmedResult\n";

// confirmation/rejected must not stop fetching when spicy is later
$fetchOrder = [];
$orderPages = new class($fetchOrder) extends NutritionPageExtractor {
    /** @param list<string> $fetchOrder */
    public function __construct(private array &$fetchOrder)
    {
    }

    public function fetchPageHtml(string $url): ?string
    {
        $this->fetchOrder[] = $url;

        return match ($url) {
            $GLOBALS['spicyUrl'] => $GLOBALS['spicyHtml'],
            $GLOBALS['sweetUrl'] => $GLOBALS['sweetHtml'],
            default => null,
        };
    }
};
$service2 = new AiWebSearchService(
    planService: new NoshPlanService($plan),
    queryBuilder: new NutritionSearchQueryBuilder(),
    braveSearch: new NoshBrave(),
    urlRanker: new WebSearchUrlRanker(),
    pageExtractor: $orderPages,
    variantExtractor: new NutritionPageVariantExtractor(),
    cache: new WebSearchResultCache($cacheDir . '_2', 3600),
    claudeWebSearchFallback: null,
    searchProvider: AiWebSearchProvider::BRAVE_ONLY,
);
$service2->search($input, 'test-key');
assertTrue(
    in_array($spicyUrl, $fetchOrder, true),
    'testRejectedOrConfirmationCandidateDoesNotStopOfficialCandidateFetching',
);
echo "OK testRejectedOrConfirmationCandidateDoesNotStopOfficialCandidateFetching\n";

// fixture URL mapping sanity
assertTrue(str_contains($spicyHtml, '辛旨'), '1057 fixture is spicy');
assertTrue(str_contains($sweetHtml, '甘酢'), '1025 fixture is sweet-sour');
echo "OK fixture URL mapping 1057=辛旨 / 1025=甘酢\n";

foreach (glob($cacheDir . '/*.json') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);
foreach (glob($cacheDir . '_2/*.json') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir . '_2');

echo str_repeat('=', 48) . "\n";
echo "All FoodSearchSubject regression tests passed\n";
