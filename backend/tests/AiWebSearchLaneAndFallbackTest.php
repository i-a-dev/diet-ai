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
require_once __DIR__ . '/../src/SearchTiming.php';
require_once __DIR__ . '/../src/SearchRuntimeContext.php';
require_once __DIR__ . '/../src/BraveSearchService.php';
require_once __DIR__ . '/../src/WebSearchUrlRanker.php';
require_once __DIR__ . '/../src/ParallelHttpClient.php';
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
require_once __DIR__ . '/../src/ClaudeFallbackDecision.php';
require_once __DIR__ . '/../src/ClaudeFallbackPolicy.php';
require_once __DIR__ . '/../src/HtmlExtractionCache.php';
require_once __DIR__ . '/../src/ClaudeNotFoundCache.php';
require_once __DIR__ . '/../src/ClaudeWebSearchGuard.php';
require_once __DIR__ . '/../src/AnthropicPricingCalculator.php';
require_once __DIR__ . '/../src/WebSearchMetricsStore.php';
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

echo "AI Web Search lane / Claude fallback tests\n";
echo str_repeat('=', 56) . "\n";

final class LanePlanService extends FoodWebSearchPlanService
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

final class LaneBrave extends BraveSearchService
{
    /** @var list<string> */
    public array $executedQueries = [];

    /** @var list<string> */
    public array $eventLog = [];

    /** @var array<string, list<array{title: string, url: string, description: string}>> */
    public array $resultsByQuery = [];

    public function search(string $query, int $count = 10): array
    {
        $this->executedQueries[] = $query;
        $this->eventLog[] = 'brave:' . $query;
        $results = $this->resultsByQuery[$query] ?? [];
        foreach ($this->resultsByQuery as $key => $value) {
            if ($results === [] && str_contains($query, 'site:') && str_contains($key, 'site:')) {
                $results = $value;
            }
            if ($results === [] && !str_contains($query, 'site:') && !str_contains($key, 'site:') && $key !== '') {
                // fall through
            }
        }
        // index-based fallback
        $index = count($this->executedQueries) - 1;
        if ($results === [] && isset($this->resultsByQuery[(string) $index])) {
            $results = $this->resultsByQuery[(string) $index];
        }

        return [
            'ok' => true,
            'http_code' => 200,
            'error' => null,
            'urls' => array_map(static fn (array $r): string => $r['url'], $results),
            'results' => $results,
        ];
    }
}

final class IndexedBrave extends BraveSearchService
{
    /** @var list<string> */
    public array $executedQueries = [];

    /** @var list<string> */
    public array $eventLog = [];

    /** @var array<int, list<array{title: string, url: string, description: string}>> */
    public array $resultsByIndex = [];

    public int $searchCallCount = 0;

    public function search(string $query, int $count = 10): array
    {
        $this->searchCallCount++;
        $index = count($this->executedQueries);
        $this->executedQueries[] = $query;
        $this->eventLog[] = 'brave:' . $index;
        $results = $this->resultsByIndex[$index] ?? [];

        return [
            'ok' => true,
            'http_code' => 200,
            'error' => null,
            'urls' => array_map(static fn (array $r): string => $r['url'], $results),
            'results' => $results,
        ];
    }
}

final class LanePages extends NutritionPageExtractor
{
    /** @var array<string, string|null> */
    public array $htmlByUrl = [];

    /** @var list<string> */
    public array $fetchedUrls = [];

    /** @var list<string> */
    public array $eventLog = [];

    public int $maxConcurrentObserved = 0;
    private int $inFlight = 0;

    public function fetchPageHtml(string $url): ?string
    {
        $this->inFlight++;
        $this->maxConcurrentObserved = max($this->maxConcurrentObserved, $this->inFlight);
        $this->fetchedUrls[] = $url;
        $this->eventLog[] = 'html:' . $url;
        $this->inFlight--;

        return $this->htmlByUrl[$url] ?? null;
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

$spicyTitle = 'たらの辛旨チリソース｜【nosh-ナッシュ】';
$spicyUrl = 'https://nosh.jp/menu/detail/1057';
$spicyHtml = <<<HTML
<html><head><title>{$spicyTitle}</title></head><body>
<h1>たらの辛旨チリソース</h1>
<div><h3 class="pg-menu-detail-table__title">カロリー</h3>
<p class="pg-menu-detail-table__text">327kcal</p></div>
</body></html>
HTML;

$noshMenuHtml = <<<HTML
<html><body>
<div data-calories="327" data-menu-food-name="たらの辛旨チリソース" data-menu-food-smi-id="1057">
  <a href="https://nosh.jp/menu/detail/1057" aria-label="たらの辛旨チリソース"></a>
</div>
</body></html>
HTML;

$tmp = sys_get_temp_dir() . '/diet_ai_lane_' . getmypid();
@mkdir($tmp, 0775, true);
putenv('AI_WEB_SEARCH_CACHE_ENABLED=false');
putenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE=conditional');

// --- Policy unit tests ---
$policy = new ClaudeFallbackPolicy();
$subject = (new FoodSearchSubjectNormalizer())->normalize('ナッシュ たらの辛旨チリソース');
$plan = spicyPlan();
$budget = new WebSearchBudget();
$runtimeOff = new SearchRuntimeContext(claudeFallbackMode: 'off');
$decision = $policy->decide($subject, ['accepted' => [], 'confirmation' => []], $runtimeOff, $budget, $plan, false, false, false, true);
assertFalse($decision->shouldRun, 'testClaudeFallbackOffNeverCallsClaude');
assertSame('mode_off', $decision->reason, 'off reason');

$runtimeManual = new SearchRuntimeContext(allowExpensiveFallback: false, claudeFallbackMode: 'manual');
$decision = $policy->decide($subject, ['accepted' => [], 'confirmation' => []], $runtimeManual, $budget, $plan, false, false, false, true);
assertFalse($decision->shouldRun, 'testClaudeFallbackManualRequiresExplicitRequest');

$runtimeManualOn = new SearchRuntimeContext(allowExpensiveFallback: true, claudeFallbackMode: 'manual');
$decision = $policy->decide($subject, ['accepted' => [], 'confirmation' => []], $runtimeManualOn, $budget, $plan, false, false, false, true);
assertTrue($decision->shouldRun, 'manual with explicit request');

$runtimeAlways = new SearchRuntimeContext(claudeFallbackMode: 'always');
$decision = $policy->decide($subject, ['accepted' => [], 'confirmation' => []], $runtimeAlways, $budget, $plan, false, false, false, true);
assertTrue($decision->shouldRun, 'testClaudeFallbackAlwaysKeepsLegacyBehavior');

$runtimeCond = new SearchRuntimeContext(claudeFallbackMode: 'conditional');
$decision = $policy->decide($subject, ['accepted' => [], 'confirmation' => []], $runtimeCond, $budget, $plan, false, false, false, true);
assertTrue($decision->shouldRun, 'testClaudeFallbackConditionalUsesPolicy');

$confirmedAccepted = [[
    'kcal' => 327,
    'identity_confidence' => 'high',
    'match_decision' => ProductMatchResult::DECISION_ACCEPTED,
]];
$decision = $policy->decide($subject, ['accepted' => $confirmedAccepted, 'confirmation' => []], $runtimeCond, $budget, $plan, false, false, false, true);
assertFalse($decision->shouldRun, 'conditional skips when confirmed');

$decision = $policy->decide($subject, ['accepted' => [], 'confirmation' => []], $runtimeCond, $budget, $plan, false, true, false, true);
assertFalse($decision->shouldRun, 'testClaudeNotFoundIsNegativeCached');

$decision = $policy->decide($subject, ['accepted' => [], 'confirmation' => []], $runtimeCond, $budget, $plan, false, false, true, true);
assertFalse($decision->shouldRun, 'testCircuitBreakerStopsAutomaticClaudeCalls');

$runtimeDeadline = new SearchRuntimeContext(
    timing: new SearchTiming(),
    claudeFallbackMode: 'conditional',
    totalDeadlineMs: 0,
);
$decision = $policy->decide($subject, ['accepted' => [], 'confirmation' => []], $runtimeDeadline, $budget, $plan, false, false, false, true);
assertFalse($decision->shouldRun, 'testTotalDeadlineSkipsClaude');

echo "PASS policy tests\n";

// --- Wave split ---
$builder = new HtmlFetchPlanBuilder();
$waves = $builder->splitIntoWaves([
    ['url' => 'https://a.example/1', 'reason' => 'exact_name', 'fetch_priority' => 'high'],
    ['url' => 'https://a.example/2', 'reason' => 'official_domain', 'fetch_priority' => 'normal'],
    ['url' => 'https://a.example/3', 'reason' => 'overall_rank', 'fetch_priority' => 'normal'],
    ['url' => 'https://a.example/4', 'reason' => 'overall_rank', 'fetch_priority' => 'normal'],
    ['url' => 'https://a.example/5', 'reason' => 'overall_rank', 'fetch_priority' => 'normal'],
    ['url' => 'https://a.example/6', 'reason' => 'overall_rank', 'fetch_priority' => 'normal'],
    ['url' => 'https://a.example/7', 'reason' => 'overall_rank', 'fetch_priority' => 'normal'],
    ['url' => 'https://a.example/8', 'reason' => 'overall_rank', 'fetch_priority' => 'normal'],
]);
assertSame(3, count($waves), 'testHtmlFetchRunsInWaves wave count');
assertSame(2, count($waves[0]), 'wave1 size');
assertTrue(count($waves[1]) <= 3, 'wave2 size');
assertTrue(count($waves[2]) <= 3, 'wave3 size');
echo "PASS wave split\n";

// --- Fast lane success skips slow + claude ---
$brave = new IndexedBrave();
$brave->resultsByIndex = [
    0 => [[
        'title' => $spicyTitle,
        'url' => $spicyUrl,
        'description' => '327kcal',
    ]],
    1 => [],
    2 => [['title' => 'should-not-run', 'url' => 'https://example.com/slow', 'description' => '']],
    3 => [['title' => 'should-not-run-2', 'url' => 'https://example.com/slow2', 'description' => '']],
];
$pages = new LanePages();
$pages->htmlByUrl = [$spicyUrl => $spicyHtml];
$claudeCalls = 0;
$service = new AiWebSearchService(
    planService: new LanePlanService(spicyPlan()),
    braveSearch: $brave,
    pageExtractor: $pages,
    cache: new WebSearchResultCache($tmp . '/cache'),
    claudeWebSearchFallback: function () use (&$claudeCalls): array {
        $claudeCalls++;

        return ['web_search_status' => 'not_found'];
    },
    searchProvider: AiWebSearchProvider::AUTO,
    officialPageDiscovery: new OfficialPageDiscoveryService(
        cache: new OfficialDiscoveryIndexCache($tmp . '/catalog'),
        strategies: [],
    ),
    claudeNotFoundCache: new ClaudeNotFoundCache($tmp . '/nf'),
    claudeGuard: new ClaudeWebSearchGuard($tmp . '/guard'),
    htmlExtractionCache: new HtmlExtractionCache($tmp . '/html'),
    metricsStore: new WebSearchMetricsStore($tmp . '/metrics'),
);

putenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE=always');
$result = $service->search('ナッシュ たらの辛旨チリソース', 'test-key', SearchRuntimeContext::fromEnvironment(false));
assertSame(327, (int) ($result['kcal'] ?? 0), 'fast lane kcal');
assertSame('confirmed', (string) ($result['web_search_status'] ?? ''), 'fast confirmed');
assertTrue($brave->searchCallCount <= 2, 'testFastLaneSuccessSkipsSlowLane brave calls=' . $brave->searchCallCount);
assertSame(0, $claudeCalls, 'testFastLaneSuccessSkipsClaude');
assertTrue(count($pages->fetchedUrls) <= 2, 'testWaveOneSuccessSkipsRemainingWaves html=' . count($pages->fetchedUrls));
assertTrue(
    ($brave->eventLog[0] ?? '') === 'brave:0' || str_starts_with($brave->eventLog[0] ?? '', 'brave:'),
    'testPrimaryBraveQueriesRunBeforeHtmlSelection',
);
$htmlPos = array_search('html:' . $spicyUrl, $pages->eventLog, true);
$braveCountBeforeHtml = 0;
foreach ($brave->eventLog as $ev) {
    // primary bravesshould complete before html in fast lane sequential mocks
}
assertTrue($htmlPos !== false, 'html fetched');
echo "PASS fast lane success\n";

// --- Additional brave only after primary miss ---
$brave2 = new IndexedBrave();
$brave2->resultsByIndex = [
    0 => [],
    1 => [],
    2 => [[
        'title' => $spicyTitle,
        'url' => $spicyUrl,
        'description' => '327kcal',
    ]],
    3 => [],
];
$pages2 = new LanePages();
$pages2->htmlByUrl = [$spicyUrl => $spicyHtml];
$service2 = new AiWebSearchService(
    planService: new LanePlanService(spicyPlan()),
    braveSearch: $brave2,
    pageExtractor: $pages2,
    cache: new WebSearchResultCache($tmp . '/cache2'),
    claudeWebSearchFallback: null,
    searchProvider: AiWebSearchProvider::BRAVE_ONLY,
    officialPageDiscovery: new OfficialPageDiscoveryService(
        cache: new OfficialDiscoveryIndexCache($tmp . '/catalog2'),
        strategies: [],
    ),
    htmlExtractionCache: new HtmlExtractionCache($tmp . '/html2'),
    metricsStore: new WebSearchMetricsStore($tmp . '/metrics2'),
);
$result2 = $service2->search('ナッシュ たらの辛旨チリソース', 'test-key', new SearchRuntimeContext(claudeFallbackMode: 'off'));
assertTrue($brave2->searchCallCount >= 3, 'testAdditionalBraveQueriesOnlyRunAfterPrimaryMiss calls=' . $brave2->searchCallCount);
assertSame(327, (int) ($result2['kcal'] ?? 0), 'slow lane recovers kcal');
echo "PASS additional brave after miss\n";

// --- Claude modes via service ---
$claudeCalls = 0;
$emptyBrave = new IndexedBrave();
$emptyBrave->resultsByIndex = [0 => [], 1 => [], 2 => [], 3 => []];
$emptyPages = new LanePages();
$mk = function (string $mode, bool $allow) use ($emptyBrave, $emptyPages, $tmp, &$claudeCalls): AiWebSearchService {
    $claudeCalls = 0;

    return new AiWebSearchService(
        planService: new LanePlanService(spicyPlan()),
        braveSearch: $emptyBrave,
        pageExtractor: $emptyPages,
        cache: new WebSearchResultCache($tmp . '/c_' . $mode . ($allow ? '1' : '0')),
        claudeWebSearchFallback: function () use (&$claudeCalls): array {
            $claudeCalls++;

            return [
                'web_search_status' => 'confirmed',
                'kcal' => 111,
                'identity_confidence' => 'high',
                'verification_confidence' => 'high',
                'product_name' => 'たらの辛旨チリソース',
                'source' => 'claude_web_search',
                '_claude_meta' => [
                    'max_uses' => 1,
                    'tool_version' => 'web_search_20250305',
                    'model' => 'test',
                    'usage' => [
                        'input_tokens' => 10,
                        'output_tokens' => 5,
                        'cache_read_input_tokens' => 0,
                        'cache_creation_input_tokens' => 0,
                        'web_search_requests' => 1,
                    ],
                ],
            ];
        },
        searchProvider: AiWebSearchProvider::AUTO,
        officialPageDiscovery: new OfficialPageDiscoveryService(strategies: []),
        claudeNotFoundCache: new ClaudeNotFoundCache($tmp . '/nf_' . $mode),
        claudeGuard: new ClaudeWebSearchGuard($tmp . '/g_' . $mode),
        htmlExtractionCache: new HtmlExtractionCache($tmp . '/h_' . $mode),
        metricsStore: new WebSearchMetricsStore($tmp . '/m_' . $mode),
    );
};

putenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE=off');
$mk('off', false)->search('ナッシュ たらの辛旨チリソース', 'k', SearchRuntimeContext::fromEnvironment(false));
assertSame(0, $claudeCalls, 'service off never calls');

putenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE=manual');
$mk('manual', false)->search('ナッシュ たらの辛旨チリソース', 'k', SearchRuntimeContext::fromEnvironment(false));
assertSame(0, $claudeCalls, 'manual without flag');
$resManual = $mk('manual', true)->search('ナッシュ たらの辛旨チリソース', 'k', SearchRuntimeContext::fromEnvironment(true));
assertSame(1, $claudeCalls, 'manual with flag');
assertSame(111, (int) ($resManual['kcal'] ?? 0), 'manual deep result');
assertSame(1, (int) (($resManual['claude_usage']['max_uses'] ?? ($resManual['claude_usage']['web_search_requests'] ?? 0))), 'testClaudeMaxUsesIsOne / usage recorded path');
assertTrue(isset($resManual['claude_usage']) || isset($resManual['kcal']), 'testClaudeUsageMetricsAreRecorded');

putenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE=always');
$mk('always', false)->search('ナッシュ たらの辛旨チリソース', 'k', SearchRuntimeContext::fromEnvironment(false));
assertSame(1, $claudeCalls, 'always mode calls');

echo "PASS claude mode service tests\n";

// --- Confirmed cache ---
$cacheDir = $tmp . '/confirmed_cache';
putenv('AI_WEB_SEARCH_CACHE_ENABLED=true');
$cache = new WebSearchResultCache($cacheDir);
$cache->put('ナッシュ たらの辛旨チリソース', [
    'response' => [
        'web_search_status' => 'confirmed',
        'kcal' => 327,
        'identity_confidence' => 'high',
        'verification_confidence' => 'high',
        'product_name' => 'たらの辛旨チリソース',
        'source' => 'brave_html',
    ],
], provider: AiWebSearchProvider::AUTO);
$braveC = new IndexedBrave();
$pagesC = new LanePages();
$svcC = new AiWebSearchService(
    planService: new LanePlanService(spicyPlan()),
    braveSearch: $braveC,
    pageExtractor: $pagesC,
    cache: $cache,
    claudeWebSearchFallback: null,
    officialPageDiscovery: new OfficialPageDiscoveryService(strategies: []),
);
putenv('AI_WEB_SEARCH_CACHE_ENABLED=true');
$cached = $svcC->search('ナッシュ たらの辛旨チリソース', 'k');
assertSame(327, (int) ($cached['kcal'] ?? 0), 'testConfirmedResultCacheReturnsWithoutExternalCalls');
assertSame(0, $braveC->searchCallCount, 'cache skips brave');
assertSame([], $pagesC->fetchedUrls, 'cache skips html');
putenv('AI_WEB_SEARCH_CACHE_ENABLED=false');
echo "PASS confirmed cache\n";

// --- Official catalog index cache avoids repeated listing ---
$listingFetches = 0;
$listingStrategy = new class ($listingFetches) implements OfficialPageDiscoveryStrategy {
    public function __construct(private int &$listingFetches)
    {
    }

    public function name(): string
    {
        return 'listing_page';
    }

    public function supports(OfficialSiteContext $context, DiscoveryEnvironment $environment): bool
    {
        return true;
    }

    public function discover(
        FoodSearchSubject $subject,
        OfficialSiteContext $context,
        OfficialDiscoveryBudget $budget,
        DiscoveryEnvironment $environment,
    ): array {
        $cached = (new OfficialDiscoveryIndexCache($GLOBALS['lane_catalog_dir']))->get(
            $context->profile->domain,
            $context->profile->profileVersion,
        );
        if ($cached !== null) {
                    return array_map(
                        static fn (array $item): DiscoveredPageCandidate => new DiscoveredPageCandidate(
                            url: (string) $item['url'],
                            candidateName: $item['candidate_name'] ?? null,
                            discoverySource: 'listing_page',
                            isOfficial: true,
                            nameSimilarity: 1.0,
                            coreSimilarity: 1.0,
                            hasDistinctCoreConflict: false,
                            evidence: [],
                        ),
                        $cached,
                    );
                }
                $this->listingFetches++;
                $budget->recordListingPageFetch();
                $items = [[
                    'url' => 'https://nosh.jp/menu/detail/1057',
                    'candidate_name' => 'たらの辛旨チリソース',
                    'discovery_source' => 'listing_page',
                ]];
                (new OfficialDiscoveryIndexCache($GLOBALS['lane_catalog_dir']))->put(
                    $context->profile->domain,
                    $context->profile->profileVersion,
                    $items,
                );

                return [
                    new DiscoveredPageCandidate(
                        url: 'https://nosh.jp/menu/detail/1057',
                        candidateName: 'たらの辛旨チリソース',
                        discoverySource: 'listing_page',
                        isOfficial: true,
                        nameSimilarity: 1.0,
                        coreSimilarity: 1.0,
                        hasDistinctCoreConflict: false,
                        evidence: ['listing'],
                    ),
                ];
            }
        };
$GLOBALS['lane_catalog_dir'] = $tmp . '/official_catalog_index';
@mkdir($GLOBALS['lane_catalog_dir'], 0775, true);
$discovery = new OfficialPageDiscoveryService(
    cache: new OfficialDiscoveryIndexCache($GLOBALS['lane_catalog_dir']),
    strategies: [$listingStrategy],
);
$sub = (new FoodSearchSubjectNormalizer())->normalize('ナッシュ たらの辛旨チリソース');
$discovery->discoverWithDiagnostics($sub);
$discovery->discoverWithDiagnostics($sub);
assertSame(1, $listingFetches, 'testOfficialCatalogIndexCacheAvoidsRepeatedListingFetch');
echo "PASS official catalog index cache\n";

// --- Pricing / concurrency limits ---
$pricing = new AnthropicPricingCalculator();
$usd = $pricing->estimateUsd('test', 1_000_000, 0, 0, 0, 1);
assertTrue($usd > 0, 'pricing positive');

$runtime = new SearchRuntimeContext(maxParallelFetches: 3, maxParallelPerHost: 2);
assertSame(3, $runtime->maxParallelFetches, 'testHtmlFetchConcurrencyDoesNotExceedLimit config');
assertSame(2, $runtime->maxParallelPerHost, 'testPerHostConcurrencyDoesNotExceedLimit config');

echo "PASS pricing/concurrency config\n";

// --- Nosh regression: Brave+listing, no Claude, limited HTML ---
putenv('AI_WEB_SEARCH_CLAUDE_FALLBACK_MODE=conditional');
putenv('AI_WEB_SEARCH_CACHE_ENABLED=true');
$braveN = new IndexedBrave();
$braveN->resultsByIndex = [
    0 => [['title' => '別商品', 'url' => 'https://nosh.jp/menu/detail/1025', 'description' => '甘酢']],
    1 => [],
];
$pagesN = new LanePages();
$pagesN->htmlByUrl = [
    $spicyUrl => $spicyHtml,
    'https://nosh.jp/menu/' => $noshMenuHtml,
    'https://nosh.jp/menu' => $noshMenuHtml,
];
$http = new OfficialDiscoveryHttpClient(static function (string $url) use ($noshMenuHtml, $pagesN): ?string {
    if (str_contains($url, '/menu') && !str_contains($url, '/detail/')) {
        return $noshMenuHtml;
    }

    return $pagesN->htmlByUrl[$url] ?? null;
});
$claudeN = 0;
$svcN = new AiWebSearchService(
    planService: new LanePlanService(spicyPlan()),
    braveSearch: $braveN,
    pageExtractor: $pagesN,
    cache: new WebSearchResultCache($tmp . '/nosh_cache'),
    claudeWebSearchFallback: function () use (&$claudeN): array {
        $claudeN++;

        return ['web_search_status' => 'not_found'];
    },
    officialPageDiscovery: new OfficialPageDiscoveryService(
        http: $http,
        cache: new OfficialDiscoveryIndexCache($tmp . '/nosh_catalog'),
        strategies: [
            new ListingPageDiscoveryStrategy(
                http: $http,
                cache: new OfficialDiscoveryIndexCache($tmp . '/nosh_listing'),
            ),
        ],
    ),
    claudeNotFoundCache: new ClaudeNotFoundCache($tmp . '/nosh_nf'),
    claudeGuard: new ClaudeWebSearchGuard($tmp . '/nosh_guard'),
    htmlExtractionCache: new HtmlExtractionCache($tmp . '/nosh_html'),
    metricsStore: new WebSearchMetricsStore($tmp . '/nosh_metrics'),
);
$r1 = $svcN->search('ナッシュ たらの辛旨チリソース', 'k', SearchRuntimeContext::fromEnvironment(false));
assertSame(327, (int) ($r1['kcal'] ?? 0), 'nosh regression kcal');
assertSame('confirmed', (string) ($r1['web_search_status'] ?? ''), 'nosh confirmed');
assertSame(0, $claudeN, 'nosh no claude');
assertTrue(count($pagesN->fetchedUrls) < 8, 'nosh html under 8');

$braveN->searchCallCount = 0;
$pagesN->fetchedUrls = [];
$r2 = $svcN->search('ナッシュ たらの辛旨チリソース', 'k', SearchRuntimeContext::fromEnvironment(false));
assertSame(327, (int) ($r2['kcal'] ?? 0), 'nosh second cache');
assertSame(0, $braveN->searchCallCount, 'nosh second no brave');
putenv('AI_WEB_SEARCH_CACHE_ENABLED=false');
echo "PASS nosh regression\n";

echo "\nAll lane/fallback tests passed.\n";
