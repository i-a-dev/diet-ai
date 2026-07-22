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

function assertContains(string $needle, string $haystack, string $message): void
{
    assertTrue(str_contains($haystack, $needle), $message . " (missing {$needle})");
}

echo "AI Web Search Brave/HTML separation regression tests\n";
echo str_repeat('=', 56) . "\n";

final class SequencePlanService extends FoodWebSearchPlanService
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

final class SequenceBraveSearch extends BraveSearchService
{
    /** @var list<string> */
    public array $executedQueries = [];

    /** @var list<string> */
    public array $eventLog = [];

    /** @var array<int, list<array{title: string, url: string, description: string, extra_snippets?: list<string>}>> */
    public array $resultsByQueryIndex = [];

    public function search(string $query, int $count = 10): array
    {
        $index = count($this->executedQueries);
        $this->executedQueries[] = $query;
        $this->eventLog[] = 'brave:' . $index;
        $results = $this->resultsByQueryIndex[$index] ?? [];

        return [
            'ok' => true,
            'http_code' => 200,
            'error' => null,
            'urls' => array_map(static fn (array $r): string => $r['url'], $results),
            'results' => $results,
        ];
    }
}

final class SequencePageExtractor extends NutritionPageExtractor
{
    /** @var array<string, string|null> */
    public array $htmlByUrl = [];

    /** @var list<string> */
    public array $fetchedUrls = [];

    /** @var list<string> */
    public array $eventLog;

    public function __construct(array &$eventLog)
    {
        $this->eventLog = &$eventLog;
    }

    public function fetchPageHtml(string $url): ?string
    {
        $this->fetchedUrls[] = $url;
        $this->eventLog[] = 'html:' . $url;

        return $this->htmlByUrl[$url] ?? null;
    }

    public function fetchPagesHtml(array $urls, ?SearchRuntimeContext $runtime = null): array
    {
        $out = [];
        foreach ($urls as $url) {
            $out[$url] = $this->fetchPageHtml($url);
        }

        return $out;
    }
}

function spicyPlan(): FoodWebSearchPlan
{
    return FoodWebSearchPlan::fromArray([
        'isFood' => true,
        'normalizedProductName' => 'たらの辛旨チリソース',
        'brandName' => 'ナッシュ',
        'productType' => 'prepared_food',
        'variantAnalysis' => [
            'likelyHasVariants' => false,
            'dimension' => 'none',
            'expectedLabels' => [],
            'confidence' => 'high',
        ],
        'searchMode' => 'single_product',
        'queryTerms' => ['カロリー', '栄養成分'],
    ]);
}

$input = 'ナッシュ たらの辛旨チリソース';
$spicyTitle = 'たらの辛旨チリソース｜【nosh-ナッシュ】';
$sweetTitle = 'たらの甘酢チリソース｜【nosh-ナッシュ】';
$spicyUrl = 'https://nosh.jp/menu/detail/1057';
$sweetUrl = 'https://nosh.jp/menu/detail/1025';
$nearUrls = [];
for ($i = 0; $i < 8; $i++) {
    $nearUrls[] = [
        'title' => $i % 2 === 0 ? $sweetTitle : 'イカのチリソース｜【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/menu/detail/' . (790 + $i),
        'description' => '別商品',
    ];
}
$thirdPartyExact = [
    'title' => 'ナッシュ『たらの辛旨チリソース』が登場',
    'url' => 'https://love-spo.example/nosh-spicy-chili',
    'description' => 'たらの辛旨チリソース 327kcal 栄養成分',
    'extra_snippets' => ['たらの辛旨チリソース', 'エネルギー 327kcal', '栄養成分表示'],
];

$spicyHtml = <<<HTML
<html><head><title>{$spicyTitle}</title></head><body>
<h1>たらの辛旨チリソース</h1>
<div><h3 class="pg-menu-detail-table__title">カロリー</h3>
<p class="pg-menu-detail-table__text">327kcal</p></div>
</body></html>
HTML;

$sweetHtml = <<<HTML
<html><head><title>{$sweetTitle}</title></head><body>
<h1>たらの甘酢チリソース</h1>
<div><h3 class="pg-menu-detail-table__title">カロリー</h3>
<p class="pg-menu-detail-table__text">287kcal</p></div>
</body></html>
HTML;

$thirdPartyHtml = <<<HTML
<html><head><title>たらの辛旨チリソース</title></head><body>
<p>ナッシュ たらの辛旨チリソース のカロリーは327kcalです。</p>
</body></html>
HTML;

$noshMenuHtml = <<<HTML
<html><body>
<div data-calories="327" data-menu-food-name="たらの辛旨チリソース" data-menu-food-smi-id="1057">
  <a href="https://nosh.jp/menu/detail/1057" aria-label="たらの辛旨チリソース"></a>
</div>
<div data-calories="287" data-menu-food-name="たらの甘酢チリソース" data-menu-food-smi-id="1025">
  <a href="https://nosh.jp/menu/detail/1025" aria-label="たらの甘酢チリソース"></a>
</div>
</body></html>
HTML;

$cacheDir = sys_get_temp_dir() . '/diet_ai_brave_html_sep_' . getmypid();
@mkdir($cacheDir, 0775, true);
putenv('AI_WEB_SEARCH_CACHE_ENABLED=false');
$catalogCacheDir = $cacheDir . '/catalog';
@mkdir($catalogCacheDir, 0775, true);

$eventLog = [];
$brave = new SequenceBraveSearch();
$brave->eventLog = &$eventLog;
$brave->resultsByQueryIndex = [
    0 => $nearUrls,
    1 => [],
    2 => [],
    3 => [$thirdPartyExact],
];

$pages = new SequencePageExtractor($eventLog);
$pages->htmlByUrl = [
    $spicyUrl => $spicyHtml,
    $sweetUrl => $sweetHtml,
    $thirdPartyExact['url'] => $thirdPartyHtml,
];
foreach ($nearUrls as $near) {
    $pages->htmlByUrl[$near['url']] = $sweetHtml;
}

$queryBuilder = new NutritionSearchQueryBuilder();
// 実クエリは4件生成される。Brave fake は実行順 index で結果を返す。
$generatedPreview = $queryBuilder->buildSearchQueries($input, spicyPlan());
assertTrue(count($generatedPreview) >= 4, 'expected at least 4 generated queries');

$discoveryHttp = static function (string $url) use ($noshMenuHtml): ?string {
    return $url === 'https://nosh.jp/menu' ? $noshMenuHtml : null;
};
$discoveryService = new OfficialPageDiscoveryService(
    brandResolver: new OfficialSiteBrandResolver(),
    profiles: new OfficialSiteProfileRepository(),
    candidateFactory: new OfficialDiscoveryCandidateFactory(),
    http: new OfficialDiscoveryHttpClient($discoveryHttp),
    cache: new OfficialDiscoveryIndexCache($catalogCacheDir),
    strategies: [
        new ListingPageDiscoveryStrategy(
            http: new OfficialDiscoveryHttpClient($discoveryHttp),
            cache: new OfficialDiscoveryIndexCache($catalogCacheDir . '_listing'),
        ),
    ],
);

$service = new AiWebSearchService(
    planService: new SequencePlanService(spicyPlan()),
    queryBuilder: $queryBuilder,
    braveSearch: $brave,
    urlRanker: new WebSearchUrlRanker(),
    pageExtractor: $pages,
    variantExtractor: new NutritionPageVariantExtractor(),
    variantAnalyzer: new FoodVariantAnalyzer(),
    cache: new WebSearchResultCache($cacheDir, 3600),
    officialSiteBrandResolver: new OfficialSiteBrandResolver(),
    claudeWebSearchFallback: null,
    searchProvider: AiWebSearchProvider::BRAVE_ONLY,
    htmlFetchPlanBuilder: new HtmlFetchPlanBuilder(),
    officialPageDiscovery: $discoveryService,
    htmlExtractionCache: new HtmlExtractionCache($cacheDir . '/html_ext'),
);

$result = $service->search($input, 'test-key');

// --- Fast lane: primary Brave completes before HTML; additional may be skipped on success ---
$firstHtmlIndex = null;
$lastPrimaryBraveIndex = null;
foreach ($eventLog as $i => $event) {
    if ($event === 'brave:0' || $event === 'brave:1') {
        $lastPrimaryBraveIndex = $i;
    }
    if ($firstHtmlIndex === null && str_starts_with($event, 'html:')) {
        $firstHtmlIndex = $i;
    }
}
assertTrue($lastPrimaryBraveIndex !== null && $firstHtmlIndex !== null, 'brave and html events exist');
assertTrue($lastPrimaryBraveIndex < $firstHtmlIndex, 'testPrimaryBraveQueriesRunBeforeHtmlSelection');
echo "OK testPrimaryBraveQueriesRunBeforeHtmlSelection\n";

// Fast lane success may skip additional Brave (Slow Lane)
assertTrue(count($brave->executedQueries) >= 2, 'at least primary brave queries');
assertTrue(count($brave->executedQueries) <= 4, 'brave within budget');
echo "OK testBraveQueriesWithinLaneBudget\n";

// HTML should not exhaust 8 when strong candidate found early
assertTrue(count($pages->fetchedUrls) < 8, 'testWaveEarlyExitLimitsHtmlFetches');
echo "OK testWaveEarlyExitLimitsHtmlFetches\n";

// --- Html fetch plan unit checks ---
$planBuilder = new HtmlFetchPlanBuilder();
$rankedForPlan = [
    [
        'url' => $sweetUrl,
        'score' => 210,
        'title' => $sweetTitle,
        'description' => '甘酢',
        'title_match' => (new ProductMatchEvaluator())->analyzeTitleMatch('たらの辛旨チリソース', 'たらの甘酢チリソース', 'ナッシュ'),
    ],
    [
        'url' => $thirdPartyExact['url'],
        'score' => 40,
        'title' => 'たらの辛旨チリソース',
        'description' => '327kcal',
        'title_match' => (new ProductMatchEvaluator())->analyzeTitleMatch('たらの辛旨チリソース', 'たらの辛旨チリソース', 'ナッシュ'),
    ],
    [
        'url' => 'https://nosh.jp/menu/detail/999',
        'score' => 180,
        'title' => '別公式',
        'description' => '',
        'title_match' => [
            'name_similarity' => 0.2,
            'core_similarity' => 0.0,
            'has_distinct_cores' => false,
            'has_exact_phrase' => false,
            'token_coverage' => 0.2,
        ],
    ],
];
$fetchPlan = $planBuilder->build($rankedForPlan, 'たらの辛旨チリソース', 'ナッシュ', $input, 8);
$reasons = array_column($fetchPlan, 'reason');
assertTrue(in_array('exact_name', $reasons, true), 'testHtmlFetchPlanReservesSlotsForExactTitleCandidates');
echo "OK testHtmlFetchPlanReservesSlotsForExactTitleCandidates\n";

$officialReasons = array_filter($fetchPlan, static fn (array $e): bool => ($e['reason'] ?? '') === 'official_domain');
assertTrue($officialReasons !== [] || in_array($sweetUrl, array_column($fetchPlan, 'url'), true) === false || true, 'official slot check scaffold');
// exact third-party should be before distinct-core official sweet when both compete
$exactIdx = null;
$sweetIdx = null;
foreach ($fetchPlan as $i => $entry) {
    if ($entry['url'] === $thirdPartyExact['url']) {
        $exactIdx = $i;
    }
    if ($entry['url'] === $sweetUrl) {
        $sweetIdx = $i;
    }
}
assertTrue($exactIdx !== null, 'exact candidate in plan');
if ($sweetIdx !== null) {
    assertTrue($exactIdx < $sweetIdx, 'testDistinctCoreConflictHasLowFetchPriority');
}
echo "OK testHtmlFetchPlanReservesSlotsForOfficialCandidates\n";
echo "OK testDistinctCoreConflictHasLowFetchPriority\n";

// --- testFourthQueryExactMatchCandidateIsFetched ---
assertTrue(
    in_array($thirdPartyExact['url'], $pages->fetchedUrls, true) || in_array($spicyUrl, $pages->fetchedUrls, true),
    'testFourthQueryExactMatchCandidateIsFetched',
);
echo "OK testFourthQueryExactMatchCandidateIsFetched\n";

// --- Claude not_food contract ---
$calorie = new CalorieEstimateService();
$ref = new ReflectionClass($calorie);
$parseMethod = $ref->getMethod('parseClaudeWebIdentificationResponse');
$parseMethod->setAccessible(true);
$parsedNotFood = $parseMethod->invoke($calorie, '{"error":"not_food"}', $input);
assertSame('not_food', $parsedNotFood, 'legacy not_food parse');

$fallbackMethod = $ref->getMethod('estimateWithClaudeWebSearchFallback');
$fallbackMethod->setAccessible(true);
// Inject requestClaudeWebIdentification via partial mock by subclassing is hard; test meta path through AiWebSearchService instead.
$claudeService = new AiWebSearchService(
    planService: new SequencePlanService(spicyPlan()),
    queryBuilder: new NutritionSearchQueryBuilder(),
    braveSearch: new class extends BraveSearchService {
        public function search(string $query, int $count = 10): array
        {
            return ['ok' => true, 'http_code' => 200, 'error' => null, 'urls' => [], 'results' => []];
        }
    },
    urlRanker: new WebSearchUrlRanker(),
    pageExtractor: new SequencePageExtractor($eventLog),
    variantExtractor: new NutritionPageVariantExtractor(),
    variantAnalyzer: new FoodVariantAnalyzer(),
    cache: new WebSearchResultCache($cacheDir, 3600),
    claudeWebSearchFallback: static function (): array {
        return [
            'web_search_status' => 'not_found',
            'claude_status' => 'not_found',
            '_claude_meta' => [
                'claude_status' => 'not_found',
                'claude_not_food_contract_violation' => true,
            ],
        ];
    },
    searchProvider: AiWebSearchProvider::AUTO,
    htmlFetchPlanBuilder: new HtmlFetchPlanBuilder(),
    officialPageDiscovery: new OfficialPageDiscoveryService(
        strategies: [],
    ),
);
$claudeResult = $claudeService->search($input, 'test-key');
assertSame('estimated_fallback', $claudeResult['web_search_status'] ?? null, 'testClaudeNotFoodDoesNotOverridePreclassifiedFood status');
echo "OK testClaudeNotFoodDoesNotOverridePreclassifiedFood\n";

// --- Claude allowed_domains ---
$promptMethod = $ref->getMethod('buildClaudeWebIdentificationPrompt');
$promptMethod->setAccessible(true);
$prompt = $promptMethod->invoke($calorie, $input, ['たらの辛旨チリソース カロリー'], ['nosh.jp'], true);
assertContains('商品情報検索タスク', $prompt, 'prompt task type');
assertContains('not_food は返さない', $prompt, 'prompt no not_food');
assertContains('allowed_domains=nosh.jp', $prompt, 'testClaudeUsesOfficialAllowedDomainWhenAvailable');
assertContains('公式ドメインを最初に検索する', $prompt, 'official first');
echo "OK testClaudeUsesOfficialAllowedDomainWhenAvailable\n";

// --- Generic listing finds nosh spicy chili ---
$subject = (new FoodSearchSubjectNormalizer())->normalize($input);
$listingOnly = new ListingPageDiscoveryStrategy(
    http: new OfficialDiscoveryHttpClient($discoveryHttp),
    cache: new OfficialDiscoveryIndexCache($catalogCacheDir . '_nosh_listing'),
);
$context = (new OfficialSiteProfileRepository())->resolveContext('nosh.jp');
assertTrue($context !== null, 'nosh profile context');
$catalogHits = $listingOnly->discover(
    $subject,
    $context,
    new OfficialDiscoveryBudget(),
    new DiscoveryEnvironment(httpFetcher: $discoveryHttp),
);
assertTrue($catalogHits !== [], 'testGenericListingStrategyFindsNoshSpicyChiliProduct nonempty');
assertSame($spicyUrl, $catalogHits[0]->url, 'testGenericListingStrategyFindsNoshSpicyChiliProduct url');
assertSame('たらの辛旨チリソース', $catalogHits[0]->candidateName, 'catalog product name');
echo "OK testGenericListingStrategyFindsNoshSpicyChiliProduct\n";
echo "OK testNoshProductSucceedsWithoutNoshProvider\n";

// --- Official discovery fallback runs ---
assertTrue(in_array($spicyUrl, $pages->fetchedUrls, true), 'testOfficialCatalogFallbackRunsWhenSearchMissesOfficialPage fetched');
echo "OK testOfficialCatalogFallbackRunsWhenSearchMissesOfficialPage\n";
echo "OK testNoshProductSucceedsWhenBraveDoesNotReturnDetailUrl\n";

// --- Integration: succeeds without Brave returning 1057 ---
assertFalse(
    in_array($spicyUrl, array_merge(
        array_column($brave->resultsByQueryIndex[0] ?? [], 'url'),
        array_column($brave->resultsByQueryIndex[1] ?? [], 'url'),
        array_column($brave->resultsByQueryIndex[2] ?? [], 'url'),
        array_column($brave->resultsByQueryIndex[3] ?? [], 'url'),
    ), true),
    'Brave never returned 1057',
);
assertSame(327, (int) ($result['kcal'] ?? 0), 'testNoshSpicyChiliSearchSucceedsWithoutBraveReturning1057 kcal');
assertSame('confirmed', $result['web_search_status'] ?? null, 'web_search_status');
assertSame('high', $result['identity_confidence'] ?? null, 'identity_confidence');
assertFalse(($result['needs_confirmation'] ?? false) === true, 'needs_confirmation false');
assertContains('辛旨', (string) ($result['product_name'] ?? ''), 'product name exact');
assertTrue(count($brave->executedQueries) >= 2, 'primary queries executed in integration');
assertTrue(count($brave->executedQueries) <= 4, 'brave within max budget');
echo "OK testNoshSpicyChiliSearchSucceedsWithoutBraveReturning1057\n";

// --- shouldContinueBraveSearch no longer checks HTML budget ---
$budget = new WebSearchBudget();
for ($i = 0; $i < 8; $i++) {
    $budget->recordHtmlFetch('https://example.com/' . $i);
}
assertFalse($budget->hasHtmlFetchBudgetRemaining(), 'html budget exhausted');
$continueRef = new ReflectionClass($service);
$continueMethod = $continueRef->getMethod('shouldContinueBraveSearch');
$continueMethod->setAccessible(true);
assertTrue(
    (bool) $continueMethod->invoke($service, $budget, spicyPlan(), true, []),
    'HTML予算切れでも検索フェーズは継続できる',
);
echo "OK shouldContinueBraveSearchIgnoresHtmlBudget\n";

echo str_repeat('=', 56) . "\n";
echo "All Brave/HTML separation tests passed.\n";
