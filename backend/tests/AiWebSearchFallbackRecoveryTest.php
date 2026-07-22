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

echo "AI Web Search fallback recovery tests\n";
echo str_repeat('=', 48) . "\n";

final class FakePlanService extends FoodWebSearchPlanService
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

final class FakeBraveSearch extends BraveSearchService
{
    /** @var list<array{title: string, url: string, description: string}> */
    public array $results = [];

    /** @var list<string> */
    public array $executedQueries = [];

    public function search(string $query, int $count = 10): array
    {
        $this->executedQueries[] = $query;

        return [
            'ok' => true,
            'http_code' => 200,
            'error' => null,
            'urls' => array_map(static fn (array $r): string => $r['url'], $this->results),
            'results' => $this->results,
        ];
    }
}

final class FakePageExtractor extends NutritionPageExtractor
{
    /** @var array<string, string|null> */
    public array $htmlByUrl = [];

    public function fetchPageHtml(string $url): ?string
    {
        return $this->htmlByUrl[$url] ?? null;
    }
}

final class FakeVariantExtractor extends NutritionPageVariantExtractor
{
    /** @var list<array<string, mixed>> */
    public array $items = [];

    public function extractFromHtml(
        string $html,
        string $productName,
        ?string $brandName,
        string $variantDimension,
        array $expectedLabels = [],
        string $sourceUrl = '',
    ): array {
        return $this->items;
    }
}

/**
 * @param list<array<string, mixed>> $accepted
 * @param list<array<string, mixed>> $confirmation
 * @param array<string, mixed>|null $inputAnalysis
 */
function invokeShouldRunClaudeFallback(
    AiWebSearchService $service,
    array $accepted,
    array $confirmation,
    FoodWebSearchPlan $plan,
    WebSearchBudget $budget,
    ?array $inputAnalysis = null,
): bool {
    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('shouldRunClaudeFallback');
    $method->setAccessible(true);

    return (bool) $method->invoke($service, [
        'accepted' => $accepted,
        'confirmation' => $confirmation,
    ], $plan, $budget, $inputAnalysis);
}

function singleProductPlan(string $name, ?string $brand = null, bool $likelyHasVariants = false): FoodWebSearchPlan
{
    return FoodWebSearchPlan::fromArray([
        'isFood' => true,
        'normalizedProductName' => $name,
        'brandName' => $brand,
        'productType' => 'packaged_food',
        'variantAnalysis' => [
            'likelyHasVariants' => $likelyHasVariants,
            'dimension' => $likelyHasVariants ? 'named_size' : 'none',
            'expectedLabels' => $likelyHasVariants ? ['S', 'M', 'L'] : [],
            'confidence' => 'high',
        ],
        'searchMode' => $likelyHasVariants ? 'variant_list_page' : 'single_product',
        'queryTerms' => ['カロリー', '栄養成分'],
    ]);
}

function makeService(
    FoodWebSearchPlan $plan,
    FakeBraveSearch $brave,
    FakePageExtractor $pages,
    FakeVariantExtractor $variants,
    ?callable $claudeFallback,
    string $cacheDir,
    string $provider = AiWebSearchProvider::AUTO,
): AiWebSearchService {
    return new AiWebSearchService(
        planService: new FakePlanService($plan),
        queryBuilder: new NutritionSearchQueryBuilder(),
        braveSearch: $brave,
        urlRanker: new WebSearchUrlRanker(),
        pageExtractor: $pages,
        variantExtractor: $variants,
        variantAnalyzer: new FoodVariantAnalyzer(),
        cache: new WebSearchResultCache($cacheDir, 3600),
        officialSiteBrandResolver: new OfficialSiteBrandResolver(),
        claudeWebSearchFallback: $claudeFallback,
        searchProvider: $provider,
    );
}

function clearCacheDir(string $dir): void
{
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
}

$cacheDir = sys_get_temp_dir() . '/diet_ai_web_search_recovery_' . getmypid();
@mkdir($cacheDir, 0775, true);
putenv('AI_WEB_SEARCH_CACHE_ENABLED=true');
clearCacheDir($cacheDir);

// --- shouldRunClaudeFallback unit checks ---

$plan = singleProductPlan('テスト商品', 'テスト');
$brave = new FakeBraveSearch();
$pages = new FakePageExtractor();
$variants = new FakeVariantExtractor();
$claudeCalls = 0;
$service = makeService($plan, $brave, $pages, $variants, function () use (&$claudeCalls): array {
    $claudeCalls++;

    return [
        'kcal' => 321,
        'confidence' => 'high',
        'product_name' => 'テスト商品',
        'source' => 'claude_web_search',
        'identity_confidence' => 'high',
        'needs_confirmation' => false,
        'web_search_status' => 'confirmed',
        'verification_confidence' => 'high',
    ];
}, $cacheDir);

$budget = new WebSearchBudget();
assertTrue(
    invokeShouldRunClaudeFallback($service, [], [], $plan, $budget),
    'testClaudeFallbackRunsWhenBraveHasNoCandidates (predicate)',
);
assertTrue(
    invokeShouldRunClaudeFallback($service, [], [[
        'product_name' => '弱候補',
        'kcal' => 100,
        'identity_confidence' => 'medium',
        'verification_confidence' => 'medium',
        'source_url' => 'https://example.com/a',
    ]], $plan, $budget),
    'testClaudeFallbackRunsWhenBraveHasOnlyConfirmationCandidates (predicate)',
);
assertTrue(
    invokeShouldRunClaudeFallback($service, [[
        'product_name' => '弱accepted',
        'kcal' => 100,
        'identity_confidence' => 'medium',
        'verification_confidence' => 'medium',
        'variant_label' => '通常サイズ',
        'base_product_name' => '弱accepted',
    ]], [], $plan, $budget),
    'testClaudeFallbackRunsWhenAcceptedCandidatesCannotAutoConfirm (predicate)',
);

$strongPlan = singleProductPlan('わさビーフ', '山芳製菓');
$strongInputAnalysis = (new FoodVariantAnalyzer())->analyzeInput('わさビーフ 55g');
$strongAccepted = [[
    'product_name' => 'わさビーフ',
    'kcal' => 200,
    'identity_confidence' => 'high',
    'verification_confidence' => 'high',
    'variant_label' => '通常サイズ',
    'base_product_name' => 'わさビーフ',
    'package_size' => '55g',
    'serving_weight_g' => 55,
]];
assertFalse(
    invokeShouldRunClaudeFallback($service, $strongAccepted, [], $strongPlan, $budget, $strongInputAnalysis),
    'testStrongBraveAutoConfirmedResultSkipsClaudeFallback (predicate)',
);
echo "OK Claude fallback predicates\n";

// --- end-to-end: no Brave candidates → Claude runs ---
clearCacheDir($cacheDir);
$claudeCalls = 0;
$brave->results = [];
$variants->items = [];
$result = $service->search('ゼロ候補テスト商品', 'test-key');
assertSame(1, $claudeCalls, 'testClaudeFallbackRunsWhenBraveHasNoCandidates calls Claude');
assertSame('confirmed', $result['web_search_status'] ?? null, 'Claude confirmed status');
assertSame(321, $result['kcal'] ?? null, 'Claude kcal from fixture');
echo "OK testClaudeFallbackRunsWhenBraveHasNoCandidates\n";

// --- confirmation only → Claude runs ---
clearCacheDir($cacheDir);
$claudeCalls = 0;
$brave->results = [[
    'title' => '弱候補',
    'url' => 'https://blog.example.com/item',
    'description' => 'カロリー',
]];
$pages->htmlByUrl['https://blog.example.com/item'] = '<html><body>弱候補 100kcal</body></html>';
$variants->items = [[
    'productName' => '別の商品',
    'brandName' => null,
    'kcal' => 100,
    'variantLabel' => '通常サイズ',
    'variantDimension' => 'none',
    'verificationConfidence' => 'medium',
    'matchDecision' => ProductMatchResult::DECISION_NEEDS_CONFIRMATION,
    'matchScore' => 40.0,
    'matchReasons' => [],
    'sourceType' => 'html_text',
    'evidenceText' => '弱候補 100kcal',
]];
$planConfirm = singleProductPlan('確認のみテスト商品', 'テスト');
$serviceConfirm = makeService($planConfirm, $brave, $pages, $variants, function () use (&$claudeCalls): array {
    $claudeCalls++;

    return [
        'kcal' => 250,
        'confidence' => 'high',
        'product_name' => '確認のみテスト商品',
        'source' => 'claude_web_search',
        'source_url' => 'https://official.example.com/p',
        'identity_confidence' => 'high',
        'needs_confirmation' => false,
        'web_search_status' => 'confirmed',
        'verification_confidence' => 'high',
    ];
}, $cacheDir);
$resultConfirm = $serviceConfirm->search('確認のみテスト商品', 'test-key', new SearchRuntimeContext(
    allowExpensiveFallback: false,
    claudeFallbackMode: 'always',
));
assertTrue($claudeCalls >= 1, 'testClaudeFallbackRunsWhenBraveHasOnlyConfirmationCandidates');
assertSame('confirmed', $resultConfirm['web_search_status'] ?? null, 'Claude wins over confirmation');
assertSame(250, $resultConfirm['kcal'] ?? null, 'Claude confirmed kcal');
echo "OK testClaudeFallbackRunsWhenBraveHasOnlyConfirmationCandidates\n";
echo "OK testClaudeConfirmedResultWinsOverWeakBraveConfirmation\n";

// --- accepted cannot auto-confirm → Claude runs ---
clearCacheDir($cacheDir);
$claudeCalls = 0;
$variants->items = [[
    'productName' => 'テスト商品っぽい別物',
    'brandName' => null,
    'kcal' => 180,
    'variantLabel' => '通常サイズ',
    'variantDimension' => 'none',
    'verificationConfidence' => 'medium',
    'matchDecision' => ProductMatchResult::DECISION_ACCEPTED,
    'matchScore' => 70.0,
    'matchReasons' => ['has_distinct_cores' => true, 'name_similarity' => 0.5],
    'sourceType' => 'html_single_product',
    'evidenceText' => '180kcal',
]];
$planWeak = singleProductPlan('弱acceptedテスト商品', 'テスト');
$serviceWeakAccepted = makeService($planWeak, $brave, $pages, $variants, function () use (&$claudeCalls): array {
    $claudeCalls++;

    return [
        'kcal' => 199,
        'confidence' => 'high',
        'product_name' => '弱acceptedテスト商品',
        'source' => 'claude_web_search',
        'identity_confidence' => 'high',
        'needs_confirmation' => false,
        'web_search_status' => 'confirmed',
        'verification_confidence' => 'high',
    ];
}, $cacheDir);
$resultWeak = $serviceWeakAccepted->search('弱acceptedテスト商品', 'test-key');
assertTrue($claudeCalls >= 1, 'testClaudeFallbackRunsWhenAcceptedCandidatesCannotAutoConfirm');
assertSame(199, $resultWeak['kcal'] ?? null, 'Claude used for weak accepted');
echo "OK testClaudeFallbackRunsWhenAcceptedCandidatesCannotAutoConfirm\n";

// --- strong Brave skips Claude ---
clearCacheDir($cacheDir);
$claudeCalls = 0;
$brave->results = [[
    'title' => 'わさビーフ 公式',
    'url' => 'https://www.sanyo-seika.co.jp/item/1',
    'description' => 'カロリー',
]];
$pages->htmlByUrl['https://www.sanyo-seika.co.jp/item/1'] = '<html><body>わさビーフ 200kcal</body></html>';
$variants->items = [[
    'productName' => 'わさビーフ',
    'brandName' => '山芳製菓',
    'kcal' => 200,
    'variantLabel' => '通常サイズ',
    'variantDimension' => 'weight',
    'packageSize' => '55g',
    'servingWeightG' => 55,
    'verificationConfidence' => 'high',
    'matchDecision' => ProductMatchResult::DECISION_ACCEPTED,
    'matchScore' => 95.0,
    'matchReasons' => [],
    'sourceType' => 'html_single_product',
    'evidenceText' => '200kcal',
]];
$strongPlanE2E = singleProductPlan('わさビーフ', '山芳製菓');
$serviceStrong = makeService($strongPlanE2E, $brave, $pages, $variants, function () use (&$claudeCalls): array {
    $claudeCalls++;

    return ['kcal' => 999, 'needs_confirmation' => false, 'identity_confidence' => 'high', 'web_search_status' => 'confirmed'];
}, $cacheDir);
$resultStrong = $serviceStrong->search('わさビーフ 55g', 'test-key');
assertSame(0, $claudeCalls, 'testStrongBraveAutoConfirmedResultSkipsClaudeFallback');
assertSame(200, $resultStrong['kcal'] ?? null, 'Brave strong kcal kept');
assertSame('confirmed', $resultStrong['web_search_status'] ?? null, 'Brave confirmed status');
echo "OK testStrongBraveAutoConfirmedResultSkipsClaudeFallback\n";

// --- Claude failure keeps Brave confirmation ---
clearCacheDir($cacheDir);
$claudeCalls = 0;
$brave->results = [[
    'title' => '確認候補',
    'url' => 'https://example.com/confirm',
    'description' => 'kcal',
]];
$pages->htmlByUrl['https://example.com/confirm'] = '<html>確認候補 111kcal</html>';
$variants->items = [[
    'productName' => '近い別商品',
    'brandName' => null,
    'kcal' => 111,
    'variantLabel' => '通常サイズ',
    'variantDimension' => 'none',
    'verificationConfidence' => 'medium',
    'matchDecision' => ProductMatchResult::DECISION_NEEDS_CONFIRMATION,
    'matchScore' => 45.0,
    'matchReasons' => [],
    'sourceType' => 'html_text',
    'evidenceText' => '111kcal',
]];
$planFail = singleProductPlan('Claude失敗テスト商品', 'テスト');
$serviceFail = makeService($planFail, $brave, $pages, $variants, function () use (&$claudeCalls): array {
    $claudeCalls++;
    throw new RuntimeException('claude failed');
}, $cacheDir);
$resultFail = $serviceFail->search('Claude失敗テスト商品', 'test-key');
assertTrue($claudeCalls >= 1, 'Claude attempted before failure');
assertTrue(($resultFail['needs_confirmation'] ?? false) === true, 'testClaudeFailureReturnsExistingBraveConfirmationCandidates');
assertTrue(count($resultFail['candidates'] ?? []) >= 1, 'Brave confirmation retained');
assertSame(111, (int) ($resultFail['candidates'][0]['kcal'] ?? 0), 'Brave confirmation kcal fixture');
echo "OK testClaudeFailureReturnsExistingBraveConfirmationCandidates\n";

// --- cache rules ---
$cache = new WebSearchResultCache($cacheDir, 3600);
$confirmedPayload = [
    'plan' => $plan->toArray(),
    'response' => [
        'kcal' => 200,
        'needs_confirmation' => false,
        'identity_confidence' => 'high',
        'verification_confidence' => 'high',
        'web_search_status' => 'confirmed',
        'source' => 'brave_html',
    ],
];
assertTrue($cache->shouldCacheResponse($confirmedPayload['response']), 'confirmed eligible');
$cache->put('キャッシュ確定商品', $confirmedPayload, provider: 'auto');
assertTrue($cache->get('キャッシュ確定商品', provider: 'auto') !== null, 'testConfirmedResultsAreCached');

$confirmationPayload = [
    'plan' => $plan->toArray(),
    'response' => [
        'needs_confirmation' => true,
        'reason' => 'identity_ambiguous',
        'web_search_status' => 'needs_variant_confirmation',
        'candidates' => [['product_name' => 'x', 'kcal' => 1, 'source' => 'brave_html', 'identity_confidence' => 'medium']],
    ],
];
assertFalse($cache->shouldCacheResponse($confirmationPayload['response']), 'confirmation not eligible');
$cache->put('キャッシュ確認商品', $confirmationPayload, provider: 'auto');
assertTrue($cache->get('キャッシュ確認商品', provider: 'auto') === null, 'testConfirmationResultsAreNotCached');
echo "OK testConfirmationResultsAreNotCached\n";
echo "OK testConfirmedResultsAreCached\n";

putenv('AI_WEB_SEARCH_CACHE_ENABLED=false');
$disabledCache = new WebSearchResultCache($cacheDir . '_disabled', 3600);
@mkdir($cacheDir . '_disabled', 0775, true);
$disabledCache->put('無効化商品', $confirmedPayload, provider: 'auto');
assertTrue($disabledCache->get('無効化商品', provider: 'auto') === null, 'testCacheCanBeDisabledByEnvironmentVariable');
putenv('AI_WEB_SEARCH_CACHE_ENABLED=true');
echo "OK testCacheCanBeDisabledByEnvironmentVariable\n";

// --- search queries ---
$builder = new NutritionSearchQueryBuilder();
$variantPlan = singleProductPlan('マックフライポテト', 'マクドナルド', true);
$queries = $builder->buildSearchQueries('マックフライポテト', $variantPlan);
assertTrue(count($queries) <= 4, 'max 4 queries');
assertTrue(count($queries) >= 2, 'at least 2 queries');
$normalized = array_map(
    static fn (string $q): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q) ?? '')),
    $queries,
);
assertSame(count($normalized), count(array_unique($normalized)), 'testSearchQueriesAreDistinct');
$joined = implode("\n", $queries);
assertTrue(
    str_contains($joined, 'マックフライポテト') || str_contains($joined, 'フライドポテト'),
    'testSearchQueriesIncludeRawUserInput (product token)',
);
assertTrue(
    (bool) preg_match('/サイズ|一覧|内容量/u', $joined),
    'testVariantQueryIsOnlyAddedForLikelyVariants (present)',
);

$noVariantPlan = singleProductPlan('おろしハンバーグ', 'ナッシュ', false);
$noVariantQueries = $builder->buildSearchQueries('ナッシュ おろしハンバーグ', $noVariantPlan);
$noVariantJoined = implode("\n", $noVariantQueries);
assertTrue(str_contains($noVariantJoined, 'ナッシュ') || str_contains($noVariantJoined, 'おろしハンバーグ'), 'raw input retained');
assertFalse(
    (bool) preg_match('/サイズ一覧|商品一覧/u', $noVariantJoined),
    'testVariantQueryIsOnlyAddedForLikelyVariants (absent)',
);
echo "OK testSearchQueriesAreDistinct\n";
echo "OK testSearchQueriesIncludeRawUserInput\n";
echo "OK testVariantQueryIsOnlyAddedForLikelyVariants\n";

// --- official domain consistency ---
$resolver = new OfficialSiteBrandResolver();
foreach (['すき家' => 'sukiya.jp', '吉野家' => 'yoshinoya.com', '松屋' => 'matsuyafoods.co.jp', '山芳製菓' => 'sanyo-seika.co.jp'] as $brand => $domain) {
    $domains = $resolver->resolveOfficialDomains($brand, $brand);
    assertTrue(in_array($domain, $domains, true), "resolver knows {$domain}");
    assertTrue($resolver->isOfficialUrl('https://www.' . $domain . '/item/1', $brand, $brand), "resolver official {$domain}");
    $site = $builder->resolveOfficialSiteForTest($brand, $brand);
    assertSame($domain, $site, "query builder official site for {$brand}");
    $rankerExtractor = new NutritionPageExtractor();
    assertTrue($rankerExtractor->isOfficialUrl('https://www.' . $domain . '/x'), "extractor official {$domain}");
}
echo "OK testOfficialDomainIsConsistentAcrossQueryBuilderAndRanker\n";

// --- pause_turn ---
$pauseResponses = [
    [
        'stop_reason' => 'pause_turn',
        'content' => [
            ['type' => 'server_tool_use', 'id' => 'toolu_1', 'name' => 'web_search', 'input' => ['query' => 'x']],
        ],
    ],
    [
        'stop_reason' => 'end_turn',
        'content' => [
            ['type' => 'text', 'text' => json_encode([
                'product_name' => 'フィクスチャ商品',
                'brand' => null,
                'source_urls' => ['https://nosh.jp/menu/detail/999'],
                'kcal' => 333,
                'confidence' => 'high',
            ], JSON_UNESCAPED_UNICODE)],
        ],
    ],
];
$pauseIndex = 0;
$calorie = new CalorieEstimateService(
    nutritionPageExtractor: new class extends NutritionPageExtractor {
        public function fetchPageHtml(string $url): ?string
        {
            return null;
        }
    },
    anthropicTransport: function (array $payload, string $apiKey, bool $withWebSearch) use (&$pauseResponses, &$pauseIndex): array {
        $response = $pauseResponses[$pauseIndex] ?? $pauseResponses[array_key_last($pauseResponses)];
        $pauseIndex++;

        return $response;
    },
);
$refCal = new ReflectionClass($calorie);
$req = $refCal->getMethod('requestClaudeWebIdentification');
$req->setAccessible(true);
$identified = $req->invoke($calorie, 'フィクスチャ商品', 'test-key');
assertTrue(is_array($identified), 'pause_turn eventually parses');
assertSame(333, $identified['kcal'] ?? null, 'testClaudePauseTurnContinuesTheConversation');
assertSame(2, $pauseIndex, 'one continuation then end_turn');
$meta = $refCal->getProperty('lastClaudeWebSearchMeta');
$meta->setAccessible(true);
$metaValue = $meta->getValue($calorie);
assertSame(1, $metaValue['pause_turn_continuations'] ?? null, 'recorded continuation count');
echo "OK testClaudePauseTurnContinuesTheConversation\n";

$limitResponses = [
    ['stop_reason' => 'pause_turn', 'content' => [['type' => 'text', 'text' => 'still searching']]],
    ['stop_reason' => 'pause_turn', 'content' => [['type' => 'text', 'text' => 'still searching 2']]],
    ['stop_reason' => 'pause_turn', 'content' => [['type' => 'text', 'text' => 'still searching 3']]],
];
$limitIndex = 0;
$calorieLimit = new CalorieEstimateService(
    anthropicTransport: function () use (&$limitResponses, &$limitIndex): array {
        $response = $limitResponses[$limitIndex] ?? $limitResponses[array_key_last($limitResponses)];
        $limitIndex++;

        return $response;
    },
);
$reqLimit = (new ReflectionClass($calorieLimit))->getMethod('requestClaudeWebIdentification');
$reqLimit->setAccessible(true);
$limited = $reqLimit->invoke($calorieLimit, '上限商品名テスト', 'test-key');
assertSame(null, $limited, 'testClaudePauseTurnStopsAtContinuationLimit returns null');
$metaLimit = (new ReflectionClass($calorieLimit))->getProperty('lastClaudeWebSearchMeta');
$metaLimit->setAccessible(true);
$metaLimitValue = $metaLimit->getValue($calorieLimit);
assertSame(2, $metaLimitValue['pause_turn_continuations'] ?? null, 'stopped at max continuations');
assertTrue(($metaLimitValue['pause_turn_limit_exceeded'] ?? false) === true, 'limit exceeded flagged');
echo "OK testClaudePauseTurnStopsAtContinuationLimit\n";

foreach (glob($cacheDir . '/*.json') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);
foreach (glob($cacheDir . '_disabled/*.json') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir . '_disabled');

echo str_repeat('=', 48) . "\n";
echo "All fallback recovery tests passed\n";
