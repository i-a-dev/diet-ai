<?php

declare(strict_types=1);

/**
 * AI Web 検索の計測・構造化ログ。
 */
final class WebSearchDiagnostics
{
    private float $startedAt;
    private string $requestId;

    /** @var list<string> */
    private array $fallbackReasons = [];

    public function __construct(
        private readonly string $userInput,
        private readonly WebSearchBudget $budget,
    ) {
        $this->startedAt = microtime(true);
        $this->requestId = bin2hex(random_bytes(8));
    }

    private string $normalizedProductName = '';
    private ?string $brandName = null;
    private string $searchMode = '';
    private string $productType = '';
    private string $variantDimension = '';
    private int $claudeExpectedLabelCount = 0;
    private int $finalCandidateCount = 0;

    /** @var 'sufficient_variants'|'single_candidate_confirmed'|'search_budget_exhausted'|'no_candidates'|'no_web_search'|'fallback'|'' */
    private string $stoppedReason = '';
    private string $searchProvider = 'auto';
    private string $finalStatus = '';
    private bool $cacheHit = false;

    /** @var list<string> */
    private array $generatedQueries = [];

    /** @var list<string> */
    private array $executedQueries = [];

    /** @var list<string> */
    private array $skippedDuplicateQueries = [];

    private int $braveResultCount = 0;

    /** @var list<array{url: string, score?: float|int}> */
    private array $urlRanking = [];

    private int $htmlFetchSuccessCount = 0;
    private int $htmlFetchFailureCount = 0;
    private int $htmlExtractedCandidateCount = 0;
    private int $acceptedCount = 0;
    private int $confirmationCount = 0;
    private int $rejectedCount = 0;

    /** @var list<string> */
    private array $rejectReasons = [];

    private bool $claudeFallbackRan = false;
    private ?string $claudeFallbackSkipReason = null;
    private ?string $claudeStopReason = null;
    private int $claudePauseTurnContinuations = 0;
    private ?string $claudeSourceUrl = null;
    private ?string $claudeFallbackFailure = null;

    private bool $searchPhaseCompleted = false;

    /** @var array<string, int> */
    private array $resultsPerQuery = [];

    private int $mergedUrlCount = 0;
    private int $exactTitleCandidateCount = 0;
    private int $officialCandidateCount = 0;

    /** @var list<array{url: string, reason: string, score?: int, fetch_priority?: string}> */
    private array $htmlFetchPlan = [];

    /** @var array<string, string> url => reason */
    private array $htmlFetchReasons = [];

    private int $htmlBudgetRemaining = WebSearchBudget::MAX_HTML_FETCHES;
    private bool $officialCatalogRan = false;
    private int $officialCatalogCandidateCount = 0;
    private ?string $officialProfileSource = null;
    private ?string $officialProfileDomain = null;
    /** @var list<string> */
    private array $enabledDiscoveryStrategies = [];
    /** @var list<string> */
    private array $executedDiscoveryStrategies = [];
    private int $robotsUrlsFound = 0;
    private int $sitemapUrlsFound = 0;
    private int $listingPagesFetched = 0;
    private int $embeddedJsonCandidates = 0;
    private int $structuredDataCandidates = 0;
    private int $mergedOfficialCandidates = 0;
    private bool $discoveryBudgetExhausted = false;
    /** @var list<string> */
    private array $selectedDetailUrls = [];
    private ?string $finalDiscoverySource = null;
    private bool $siteAdapterUsed = false;
    private ?string $claudeStatus = null;
    private bool $claudeNotFoodContractViolation = false;
    private ?string $finalSourceStrategy = null;

    /** @var list<array{url: string, has_product_name?: bool, has_calorie?: bool, has_nutrition?: bool}> */
    private array $extraSnippetSignals = [];

    public function setPlan(FoodWebSearchPlan $plan): void
    {
        $this->normalizedProductName = $plan->normalizedProductName;
        $this->brandName = $plan->brandName;
        $this->searchMode = $plan->searchMode;
        $this->productType = $plan->productType;
        $this->variantDimension = $plan->variantDimension;
        $this->claudeExpectedLabelCount = count($plan->expectedLabels);
    }

    public function setSearchProvider(string $provider): void
    {
        $this->searchProvider = AiWebSearchProvider::resolve($provider);
    }

    public function setFinalCandidateCount(int $count): void
    {
        $this->finalCandidateCount = $count;
    }

    public function setStoppedReason(string $reason): void
    {
        $this->stoppedReason = $reason;
    }

    public function setFinalStatus(string $status): void
    {
        $this->finalStatus = $status;
    }

    public function setCacheHit(bool $hit): void
    {
        $this->cacheHit = $hit;
    }

    /**
     * @param list<string> $queries
     */
    public function setGeneratedQueries(array $queries): void
    {
        $this->generatedQueries = array_values($queries);
    }

    /**
     * @param list<string> $queries
     */
    public function setExecutedQueries(array $queries): void
    {
        $this->executedQueries = array_values($queries);
    }

    /**
     * @param list<string> $queries
     */
    public function setSkippedDuplicateQueries(array $queries): void
    {
        $this->skippedDuplicateQueries = array_values($queries);
    }

    public function setBraveResultCount(int $count): void
    {
        $this->braveResultCount = $count;
    }

    /**
     * @param list<array{url: string, score?: float|int}> $ranking
     */
    public function setUrlRanking(array $ranking): void
    {
        $this->urlRanking = array_slice(array_map(
            static function (array $entry): array {
                return [
                    'url' => (string) ($entry['url'] ?? ''),
                    'score' => $entry['score'] ?? null,
                ];
            },
            $ranking,
        ), 0, 10);
    }

    public function recordHtmlFetchSuccess(): void
    {
        $this->htmlFetchSuccessCount++;
    }

    public function recordHtmlFetchFailure(): void
    {
        $this->htmlFetchFailureCount++;
    }

    public function setHtmlExtractedCandidateCount(int $count): void
    {
        $this->htmlExtractedCandidateCount = $count;
    }

    public function setCandidateCounts(int $accepted, int $confirmation, int $rejected): void
    {
        $this->acceptedCount = $accepted;
        $this->confirmationCount = $confirmation;
        $this->rejectedCount = $rejected;
    }

    public function addRejectReason(string $reason): void
    {
        if ($reason !== '' && !in_array($reason, $this->rejectReasons, true)) {
            $this->rejectReasons[] = $reason;
        }
    }

    public function setClaudeFallbackRan(bool $ran): void
    {
        $this->claudeFallbackRan = $ran;
    }

    public function setClaudeFallbackSkipReason(?string $reason): void
    {
        $this->claudeFallbackSkipReason = $reason;
    }

    public function setClaudeStopReason(?string $reason): void
    {
        $this->claudeStopReason = $reason;
    }

    public function setClaudePauseTurnContinuations(int $count): void
    {
        $this->claudePauseTurnContinuations = $count;
    }

    public function setClaudeSourceUrl(?string $url): void
    {
        $this->claudeSourceUrl = $url;
    }

    public function recordClaudeFallbackFailure(Throwable $exception): void
    {
        $this->claudeFallbackFailure = $exception::class . ': ' . mb_substr($exception->getMessage(), 0, 200);
        $this->addFallbackReason('claude_web_search_exception');
    }

    public function setSearchPhaseCompleted(bool $completed): void
    {
        $this->searchPhaseCompleted = $completed;
    }

    public function setResultsPerQuery(string $query, int $count): void
    {
        $this->resultsPerQuery[$query] = $count;
    }

    public function setMergedUrlCount(int $count): void
    {
        $this->mergedUrlCount = $count;
    }

    public function setExactTitleCandidateCount(int $count): void
    {
        $this->exactTitleCandidateCount = $count;
    }

    public function setOfficialCandidateCount(int $count): void
    {
        $this->officialCandidateCount = $count;
    }

    /**
     * @param list<array{url: string, reason?: string, score?: int, fetch_priority?: string}> $plan
     */
    public function setHtmlFetchPlan(array $plan): void
    {
        $this->htmlFetchPlan = [];
        $this->htmlFetchReasons = [];
        foreach ($plan as $entry) {
            $url = (string) ($entry['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $reason = (string) ($entry['reason'] ?? 'overall_rank');
            $this->htmlFetchPlan[] = [
                'url' => $url,
                'reason' => $reason,
                'score' => isset($entry['score']) ? (int) $entry['score'] : null,
                'fetch_priority' => isset($entry['fetch_priority']) ? (string) $entry['fetch_priority'] : null,
            ];
            $this->htmlFetchReasons[$url] = $reason;
        }
    }

    public function setHtmlBudgetRemaining(int $remaining): void
    {
        $this->htmlBudgetRemaining = max(0, $remaining);
    }

    public function setOfficialCatalogRan(bool $ran, int $candidateCount = 0): void
    {
        $this->officialCatalogRan = $ran;
        $this->officialCatalogCandidateCount = $candidateCount;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applyOfficialDiscoveryDiagnostics(array $payload): void
    {
        $this->officialCatalogRan = (bool) ($payload['official_discovery_ran'] ?? false);
        $this->officialCatalogCandidateCount = (int) ($payload['merged_official_candidates'] ?? 0);
        $this->officialProfileSource = isset($payload['official_profile_source'])
            ? (string) $payload['official_profile_source']
            : null;
        $this->officialProfileDomain = isset($payload['official_profile_domain'])
            ? (string) $payload['official_profile_domain']
            : null;
        $this->enabledDiscoveryStrategies = array_values(array_map(
            'strval',
            is_array($payload['enabled_discovery_strategies'] ?? null) ? $payload['enabled_discovery_strategies'] : [],
        ));
        $this->executedDiscoveryStrategies = array_values(array_map(
            'strval',
            is_array($payload['executed_discovery_strategies'] ?? null) ? $payload['executed_discovery_strategies'] : [],
        ));
        $this->robotsUrlsFound = (int) ($payload['robots_urls_found'] ?? 0);
        $this->sitemapUrlsFound = (int) ($payload['sitemap_urls_found'] ?? 0);
        $this->listingPagesFetched = (int) ($payload['listing_pages_fetched'] ?? 0);
        $this->embeddedJsonCandidates = (int) ($payload['embedded_json_candidates'] ?? 0);
        $this->structuredDataCandidates = (int) ($payload['structured_data_candidates'] ?? 0);
        $this->mergedOfficialCandidates = (int) ($payload['merged_official_candidates'] ?? 0);
        $this->discoveryBudgetExhausted = (bool) ($payload['discovery_budget_exhausted'] ?? false);
        $this->selectedDetailUrls = array_values(array_map(
            'strval',
            is_array($payload['selected_detail_urls'] ?? null) ? $payload['selected_detail_urls'] : [],
        ));
        $this->finalDiscoverySource = isset($payload['final_discovery_source'])
            ? (string) $payload['final_discovery_source']
            : null;
        $this->siteAdapterUsed = (bool) ($payload['site_adapter_used'] ?? false);
    }

    public function setClaudeStatus(?string $status): void
    {
        $this->claudeStatus = $status;
    }

    public function setClaudeNotFoodContractViolation(bool $violated): void
    {
        $this->claudeNotFoodContractViolation = $violated;
    }

    public function setFinalSourceStrategy(?string $strategy): void
    {
        $this->finalSourceStrategy = $strategy;
    }

    /**
     * @param array{url: string, has_product_name?: bool, has_calorie?: bool, has_nutrition?: bool} $signal
     */
    public function addExtraSnippetSignal(array $signal): void
    {
        $this->extraSnippetSignals[] = $signal;
    }

    public function addFallbackReason(string $reason): void
    {
        if ($reason !== '' && !in_array($reason, $this->fallbackReasons, true)) {
            $this->fallbackReasons[] = $reason;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $snapshot = $this->budget->snapshot();

        return [
            'request_id' => $this->requestId,
            'user_input' => $this->userInput,
            'userInput' => $this->userInput,
            'provider' => $this->searchProvider,
            'searchProvider' => $this->searchProvider,
            'cache_hit' => $this->cacheHit,
            'plan' => [
                'search_mode' => $this->searchMode,
                'product_type' => $this->productType,
                'brand_name' => $this->brandName,
            ],
            'normalizedProductName' => $this->normalizedProductName,
            'brandName' => $this->brandName,
            'searchMode' => $this->searchMode,
            'variantDimension' => $this->variantDimension,
            'claudeExpectedLabelCount' => $this->claudeExpectedLabelCount,
            'generated_queries' => $this->generatedQueries,
            'executed_queries' => $this->executedQueries,
            'skipped_duplicate_queries' => $this->skippedDuplicateQueries,
            'search_phase_completed' => $this->searchPhaseCompleted,
            'results_per_query' => $this->resultsPerQuery,
            'merged_url_count' => $this->mergedUrlCount,
            'brave_result_count' => $this->braveResultCount,
            'exact_title_candidate_count' => $this->exactTitleCandidateCount,
            'official_candidate_count' => $this->officialCandidateCount,
            'url_ranking' => $this->urlRanking,
            'html_fetch_plan' => $this->htmlFetchPlan,
            'html_fetch_reason' => $this->htmlFetchReasons,
            'html_budget_remaining' => $this->htmlBudgetRemaining,
            'html_fetch_success' => $this->htmlFetchSuccessCount,
            'html_fetch_failure' => $this->htmlFetchFailureCount,
            'html_extracted_candidate_count' => $this->htmlExtractedCandidateCount,
            'accepted_count' => $this->acceptedCount,
            'confirmation_count' => $this->confirmationCount,
            'rejected_count' => $this->rejectedCount,
            'reject_reasons' => $this->rejectReasons,
            'official_catalog_ran' => $this->officialCatalogRan,
            'official_catalog_candidate_count' => $this->officialCatalogCandidateCount,
            'official_discovery_ran' => $this->officialCatalogRan,
            'official_profile_source' => $this->officialProfileSource,
            'official_profile_domain' => $this->officialProfileDomain,
            'enabled_discovery_strategies' => $this->enabledDiscoveryStrategies,
            'executed_discovery_strategies' => $this->executedDiscoveryStrategies,
            'robots_urls_found' => $this->robotsUrlsFound,
            'sitemap_urls_found' => $this->sitemapUrlsFound,
            'listing_pages_fetched' => $this->listingPagesFetched,
            'embedded_json_candidates' => $this->embeddedJsonCandidates,
            'structured_data_candidates' => $this->structuredDataCandidates,
            'merged_official_candidates' => $this->mergedOfficialCandidates,
            'discovery_budget_exhausted' => $this->discoveryBudgetExhausted,
            'selected_detail_urls' => $this->selectedDetailUrls,
            'final_discovery_source' => $this->finalDiscoverySource,
            'site_adapter_used' => $this->siteAdapterUsed,
            'claude_fallback_ran' => $this->claudeFallbackRan,
            'claude_fallback_skip_reason' => $this->claudeFallbackSkipReason,
            'claude_stop_reason' => $this->claudeStopReason,
            'claude_status' => $this->claudeStatus,
            'claude_not_food_contract_violation' => $this->claudeNotFoodContractViolation,
            'pause_turn_continuations' => $this->claudePauseTurnContinuations,
            'claude_source_url' => $this->claudeSourceUrl,
            'claude_fallback_failure' => $this->claudeFallbackFailure,
            'extra_snippet_signals' => $this->extraSnippetSignals,
            'final_source_strategy' => $this->finalSourceStrategy,
            'haikuCalls' => $snapshot['haikuCalls'],
            'braveSearchCalls' => $snapshot['braveSearchCalls'],
            'htmlFetchCalls' => $snapshot['htmlFetchCalls'],
            'claudeWebSearchCalls' => $snapshot['claudeWebSearchCalls'],
            'finalCandidateCount' => $this->finalCandidateCount,
            'final_status' => $this->finalStatus,
            'stopped_reason' => $this->stoppedReason,
            'stoppedReason' => $this->stoppedReason,
            'fallbackReasons' => $this->fallbackReasons,
            'duration_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
            'durationMs' => (int) round((microtime(true) - $this->startedAt) * 1000),
        ];
    }

    public function log(): void
    {
        error_log('[ai_web_search] ' . json_encode($this->toArray(), JSON_UNESCAPED_UNICODE));
    }
}
