<?php

declare(strict_types=1);

/**
 * 公式ページ探索オーケストレーター（ブランド固有 Provider を持たない）。
 */
final class OfficialPageDiscoveryService
{
    /** @var list<OfficialPageDiscoveryStrategy> */
    private array $strategies;

    /**
     * @param list<OfficialPageDiscoveryStrategy>|null $strategies
     */
    public function __construct(
        private readonly OfficialSiteBrandResolver $brandResolver = new OfficialSiteBrandResolver(),
        private readonly OfficialSiteProfileRepository $profiles = new OfficialSiteProfileRepository(),
        private readonly OfficialDiscoveryCandidateFactory $candidateFactory = new OfficialDiscoveryCandidateFactory(),
        private readonly OfficialDiscoveryHttpClient $http = new OfficialDiscoveryHttpClient(),
        private readonly OfficialDiscoveryIndexCache $cache = new OfficialDiscoveryIndexCache(),
        ?array $strategies = null,
    ) {
        $this->strategies = $strategies ?? $this->defaultStrategies();
    }

    /**
     * @return list<OfficialPageDiscoveryStrategy>
     */
    private function defaultStrategies(): array
    {
        return [
            new RobotsSitemapDiscoveryStrategy($this->http, $this->candidateFactory),
            new SitemapDiscoveryStrategy($this->http, $this->candidateFactory),
            new ListingPageDiscoveryStrategy($this->http, $this->candidateFactory, cache: $this->cache),
            new StructuredDataDiscoveryStrategy($this->http, $this->candidateFactory),
            new EmbeddedJsonDiscoveryStrategy($this->http, $this->candidateFactory),
            new SearchEngineDiscoveryStrategy($this->candidateFactory),
        ];
    }

    /**
     * @param list<array{title?: string, url?: string, description?: string}> $searchResults
     */
    public function shouldRun(FoodSearchSubject $subject, array $searchResults): bool
    {
        $domain = $this->brandResolver->resolveOfficialSite($subject->brandName, $subject->rawInput);
        if ($domain === null) {
            return false;
        }

        $pathHint = $this->brandResolver->officialDetailPathHint($subject->brandName, $subject->rawInput);
        $productName = trim($subject->productName);
        $evaluator = new ProductMatchEvaluator();

        foreach ($searchResults as $result) {
            $url = trim((string) ($result['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== $domain && !str_ends_with($host, '.' . $domain)) {
                continue;
            }
            if ($pathHint !== null && !str_contains(mb_strtolower((string) parse_url($url, PHP_URL_PATH)), mb_strtolower($pathHint))) {
                continue;
            }

            $title = trim((string) ($result['title'] ?? ''));
            if ($productName === '' || $title === '') {
                return false;
            }

            $match = $evaluator->analyzeTitleMatch($productName, $title, $subject->brandName);
            if (
                ($match['has_distinct_cores'] ?? false) !== true
                && (
                    ($match['has_exact_phrase'] ?? false) === true
                    || (float) ($match['token_coverage'] ?? 0.0) >= 0.9
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *   candidates: list<DiscoveredPageCandidate>,
     *   diagnostics: array<string, mixed>
     * }
     */
    /**
     * @param list<string>|null $strategyAllowlist null ならプロファイルの全有効戦略
     */
    public function discoverWithDiagnostics(
        FoodSearchSubject $subject,
        DiscoveryEnvironment $environment = new DiscoveryEnvironment(),
        ?OfficialDiscoveryBudget $budget = null,
        ?array $strategyAllowlist = null,
    ): array {
        $budget ??= new OfficialDiscoveryBudget();
        $domain = $this->brandResolver->resolveOfficialSite($subject->brandName, $subject->rawInput);
        $context = $this->profiles->resolveContext($domain);
        if ($context === null) {
            return [
                'candidates' => [],
                'diagnostics' => [
                    'official_discovery_ran' => false,
                    'official_profile_source' => null,
                    'official_profile_domain' => null,
                    'enabled_discovery_strategies' => [],
                    'executed_discovery_strategies' => [],
                    'merged_official_candidates' => 0,
                    'discovery_budget_exhausted' => false,
                    'site_adapter_used' => false,
                    'final_discovery_source' => null,
                ],
            ];
        }

        $enabled = $context->profile->enabledStrategies;
        if ($strategyAllowlist !== null) {
            $enabled = array_values(array_intersect($enabled, $strategyAllowlist));
        }
        $executed = [];
        $robotsUrls = 0;
        $sitemapUrls = 0;
        $listingFetched = 0;
        $embeddedCount = 0;
        $structuredCount = 0;
        /** @var array<string, DiscoveredPageCandidate> $merged */
        $merged = [];

        foreach ($this->strategies as $strategy) {
            if (!in_array($strategy->name(), $enabled, true)) {
                continue;
            }
            if (!$strategy->supports($context, $environment)) {
                continue;
            }
            if ($budget->isExhausted()) {
                break;
            }

            $executed[] = $strategy->name();
            $beforeListing = $budget->snapshot()['listingPageFetches'];
            $found = $strategy->discover($subject, $context, $budget, $environment);
            $afterListing = $budget->snapshot()['listingPageFetches'];
            if ($afterListing > $beforeListing) {
                $listingFetched += ($afterListing - $beforeListing);
            }

            foreach ($found as $candidate) {
                if ($candidate->hasDistinctCoreConflict) {
                    continue;
                }
                if ($strategy->name() === 'robots_sitemap') {
                    $robotsUrls++;
                }
                if ($strategy->name() === 'sitemap') {
                    $sitemapUrls++;
                }
                if ($strategy->name() === 'embedded_json') {
                    $embeddedCount++;
                }
                if ($strategy->name() === 'structured_data') {
                    $structuredCount++;
                }

                $canonical = $this->canonicalUrl($candidate->url);
                if ($canonical === '') {
                    continue;
                }
                if (!isset($merged[$canonical])) {
                    $merged[$canonical] = $candidate;
                } else {
                    $merged[$canonical] = $merged[$canonical]->withMergedEvidence(
                        $candidate->evidence,
                        $candidate->candidateName,
                    );
                }
            }

            foreach ($merged as $candidate) {
                if ($this->candidateFactory->isStrongMatch($candidate) && $candidate->isOfficial) {
                    break 2;
                }
            }
        }

        $candidates = array_values($merged);
        usort(
            $candidates,
            static function (DiscoveredPageCandidate $a, DiscoveredPageCandidate $b): int {
                if ($a->hasDistinctCoreConflict !== $b->hasDistinctCoreConflict) {
                    return $a->hasDistinctCoreConflict ? 1 : -1;
                }

                return $b->nameSimilarity <=> $a->nameSimilarity;
            },
        );

        $finalSource = $candidates[0]->discoverySource ?? null;

        return [
            'candidates' => array_slice($candidates, 0, $context->profile->maxCandidateUrls),
            'diagnostics' => [
                'official_discovery_ran' => true,
                'official_profile_source' => $context->profileSource,
                'official_profile_domain' => $context->domain(),
                'enabled_discovery_strategies' => $enabled,
                'executed_discovery_strategies' => $executed,
                'robots_urls_found' => $robotsUrls,
                'sitemap_urls_found' => $sitemapUrls,
                'listing_pages_fetched' => $listingFetched,
                'embedded_json_candidates' => $embeddedCount,
                'structured_data_candidates' => $structuredCount,
                'merged_official_candidates' => count($candidates),
                'discovery_budget_exhausted' => $budget->isExhausted(),
                'selected_detail_urls' => array_map(
                    static fn (DiscoveredPageCandidate $c): string => $c->url,
                    array_slice($candidates, 0, 10),
                ),
                'final_discovery_source' => $finalSource,
                'site_adapter_used' => false,
            ],
        ];
    }

    /**
     * @return list<DiscoveredPageCandidate>
     */
    public function discover(
        FoodSearchSubject $subject,
        DiscoveryEnvironment $environment = new DiscoveryEnvironment(),
        ?OfficialDiscoveryBudget $budget = null,
    ): array {
        return $this->discoverWithDiagnostics($subject, $environment, $budget)['candidates'];
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return '';
        }
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        $path = rtrim($path, '/') ?: '/';

        return $host . $path;
    }
}
