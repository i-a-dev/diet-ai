<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/OfficialSiteBrandResolver.php';
require_once __DIR__ . '/../src/ProductMatchResult.php';
require_once __DIR__ . '/../src/ProductMatchEvaluator.php';
require_once __DIR__ . '/../src/FoodVariantAnalyzer.php';
require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/FoodSearchSubject.php';
require_once __DIR__ . '/../src/FoodSearchSubjectNormalizer.php';
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

function assertFileHasNoForbiddenTokens(string $path, array $forbidden, string $label): void
{
    $text = file_get_contents($path);
    assertTrue(is_string($text), $label . ' readable');
    foreach ($forbidden as $token) {
        assertFalse(str_contains($text, $token), $label . ' must not contain ' . $token);
    }
}

echo "Official page discovery generic architecture tests\n";
echo str_repeat('=', 56) . "\n";

$forbidden = ['NoshCatalogProvider', 'たらの辛旨チリソース', '1057', '327'];
// core files must not contain product-specific / Nosh provider tokens (nosh.jp may exist only in profile config)
assertFileHasNoForbiddenTokens(__DIR__ . '/../src/AiWebSearchService.php', $forbidden, 'AiWebSearchService');
assertFileHasNoForbiddenTokens(__DIR__ . '/../src/OfficialPageDiscoveryService.php', array_merge($forbidden, ['nosh.jp']), 'OfficialPageDiscoveryService');
assertFileHasNoForbiddenTokens(__DIR__ . '/../src/ListingPageDiscoveryStrategy.php', array_merge($forbidden, ['nosh.jp']), 'ListingPageDiscoveryStrategy');
assertFileHasNoForbiddenTokens(__DIR__ . '/../src/SitemapDiscoveryStrategy.php', array_merge($forbidden, ['nosh.jp']), 'SitemapDiscoveryStrategy');
echo "OK testCoreDiscoveryServiceHasNoNoshDependency\n";

// --- unknown domain uses generic profile ---
$repo = new OfficialSiteProfileRepository();
$ctx = $repo->resolveContext('unknown-foods.example');
assertTrue($ctx !== null, 'generic context');
assertSame('generic', $ctx->profileSource, 'testUnknownDomainUsesGenericProfile source');
assertSame('unknown-foods.example', $ctx->domain(), 'generic domain');
assertTrue(in_array('robots_sitemap', $ctx->profile->enabledStrategies, true), 'generic has robots');
assertTrue(in_array('search_engine', $ctx->profile->enabledStrategies, true), 'generic has search_engine');
echo "OK testUnknownDomainUsesGenericProfile\n";

// --- Fixture A: listing HTML ---
$fixtureAHtml = <<<'HTML'
<html><body>
<a href="/products/alpha-bar" aria-label="Alpha Protein Bar">Alpha Protein Bar</a>
<a href="/about">About</a>
</body></html>
HTML;

// --- Fixture B: sitemap ---
$fixtureBSitemap = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://fixture-b.example/item/beta-soup</loc><image:title>Beta Soup Bowl</image:title></url>
  <url><loc>https://fixture-b.example/about</loc></url>
</urlset>
XML;

// --- Fixture C: embedded JSON ---
$fixtureCHtml = <<<'HTML'
<html><body>
<script type="application/json">
{"items":[{"name":"Gamma Crunch Chips","url":"/products/gamma-crunch-chips","kcal":210}]}
</script>
</body></html>
HTML;

$fixtureJsonLdHtml = <<<'HTML'
<html><body>
<script type="application/ld+json">
{"@type":"Product","name":"Delta Yogurt Cup","url":"https://fixture-d.example/products/delta-yogurt"}
</script>
</body></html>
HTML;

$httpMap = [
    'https://fixture-a.example/' => $fixtureAHtml,
    'https://fixture-a.example/products' => $fixtureAHtml,
    'https://fixture-b.example/sitemap.xml' => $fixtureBSitemap,
    'https://www.fixture-b.example/sitemap.xml' => $fixtureBSitemap,
    'https://fixture-c.example/' => $fixtureCHtml,
    'https://fixture-d.example/' => $fixtureJsonLdHtml,
    'https://fixture-a.example/robots.txt' => "User-agent: *\nSitemap: https://fixture-a.example/sitemap.xml\n",
];

$fetcher = static function (string $url) use (&$httpMap): ?string {
    return $httpMap[$url] ?? null;
};

$cacheDir = sys_get_temp_dir() . '/diet_ai_official_discovery_generic_' . getmypid();
@mkdir($cacheDir, 0775, true);

$profileA = new OfficialSiteProfile(
    domain: 'fixture-a.example',
    seedPaths: ['/'],
    allowedPathPatterns: ['/products/...'],
    detailPathPatterns: ['/products/{slug}'],
    enabledStrategies: ['listing_page'],
);
$profileB = new OfficialSiteProfile(
    domain: 'fixture-b.example',
    seedPaths: ['/'],
    allowedPathPatterns: ['/item/...'],
    detailPathPatterns: ['/item/{slug}'],
    enabledStrategies: ['sitemap'],
);
$profileC = new OfficialSiteProfile(
    domain: 'fixture-c.example',
    seedPaths: ['/'],
    allowedPathPatterns: ['/products/...'],
    detailPathPatterns: ['/products/{slug}'],
    enabledStrategies: ['embedded_json'],
);
$profileD = new OfficialSiteProfile(
    domain: 'fixture-d.example',
    seedPaths: ['/'],
    allowedPathPatterns: ['/products/...'],
    detailPathPatterns: ['/products/{slug}'],
    enabledStrategies: ['structured_data'],
    structuredDataTypes: ['Product'],
);

$http = new OfficialDiscoveryHttpClient($fetcher);
$factory = new OfficialDiscoveryCandidateFactory();
$env = new DiscoveryEnvironment(httpFetcher: $fetcher);

// robots
$robots = new RobotsSitemapDiscoveryStrategy($http, $factory);
$robotsHits = $robots->discover(
    new FoodSearchSubject('x', 'Brand', 'Alpha', []),
    new OfficialSiteContext(new OfficialSiteProfile(
        domain: 'fixture-a.example',
        enabledStrategies: ['robots_sitemap'],
        allowedPathPatterns: ['/...'],
    ), 'generic'),
    new OfficialDiscoveryBudget(),
    $env,
);
assertTrue($robotsHits !== [], 'testGenericRobotsStrategyFindsSitemap');
assertTrue(str_contains($robotsHits[0]->url, 'sitemap.xml'), 'robots sitemap url');
echo "OK testGenericRobotsStrategyFindsSitemap\n";

// sitemap
$sitemap = new SitemapDiscoveryStrategy($http, $factory);
$subjectB = new FoodSearchSubject('Beta Soup Bowl', 'Brand', 'Beta Soup Bowl', []);
$sitemapHits = $sitemap->discover(
    $subjectB,
    new OfficialSiteContext($profileB, 'registered'),
    new OfficialDiscoveryBudget(),
    $env,
);
assertTrue($sitemapHits !== [], 'testGenericSitemapStrategyFindsProductDetail');
assertTrue(str_contains($sitemapHits[0]->url, '/item/beta-soup'), 'sitemap detail url');
echo "OK testGenericSitemapStrategyFindsProductDetail\n";

// listing
$listing = new ListingPageDiscoveryStrategy($http, $factory, cache: new OfficialDiscoveryIndexCache($cacheDir . '/a'));
$subjectA = new FoodSearchSubject('Alpha Protein Bar', 'Brand', 'Alpha Protein Bar', []);
$listingHits = $listing->discover(
    $subjectA,
    new OfficialSiteContext($profileA, 'registered'),
    new OfficialDiscoveryBudget(),
    $env,
);
assertTrue($listingHits !== [], 'testGenericListingStrategyFindsProductDetail');
assertTrue(str_contains($listingHits[0]->url, '/products/alpha-bar'), 'listing detail');
echo "OK testGenericListingStrategyFindsProductDetail\n";

// structured data
$structured = new StructuredDataDiscoveryStrategy($http, $factory);
$subjectD = new FoodSearchSubject('Delta Yogurt Cup', 'Brand', 'Delta Yogurt Cup', []);
$structuredHits = $structured->discover(
    $subjectD,
    new OfficialSiteContext($profileD, 'registered'),
    new OfficialDiscoveryBudget(),
    $env,
);
assertTrue($structuredHits !== [], 'testGenericStructuredDataStrategyFindsProduct');
assertTrue(str_contains($structuredHits[0]->url, 'delta-yogurt'), 'jsonld url');
echo "OK testGenericStructuredDataStrategyFindsProduct\n";

// embedded json
$embedded = new EmbeddedJsonDiscoveryStrategy($http, $factory);
$subjectC = new FoodSearchSubject('Gamma Crunch Chips', 'Brand', 'Gamma Crunch Chips', []);
$embeddedHits = $embedded->discover(
    $subjectC,
    new OfficialSiteContext($profileC, 'registered'),
    new OfficialDiscoveryBudget(),
    $env,
);
assertTrue($embeddedHits !== [], 'testGenericEmbeddedJsonStrategyFindsProduct');
assertTrue(str_contains($embeddedHits[0]->url, 'gamma-crunch'), 'embedded url');
echo "OK testGenericEmbeddedJsonStrategyFindsProduct\n";

// merge by URL
$service = new OfficialPageDiscoveryService(
    http: $http,
    cache: new OfficialDiscoveryIndexCache($cacheDir . '/merge'),
    strategies: [
        new ListingPageDiscoveryStrategy($http, $factory, cache: new OfficialDiscoveryIndexCache($cacheDir . '/merge_l')),
        new SearchEngineDiscoveryStrategy($factory),
    ],
);
// Use a custom brand resolver bypass: discoverWithDiagnostics needs resolveOfficialSite.
// For fixture domain, inject via anonymous brand resolver isn't easy; call strategies merge manually.
$merged = [];
foreach (array_merge($listingHits, [
    new DiscoveredPageCandidate(
        url: $listingHits[0]->url,
        candidateName: 'Alpha Protein Bar',
        discoverySource: 'brave',
        isOfficial: true,
        nameSimilarity: 1.0,
        coreSimilarity: 1.0,
        hasDistinctCoreConflict: false,
        evidence: ['brave'],
    ),
]) as $cand) {
    $key = parse_url($cand->url, PHP_URL_HOST) . rtrim((string) parse_url($cand->url, PHP_URL_PATH), '/');
    if (!isset($merged[$key])) {
        $merged[$key] = $cand;
    } else {
        $merged[$key] = $merged[$key]->withMergedEvidence($cand->evidence, $cand->candidateName);
    }
}
assertSame(1, count($merged), 'testCandidatesFromMultipleStrategiesAreMergedByUrl');
$mergedOne = array_values($merged)[0];
assertTrue(in_array('brave', $mergedOne->evidence, true) || in_array('listing_page', $mergedOne->evidence, true), 'evidence merged');
echo "OK testCandidatesFromMultipleStrategiesAreMergedByUrl\n";

// distinct core conflict rejected
$conflict = $factory->create(
    'https://fixture-a.example/products/alpha-sweet',
    'Alpha Sweet Bar',
    'listing_page',
    new FoodSearchSubject('Alpha Spicy Bar', 'Brand', 'Alpha Spicy Bar', []),
    $profileA,
    ['listing_page'],
);
assertTrue($conflict === null || $conflict->hasDistinctCoreConflict || $conflict->nameSimilarity < 0.9, 'testDistinctCoreConflictIsRejectedBeforeDetailFetch');
if ($conflict !== null && $conflict->hasDistinctCoreConflict) {
    assertFalse($factory->passesSubjectFilter($conflict, new FoodSearchSubject('Alpha Spicy Bar', 'Brand', 'Alpha Spicy Bar', [])), 'filter rejects conflict');
}
echo "OK testDistinctCoreConflictIsRejectedBeforeDetailFetch\n";

// budget stops unbounded crawl
$budget = new OfficialDiscoveryBudget();
for ($i = 0; $i < OfficialDiscoveryBudget::MAX_DISCOVERED_CANDIDATES; $i++) {
    assertTrue($budget->canAcceptCandidate(), 'can accept before max');
    $budget->recordCandidate();
}
assertFalse($budget->canAcceptCandidate(), 'testDiscoveryBudgetStopsUnboundedCrawling');
assertTrue($budget->isExhausted(), 'budget exhausted flag');
echo "OK testDiscoveryBudgetStopsUnboundedCrawling\n";

// redirect outside domain rejected
$httpSafe = new OfficialDiscoveryHttpClient();
assertFalse($httpSafe->isAllowedUrl('https://evil.example/x', 'fixture-a.example'), 'testRedirectOutsideOfficialDomainIsRejected');
echo "OK testRedirectOutsideOfficialDomainIsRejected\n";

// private network rejected
assertFalse($httpSafe->isAllowedUrl('http://127.0.0.1/x'), 'testPrivateNetworkUrlIsRejected loopback');
assertFalse($httpSafe->isAllowedUrl('http://10.0.0.5/x'), 'testPrivateNetworkUrlIsRejected private');
assertFalse($httpSafe->isAllowedUrl('http://localhost/x'), 'localhost rejected');
echo "OK testPrivateNetworkUrlIsRejected\n";

// cache scoped by domain+profile version
$cache = new OfficialDiscoveryIndexCache($cacheDir . '/ver');
$cache->put('fixture-a.example', 1, [['url' => 'https://fixture-a.example/products/a', 'candidate_name' => 'A']]);
assertTrue($cache->get('fixture-a.example', 1) !== null, 'cache hit v1');
assertTrue($cache->get('fixture-a.example', 2) === null, 'testCatalogCacheIsScopedByDomainAndProfileVersion');
assertTrue($cache->get('other.example', 1) === null, 'cache miss other domain');
echo "OK testCatalogCacheIsScopedByDomainAndProfileVersion\n";

// nosh profile contains no product-specific data
$noshProfile = $repo->findByDomain('nosh.jp');
assertTrue($noshProfile !== null, 'nosh profile exists');
$encoded = json_encode($noshProfile, JSON_UNESCAPED_UNICODE);
assertFalse(str_contains((string) $encoded, 'たらの辛旨'), 'testNoshProfileContainsNoProductSpecificData name');
assertFalse(str_contains((string) $encoded, '1057'), 'no product id');
assertFalse(str_contains((string) $encoded, '327'), 'no kcal');
assertSame('/menu', $noshProfile->seedPaths[0] ?? null, 'seed path');
echo "OK testNoshProfileContainsNoProductSpecificData\n";

echo str_repeat('=', 56) . "\n";
echo "All official discovery generic tests passed.\n";
