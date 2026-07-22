<?php

declare(strict_types=1);

/**
 * AI Web 検索オーケストレーター。
 *
 * フロー:
 * 1. Claude Haiku で検索計画（1回）
 * 2. 目的の異なる Brave 検索をすべて実行（最大4回・HTML取得なし）
 * 3. 結果をマージして一度だけランキングし、HTML取得計画を作成
 * 4. 必要なら公式カタログ探索で詳細URLを補完
 * 5. HTML から複数バリアント抽出（最大8URL・枠分け）
 * 6. Brave を安全に自動確定できない場合のみ Claude Web Search（AI_WEB_SEARCH_PROVIDER=auto 時）
 *
 * AI_WEB_SEARCH_PROVIDER:
 * - auto: 上記フロー
 * - brave_only: Claude フォールバックなし
 * - claude_only: 本クラスは使わず CalorieEstimateService が Claude Web Search 直
 */
final class AiWebSearchService
{
    /**
     * @param callable(string, string): array<string, mixed>|null $claudeWebSearchFallback
     */
    public function __construct(
        private readonly FoodWebSearchPlanService $planService = new FoodWebSearchPlanService(),
        private readonly NutritionSearchQueryBuilder $queryBuilder = new NutritionSearchQueryBuilder(),
        private readonly BraveSearchService $braveSearch = new BraveSearchService(),
        private readonly WebSearchUrlRanker $urlRanker = new WebSearchUrlRanker(),
        private readonly NutritionPageExtractor $pageExtractor = new NutritionPageExtractor(),
        private readonly NutritionPageVariantExtractor $variantExtractor = new NutritionPageVariantExtractor(),
        private readonly FoodVariantAnalyzer $variantAnalyzer = new FoodVariantAnalyzer(),
        private readonly WebSearchResultCache $cache = new WebSearchResultCache(),
        private readonly OfficialSiteBrandResolver $officialSiteBrandResolver = new OfficialSiteBrandResolver(),
        private $claudeWebSearchFallback = null,
        private readonly string $searchProvider = AiWebSearchProvider::AUTO,
        private readonly FoodSearchSubjectNormalizer $subjectNormalizer = new FoodSearchSubjectNormalizer(),
        private readonly HtmlFetchPlanBuilder $htmlFetchPlanBuilder = new HtmlFetchPlanBuilder(),
        private readonly OfficialPageDiscoveryService $officialPageDiscovery = new OfficialPageDiscoveryService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $userInput, string $apiKey): array
    {
        $trimmed = trim($userInput);
        $budget = new WebSearchBudget();
        $diagnostics = new WebSearchDiagnostics($trimmed, $budget);
        $diagnostics->setSearchProvider($this->searchProvider);

        $cached = $this->cache->get($trimmed, provider: $this->searchProvider);
        if ($cached !== null && isset($cached['response']) && is_array($cached['response'])) {
            $diagnostics->setCacheHit(true);
            $diagnostics->addFallbackReason('cache_hit');
            $diagnostics->setStoppedReason('single_candidate_confirmed');
            $diagnostics->setFinalStatus((string) ($cached['response']['web_search_status'] ?? 'confirmed'));
            $diagnostics->log();

            return $cached['response'];
        }

        $subject = $this->subjectNormalizer->normalize($trimmed);

        $plan = $this->planService->createPlan($trimmed, $apiKey, $budget);
        if ($plan === null) {
            $plan = FoodWebSearchPlan::fallbackFromSubject($subject, $this->variantAnalyzer);
            $diagnostics->addFallbackReason('plan_parse_failed');
        }

        if ($plan === 'not_food') {
            $diagnostics->setStoppedReason('fallback');
            $diagnostics->setFinalStatus('not_found');
            $diagnostics->log();
            throw new RuntimeException('カロリーを推定できませんでした。');
        }

        $plan = $this->alignPlanWithSubject($plan, $subject);
        $diagnostics->setPlan($plan);

        // 一時無効: 自炊判定でも AI Web 検索を続ける
        if ($plan->searchMode === 'no_web_search') {
            $plan = new FoodWebSearchPlan(
                isFood: $plan->isFood,
                normalizedProductName: $plan->normalizedProductName !== ''
                    ? $plan->normalizedProductName
                    : $subject->productName,
                brandName: $plan->brandName ?? $subject->brandName,
                productType: $plan->productType === 'homemade_food' ? 'prepared_food' : $plan->productType,
                likelyHasVariants: $plan->likelyHasVariants,
                variantDimension: $plan->variantDimension,
                expectedLabels: $plan->expectedLabels,
                variantConfidence: $plan->variantConfidence,
                searchMode: 'single_product',
                queryTerms: $plan->queryTerms !== [] ? $plan->queryTerms : ['カロリー', '栄養成分'],
            );
            $diagnostics->addFallbackReason('homemade_guard_disabled');
            $diagnostics->setPlan($plan);
        }

        $braveOutcome = $this->collectSearchCandidates($trimmed, $plan, $budget, $diagnostics, $subject);
        $acceptedCandidates = $braveOutcome['accepted'];
        $confirmationCandidates = $braveOutcome['confirmation'];
        $diagnostics->setCandidateCounts(
            count($acceptedCandidates),
            count($confirmationCandidates),
            $braveOutcome['rejected_count'] ?? 0,
        );

        $inputAnalysis = $this->variantAnalyzer->analyzeInput(
            $plan->normalizedProductName !== '' ? $plan->normalizedProductName : $trimmed,
        );

        if ($this->canAutoConfirmBraveOutcome($acceptedCandidates, $plan, $inputAnalysis)) {
            $response = $this->formatResponse($trimmed, $plan, $acceptedCandidates, $diagnostics);
            $this->maybeCache($trimmed, $plan, $response);
            $diagnostics->setFinalStatus((string) ($response['web_search_status'] ?? 'confirmed'));
            $diagnostics->setClaudeFallbackSkipReason('brave_auto_confirmed');
            $diagnostics->setFinalSourceStrategy($this->inferSourceStrategy($acceptedCandidates) ?? 'brave_html');
            $diagnostics->log();

            return $response;
        }

        $claudeOutcome = null;
        if ($this->shouldRunClaudeFallback($braveOutcome, $plan, $budget, $inputAnalysis)) {
            $budget->recordClaudeWebSearch();
            $diagnostics->setClaudeFallbackRan(true);
            $diagnostics->addFallbackReason('claude_web_search');
            try {
                $claudeOutcome = ($this->claudeWebSearchFallback)($trimmed, $apiKey);
                if (!is_array($claudeOutcome) || $claudeOutcome === []) {
                    $claudeOutcome = null;
                    $diagnostics->addFallbackReason('claude_web_search_rejected');
                } else {
                    $claudeMeta = $claudeOutcome['_claude_meta'] ?? null;
                    if (is_array($claudeMeta)) {
                        $diagnostics->setClaudeStopReason(
                            isset($claudeMeta['stop_reason']) ? (string) $claudeMeta['stop_reason'] : null,
                        );
                        $diagnostics->setClaudePauseTurnContinuations(
                            (int) ($claudeMeta['pause_turn_continuations'] ?? 0),
                        );
                        if (isset($claudeMeta['claude_status'])) {
                            $diagnostics->setClaudeStatus((string) $claudeMeta['claude_status']);
                        } elseif (isset($claudeOutcome['claude_status'])) {
                            $diagnostics->setClaudeStatus((string) $claudeOutcome['claude_status']);
                        }
                        if (($claudeMeta['claude_not_food_contract_violation'] ?? false) === true) {
                            $diagnostics->setClaudeNotFoodContractViolation(true);
                            $diagnostics->addFallbackReason('claude_not_food_contract_violation');
                        }
                        unset($claudeOutcome['_claude_meta']);
                    }
                    if (($claudeOutcome['web_search_status'] ?? '') === 'not_found') {
                        $diagnostics->setClaudeStatus(
                            (string) ($claudeOutcome['claude_status'] ?? 'not_found'),
                        );
                        $claudeOutcome = null;
                        $diagnostics->addFallbackReason('claude_web_search_not_found');
                    }
                }
            } catch (Throwable $exception) {
                $claudeOutcome = null;
                $diagnostics->recordClaudeFallbackFailure($exception);
            }
        } else {
            $diagnostics->setClaudeFallbackSkipReason(
                $this->explainClaudeFallbackSkip($braveOutcome, $plan, $budget),
            );
        }

        if ($claudeOutcome !== null && $this->canAutoConfirmClaudeOutcome($claudeOutcome)) {
            $response = $this->formatClaudeConfirmedOutcome($claudeOutcome);
            $this->maybeCache($trimmed, $plan, $response);
            $diagnostics->setFinalStatus('confirmed');
            $diagnostics->setStoppedReason('fallback');
            $diagnostics->setClaudeSourceUrl(
                isset($claudeOutcome['source_url']) ? (string) $claudeOutcome['source_url'] : null,
            );
            $diagnostics->setFinalCandidateCount(1);
            $diagnostics->log();

            return $response;
        }

        $mergedConfirmationCandidates = $this->mergeBraveAndClaudeConfirmationCandidates(
            $confirmationCandidates,
            $acceptedCandidates,
            $claudeOutcome,
            $plan,
        );

        if ($mergedConfirmationCandidates !== []) {
            $response = $this->formatConfirmationOnlyResponse($plan, $mergedConfirmationCandidates, $diagnostics);
            $diagnostics->setFinalStatus('needs_variant_confirmation');
            $diagnostics->log();

            return $response;
        }

        $response = [
            'web_search_status' => 'estimated_fallback',
            'message_code' => 'WEB_RESULT_NOT_FOUND',
            'allow_retry' => false,
        ];
        $diagnostics->setFinalCandidateCount(0);
        $diagnostics->setStoppedReason('no_candidates');
        $diagnostics->setFinalStatus('not_found');
        $diagnostics->log();

        return $response;
    }

    /**
     * @param array{accepted: list<array<string, mixed>>, confirmation: list<array<string, mixed>>} $braveCandidates
     * @param array<string, mixed>|null $inputAnalysis
     */
    private function shouldRunClaudeFallback(
        array $braveCandidates,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
        ?array $inputAnalysis = null,
    ): bool {
        if (!AiWebSearchProvider::allowsClaudeFallback($this->searchProvider)) {
            return false;
        }

        if (!$budget->canClaudeWebSearch()) {
            return false;
        }

        if ($this->claudeWebSearchFallback === null) {
            return false;
        }

        $accepted = $braveCandidates['accepted'] ?? [];
        $inputAnalysis ??= $this->variantAnalyzer->analyzeInput($plan->normalizedProductName);

        return !$this->canAutoConfirmBraveOutcome($accepted, $plan, $inputAnalysis);
    }

    /**
     * @param list<array<string, mixed>> $accepted
     * @param array<string, mixed> $inputAnalysis
     */
    private function canAutoConfirmBraveOutcome(
        array $accepted,
        FoodWebSearchPlan $plan,
        array $inputAnalysis,
    ): bool {
        if ($accepted === []) {
            return false;
        }

        if (!$this->canAutoConfirm($inputAnalysis, $accepted, $plan)) {
            return false;
        }

        $first = $accepted[0];
        if ((int) ($first['kcal'] ?? 0) <= 0) {
            return false;
        }

        if (($first['identity_confidence'] ?? '') !== 'high') {
            return false;
        }

        if (($first['verification_confidence'] ?? 'medium') === 'low') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $claudeOutcome
     */
    private function canAutoConfirmClaudeOutcome(array $claudeOutcome): bool
    {
        if (($claudeOutcome['needs_confirmation'] ?? false) === true) {
            return false;
        }

        if ((int) ($claudeOutcome['kcal'] ?? 0) <= 0) {
            return false;
        }

        if (($claudeOutcome['identity_confidence'] ?? '') !== 'high') {
            return false;
        }

        if (($claudeOutcome['verification_confidence'] ?? 'medium') === 'low') {
            return false;
        }

        $status = (string) ($claudeOutcome['web_search_status'] ?? '');
        if ($status !== '' && $status !== 'confirmed') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $claudeOutcome
     * @return array<string, mixed>
     */
    private function formatClaudeConfirmedOutcome(array $claudeOutcome): array
    {
        $response = $claudeOutcome;
        $response['needs_confirmation'] = false;
        $response['web_search_status'] = 'confirmed';
        if (!isset($response['source']) || $response['source'] === '') {
            $response['source'] = 'claude_web_search';
        }

        return $response;
    }

    /**
     * @param array{accepted?: list<array<string, mixed>>, confirmation?: list<array<string, mixed>>} $braveOutcome
     */
    private function explainClaudeFallbackSkip(
        array $braveOutcome,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
    ): string {
        if (!AiWebSearchProvider::allowsClaudeFallback($this->searchProvider)) {
            return 'provider_disallows_claude_fallback';
        }
        if (!$budget->canClaudeWebSearch()) {
            return 'claude_budget_exhausted';
        }
        if ($this->claudeWebSearchFallback === null) {
            return 'claude_fallback_not_configured';
        }

        $accepted = $braveOutcome['accepted'] ?? [];
        $inputAnalysis = $this->variantAnalyzer->analyzeInput($plan->normalizedProductName);
        if ($this->canAutoConfirmBraveOutcome($accepted, $plan, $inputAnalysis)) {
            return 'brave_auto_confirmed';
        }

        return 'unknown';
    }

    /**
     * @param FoodWebSearchPlan $plan
     * @param array<string, mixed> $response
     */
    private function maybeCache(string $userInput, FoodWebSearchPlan $plan, array $response): void
    {
        if (!$this->cache->shouldCacheResponse($response)) {
            return;
        }

        $this->cache->put($userInput, [
            'plan' => $plan->toArray(),
            'response' => $response,
        ], provider: $this->searchProvider);
    }

    private function alignPlanWithSubject(FoodWebSearchPlan $plan, FoodSearchSubject $subject): FoodWebSearchPlan
    {
        $brandName = $plan->brandName;
        if (($brandName === null || trim($brandName) === '') && $subject->brandName !== null) {
            $brandName = $subject->brandName;
        }

        $productName = $plan->normalizedProductName;
        if ($subject->productName !== '') {
            if (
                $productName === ''
                || ($brandName !== null && str_contains($productName, $brandName))
                || ($subject->brandName !== null && str_contains($productName, $subject->brandName))
            ) {
                $productName = $subject->productName;
            }
        }

        $analysis = $this->variantAnalyzer->analyzeInput($productName !== '' ? $productName : $subject->rawInput);
        $likelyHasVariants = $plan->likelyHasVariants;
        // 具体的単品でバリアント根拠が無い場合は false に揃える
        if (
            ($analysis['variant_risk'] ?? 'low') === 'low'
            && ($analysis['has_explicit_variant'] ?? false) === false
            && $plan->searchMode === 'single_product'
        ) {
            $likelyHasVariants = false;
        }

        if (
            $brandName === $plan->brandName
            && $productName === $plan->normalizedProductName
            && $likelyHasVariants === $plan->likelyHasVariants
        ) {
            return $plan;
        }

        return new FoodWebSearchPlan(
            isFood: $plan->isFood,
            normalizedProductName: $productName,
            brandName: $brandName,
            productType: $plan->productType,
            likelyHasVariants: $likelyHasVariants,
            variantDimension: $plan->variantDimension,
            expectedLabels: $plan->expectedLabels,
            variantConfidence: $plan->variantConfidence,
            searchMode: $plan->searchMode,
            queryTerms: $plan->queryTerms,
        );
    }

    /**
     * @return array{accepted: list<array<string, mixed>>, confirmation: list<array<string, mixed>>, rejected_count?: int}
     */
    private function collectSearchCandidates(
        string $userInput,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
        WebSearchDiagnostics $diagnostics,
        ?FoodSearchSubject $subject = null,
    ): array {
        $subject ??= $this->subjectNormalizer->normalize(
            $userInput !== '' ? $userInput : trim(($plan->brandName ?? '') . ' ' . $plan->normalizedProductName),
        );

        $queries = $this->queryBuilder->buildSearchQueries($userInput, $plan);
        $diagnostics->setGeneratedQueries($queries);

        /** @var array<string, array{title: string, url: string, description: string, extra_snippets?: list<string>, fetch_source?: string}> $mergedResults */
        $mergedResults = [];
        $executed = [];
        $skipped = [];

        // --- Phase 1: Brave 検索のみ（HTML取得しない） ---
        foreach ($queries as $query) {
            if (!$this->shouldContinueBraveSearch($budget, $plan, true)) {
                break;
            }
            if (!$budget->shouldExecuteBraveQuery($query)) {
                $skipped[] = $query;
                continue;
            }

            $search = $this->braveSearch->search($query, 10);
            $budget->recordBraveSearch($query);
            $executed[] = $query;
            if (!$search['ok']) {
                $diagnostics->setResultsPerQuery($query, 0);
                continue;
            }

            $diagnostics->setResultsPerQuery($query, count($search['results']));
            foreach ($search['results'] as $result) {
                $url = $result['url'];
                if (!isset($mergedResults[$url])) {
                    $mergedResults[$url] = $result;
                    continue;
                }
                // extra_snippets を後続クエリから補完
                $existingSnippets = $mergedResults[$url]['extra_snippets'] ?? [];
                $incomingSnippets = $result['extra_snippets'] ?? [];
                if (is_array($incomingSnippets) && $incomingSnippets !== []) {
                    $mergedResults[$url]['extra_snippets'] = array_values(array_unique(array_merge(
                        is_array($existingSnippets) ? $existingSnippets : [],
                        $incomingSnippets,
                    )));
                }
                if (trim((string) ($mergedResults[$url]['description'] ?? '')) === '' && trim((string) ($result['description'] ?? '')) !== '') {
                    $mergedResults[$url]['description'] = $result['description'];
                }
            }
        }

        $diagnostics->setExecutedQueries($executed);
        $diagnostics->setSkippedDuplicateQueries($skipped);
        $diagnostics->setSearchPhaseCompleted(true);
        $diagnostics->setMergedUrlCount(count($mergedResults));
        $diagnostics->setBraveResultCount(count($mergedResults));
        $this->recordExtraSnippetSignals($mergedResults, $plan, $diagnostics);

        // --- Phase 1.5: 公式ページ探索（検索が公式詳細を逃した場合） ---
        if ($this->officialPageDiscovery->shouldRun($subject, array_values($mergedResults))) {
            $discoveryEnv = new DiscoveryEnvironment(searchResults: array_values($mergedResults));
            $discovery = $this->officialPageDiscovery->discoverWithDiagnostics($subject, $discoveryEnv);
            $diagnostics->applyOfficialDiscoveryDiagnostics($discovery['diagnostics']);
            foreach ($discovery['candidates'] as $catalogCandidate) {
                if ($catalogCandidate->hasDistinctCoreConflict) {
                    continue;
                }
                $result = $catalogCandidate->toSearchResult();
                $url = $result['url'];
                if (!isset($mergedResults[$url])) {
                    $mergedResults[$url] = $result;
                } else {
                    $mergedResults[$url]['fetch_source'] = $result['fetch_source'] ?? 'official_catalog';
                    if (trim((string) ($mergedResults[$url]['title'] ?? '')) === '' && ($result['title'] ?? '') !== '') {
                        $mergedResults[$url]['title'] = $result['title'];
                    }
                }
            }
            $diagnostics->setMergedUrlCount(count($mergedResults));
            $diagnostics->setBraveResultCount(count($mergedResults));
        } else {
            $diagnostics->applyOfficialDiscoveryDiagnostics([
                'official_discovery_ran' => false,
                'official_profile_source' => null,
                'official_profile_domain' => null,
                'enabled_discovery_strategies' => [],
                'executed_discovery_strategies' => [],
                'merged_official_candidates' => 0,
                'discovery_budget_exhausted' => false,
                'site_adapter_used' => false,
                'final_discovery_source' => null,
            ]);
        }

        if ($mergedResults === []) {
            $diagnostics->setStoppedReason('no_candidates');
            $diagnostics->setHtmlBudgetRemaining($budget->remainingHtmlFetches());

            return ['accepted' => [], 'confirmation' => [], 'rejected_count' => 0];
        }

        // --- Phase 2: 一度だけランキング → HTML取得計画 → 取得 ---
        $ranked = $this->urlRanker->rank(
            array_values($mergedResults),
            $plan->normalizedProductName,
            $plan->brandName,
            $plan->searchMode,
        );
        // fetch_source を ranked に引き継ぐ
        foreach ($ranked as $index => $entry) {
            $url = $entry['url'];
            if (isset($mergedResults[$url]['fetch_source'])) {
                $ranked[$index]['fetch_source'] = $mergedResults[$url]['fetch_source'];
            }
            if (isset($mergedResults[$url]['extra_snippets']) && is_array($mergedResults[$url]['extra_snippets'])) {
                $ranked[$index]['extra_snippets'] = $mergedResults[$url]['extra_snippets'];
            }
        }
        $diagnostics->setUrlRanking($ranked);

        $exactCount = 0;
        $officialCount = 0;
        foreach ($ranked as $entry) {
            $titleMatch = $entry['title_match'] ?? [];
            if (is_array($titleMatch) && $this->htmlFetchPlanBuilder->isExactOrVeryHighMatch($titleMatch)
                && ($titleMatch['has_distinct_cores'] ?? false) !== true) {
                $exactCount++;
            }
            if ($this->officialSiteBrandResolver->isOfficialUrl($entry['url'], $userInput, $plan->brandName)) {
                $officialCount++;
            }
        }
        $diagnostics->setExactTitleCandidateCount($exactCount);
        $diagnostics->setOfficialCandidateCount($officialCount);

        $fetchPlan = $this->htmlFetchPlanBuilder->build(
            $ranked,
            $plan->normalizedProductName,
            $plan->brandName,
            $userInput,
            WebSearchBudget::MAX_HTML_FETCHES,
        );
        $diagnostics->setHtmlFetchPlan($fetchPlan);
        $diagnostics->setHtmlBudgetRemaining(max(0, WebSearchBudget::MAX_HTML_FETCHES - count($fetchPlan)));

        $extracted = $this->extractFromFetchPlan(
            $fetchPlan,
            $plan,
            $budget,
            $userInput,
            $diagnostics,
        );

        $accepted = $extracted['accepted'];
        $confirmation = $this->finalizeConfirmationCandidates($extracted['confirmation']);
        $rejectedCount = $extracted['rejected_count'];
        $diagnostics->setHtmlExtractedCandidateCount(count($accepted) + count($confirmation));

        if ($this->hasStrongAcceptedCandidate($accepted, $plan)) {
            $diagnostics->setStoppedReason('sufficient_variants');
            $diagnostics->setFinalSourceStrategy($this->inferSourceStrategy($accepted));
        } else {
            $diagnostics->setStoppedReason(
                $accepted === [] && $confirmation === [] ? 'no_candidates' : 'search_budget_exhausted',
            );
            if ($confirmation !== []) {
                $diagnostics->setFinalSourceStrategy($this->inferSourceStrategy($confirmation));
            }
        }

        $diagnostics->setHtmlBudgetRemaining($budget->remainingHtmlFetches());

        return [
            'accepted' => $accepted,
            'confirmation' => $confirmation,
            'rejected_count' => $rejectedCount,
        ];
    }

    /**
     * Brave 検索を続ける条件（HTML予算は見ない）。
     *
     * - 強い自動確定候補がまだない
     * - Brave検索予算が残っている
     * - 検索フェーズでは HTML を取得しないため、通常は全クエリを実行する
     */
    private function shouldContinueBraveSearch(
        WebSearchBudget $budget,
        FoodWebSearchPlan $plan,
        bool $searchPhaseOnly = false,
        array $accepted = [],
    ): bool {
        if (!$searchPhaseOnly && $this->hasStrongAcceptedCandidate($accepted, $plan)) {
            return false;
        }

        return $budget->canBraveSearch();
    }

    /**
     * @param array<string, array{title: string, url: string, description: string, extra_snippets?: list<string>}> $mergedResults
     */
    private function recordExtraSnippetSignals(
        array $mergedResults,
        FoodWebSearchPlan $plan,
        WebSearchDiagnostics $diagnostics,
    ): void {
        $productName = mb_strtolower($plan->normalizedProductName);
        foreach ($mergedResults as $result) {
            $snippets = $result['extra_snippets'] ?? [];
            if (!is_array($snippets) || $snippets === []) {
                continue;
            }
            $joined = mb_strtolower(implode(' ', $snippets));
            $hasProduct = $productName !== '' && str_contains($joined, $productName);
            $hasCalorie = str_contains($joined, 'kcal') || str_contains($joined, 'カロリー') || str_contains($joined, 'エネルギー');
            $hasNutrition = str_contains($joined, '栄養') || str_contains($joined, 'たんぱく') || str_contains($joined, '脂質');
            if ($hasProduct || $hasCalorie || $hasNutrition) {
                $diagnostics->addExtraSnippetSignal([
                    'url' => (string) $result['url'],
                    'has_product_name' => $hasProduct,
                    'has_calorie' => $hasCalorie,
                    'has_nutrition' => $hasNutrition,
                ]);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function inferSourceStrategy(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (($candidate['source_type'] ?? '') === 'official_catalog' || (($candidate['fetch_reason'] ?? '') === 'official_catalog')) {
                return 'official_catalog';
            }
            if (($candidate['is_official_url'] ?? false) === true) {
                return 'official_html';
            }
        }
        foreach ($candidates as $candidate) {
            if (($candidate['source'] ?? '') === 'brave_html') {
                return 'third_party_html';
            }
        }

        return null;
    }

    /**
     * @param list<array{url: string, title: string, description: string, reason: string, score?: int, title_match?: array<string, mixed>}> $fetchPlan
     * @return array{accepted: list<array<string, mixed>>, confirmation: list<array<string, mixed>>, rejected_count: int}
     */
    private function extractFromFetchPlan(
        array $fetchPlan,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
        string $userInput,
        ?WebSearchDiagnostics $diagnostics = null,
    ): array {
        $accepted = [];
        $confirmation = [];
        $rejectedCount = 0;

        foreach ($fetchPlan as $entry) {
            $url = $entry['url'];
            if (!$budget->canFetchHtml($url)) {
                continue;
            }

            $html = $this->pageExtractor->fetchPageHtml($url);
            $budget->recordHtmlFetch($url);
            if ($html === null) {
                $diagnostics?->recordHtmlFetchFailure();
                continue;
            }
            $diagnostics?->recordHtmlFetchSuccess();

            $extracted = $this->variantExtractor->extractFromHtml(
                $html,
                $plan->normalizedProductName,
                $plan->brandName,
                $plan->variantDimension,
                $plan->expectedLabels,
                $url,
            );

            foreach ($extracted as $item) {
                $candidate = $this->toVerifiedCandidate(
                    $item,
                    [
                        'url' => $url,
                        'title' => (string) ($entry['title'] ?? ''),
                        'description' => (string) ($entry['description'] ?? ''),
                    ],
                    $userInput,
                    $plan,
                );
                $candidate['fetch_reason'] = (string) ($entry['reason'] ?? 'overall_rank');
                $this->logProductMatch($candidate);

                $decision = (string) ($item['matchDecision'] ?? ProductMatchResult::DECISION_ACCEPTED);
                if ($decision === ProductMatchResult::DECISION_NEEDS_CONFIRMATION) {
                    $confirmation[] = $candidate;
                    continue;
                }

                if ($decision === ProductMatchResult::DECISION_REJECTED) {
                    $rejectedCount++;
                    $diagnostics?->addRejectReason((string) (($item['matchReasons']['decision'] ?? null) ?: 'rejected'));
                    continue;
                }

                $accepted[] = $candidate;
            }

            if ($plan->likelyHasVariants) {
                if ($this->hasSufficientCandidates($accepted, $plan)) {
                    break;
                }
            } elseif ($this->hasStrongAcceptedCandidate($accepted, $plan)) {
                break;
            }
        }

        return [
            'accepted' => $this->dedupeVerifiedCandidates($accepted),
            'confirmation' => $confirmation,
            'rejected_count' => $rejectedCount,
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function hasUsableAcceptedCandidates(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ((int) ($candidate['kcal'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 単品検索の停止条件: accepted + identity high + core conflict なし + kcal > 0
     *
     * @param list<array<string, mixed>> $candidates
     */
    private function hasStrongAcceptedCandidate(array $candidates, FoodWebSearchPlan $plan): bool
    {
        if ($plan->likelyHasVariants) {
            return $this->hasSufficientCandidates($candidates, $plan);
        }

        foreach ($candidates as $candidate) {
            if ((int) ($candidate['kcal'] ?? 0) <= 0) {
                continue;
            }
            if (($candidate['identity_confidence'] ?? '') !== 'high') {
                continue;
            }
            if (($candidate['match_decision'] ?? ProductMatchResult::DECISION_ACCEPTED) !== ProductMatchResult::DECISION_ACCEPTED) {
                continue;
            }
            $reasons = $candidate['match_reasons'] ?? null;
            if (is_array($reasons) && ($reasons['has_distinct_cores'] ?? false) === true) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeConfirmationCandidates(array $existing, array $incoming): array
    {
        if ($incoming === []) {
            return $existing;
        }

        if ($existing === []) {
            return $incoming;
        }

        return array_merge($existing, $incoming);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function finalizeConfirmationCandidates(array $candidates): array
    {
        $prepared = [];
        foreach ($candidates as $candidate) {
            $prepared[] = array_merge($candidate, [
                'match_score' => (float) ($candidate['match_score'] ?? 0),
            ]);
        }

        return (new ProductMatchEvaluator())->finalizeConfirmationCandidates($prepared);
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeVerifiedCandidates(array $existing, array $incoming): array
    {
        if ($incoming === []) {
            return $existing;
        }

        if ($existing === []) {
            return $incoming;
        }

        return $this->dedupeVerifiedCandidates(array_merge($existing, $incoming));
    }

    /**
     * @param list<array{title: string, url: string, description: string}> $results
     * @return array{accepted: list<array<string, mixed>>, confirmation: list<array<string, mixed>>, rejected_count: int}
     */
    private function extractFromRankedUrls(
        array $results,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
        string $userInput,
        ?WebSearchDiagnostics $diagnostics = null,
    ): array {
        $ranked = $this->urlRanker->rank(
            $results,
            $plan->normalizedProductName,
            $plan->brandName,
            $plan->searchMode,
        );
        if ($diagnostics !== null) {
            $diagnostics->setUrlRanking($ranked);
        }

        $accepted = [];
        $confirmation = [];
        $rejectedCount = 0;

        foreach ($ranked as $entry) {
            if (!$budget->canFetchHtml($entry['url'])) {
                continue;
            }

            $html = $this->pageExtractor->fetchPageHtml($entry['url']);
            $budget->recordHtmlFetch($entry['url']);
            if ($html === null) {
                $diagnostics?->recordHtmlFetchFailure();
                continue;
            }
            $diagnostics?->recordHtmlFetchSuccess();

            $extracted = $this->variantExtractor->extractFromHtml(
                $html,
                $plan->normalizedProductName,
                $plan->brandName,
                $plan->variantDimension,
                $plan->expectedLabels,
                $entry['url'],
            );

            foreach ($extracted as $item) {
                $candidate = $this->toVerifiedCandidate(
                    $item,
                    $entry,
                    $userInput,
                    $plan,
                );
                $this->logProductMatch($candidate);

                $decision = (string) ($item['matchDecision'] ?? ProductMatchResult::DECISION_ACCEPTED);
                if ($decision === ProductMatchResult::DECISION_NEEDS_CONFIRMATION) {
                    $confirmation[] = $candidate;
                    continue;
                }

                if ($decision === ProductMatchResult::DECISION_REJECTED) {
                    $rejectedCount++;
                    $diagnostics?->addRejectReason((string) (($item['matchReasons']['decision'] ?? null) ?: 'rejected'));
                    continue;
                }

                $accepted[] = $candidate;
            }

            // 単品は強い accepted を得るまで公式候補の取得を続ける。
            // rejected / confirmation / identity 不足では止めない。
            if ($plan->likelyHasVariants) {
                if ($this->hasSufficientCandidates($accepted, $plan)) {
                    break;
                }
            } elseif ($this->hasStrongAcceptedCandidate($accepted, $plan)) {
                break;
            }
        }

        return [
            'accepted' => $this->dedupeVerifiedCandidates($accepted),
            'confirmation' => $confirmation,
            'rejected_count' => $rejectedCount,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array{url: string, title: string, description: string} $entry
     * @return array<string, mixed>
     */
    private function toVerifiedCandidate(
        array $item,
        array $entry,
        string $userInput,
        FoodWebSearchPlan $plan,
    ): array {
        $sourceType = (string) ($item['sourceType'] ?? 'html_text');
        $fromSingleProductPage = $sourceType === 'html_single_product';
        $htmlProductName = trim((string) ($item['productName'] ?? ''));
        $htmlBrand = trim((string) ($item['brandName'] ?? ''));

        $brandName = $plan->brandName;
        if (($brandName === null || trim($brandName) === '') && $htmlBrand !== '') {
            $brandName = $htmlBrand;
        }
        if ($brandName === null || trim($brandName) === '') {
            $brandName = $this->officialSiteBrandResolver->resolveFromUrl(
                $entry['url'],
                $entry['title'] !== '' ? $entry['title'] : null,
            );
        }

        $displayProductName = ($fromSingleProductPage && $htmlProductName !== '')
            ? $htmlProductName
            : $plan->normalizedProductName;

        $identityConfidence = $this->pageExtractor->assessProductIdentity(
            $plan->normalizedProductName,
            $displayProductName,
            $brandName,
        );

        $source = $sourceType === 'claude_web_search' ? 'claude_web_search' : 'brave_html';

        $isOfficialUrl = $this->officialSiteBrandResolver->isOfficialUrl(
            $entry['url'],
            $userInput,
            $brandName,
        );

        $verificationConfidence = (string) ($item['verificationConfidence'] ?? 'medium');
        $matchReasons = is_array($item['matchReasons'] ?? null) ? $item['matchReasons'] : [];
        $hasDistinctCores = ($matchReasons['has_distinct_cores'] ?? false) === true;
        $nameSimilarity = (float) ($matchReasons['name_similarity'] ?? 0.0);
        $hasExactName = $nameSimilarity >= 0.9
            || ($displayProductName !== '' && $plan->normalizedProductName !== ''
                && str_contains(mb_strtolower($displayProductName), mb_strtolower($plan->normalizedProductName)));

        if ($isOfficialUrl && !$hasDistinctCores) {
            $verificationConfidence = 'high';
        } elseif (!$isOfficialUrl && $hasExactName && !$hasDistinctCores && (int) ($item['kcal'] ?? 0) > 0) {
            // 第三者の完全一致＋栄養証拠は確認可能だが公式より verification を下げる
            $verificationConfidence = 'medium';
            if ($sourceType === 'html_text' || $sourceType === 'html_table') {
                $sourceType = 'third_party_exact';
            }
        }

        $candidate = [
            'product_name' => $displayProductName,
            'brand' => $brandName,
            'kcal' => (int) ($item['kcal'] ?? 0),
            'source_url' => $entry['url'],
            'source_title' => $entry['title'] !== '' ? $entry['title'] : null,
            'source' => $source,
            'source_type' => $sourceType,
            'identity_confidence' => $identityConfidence,
            'is_official_url' => $isOfficialUrl,
            'base_product_name' => $displayProductName,
            'variant_label' => $item['variantLabel'] ?? '通常サイズ',
            'variant_confidence' => 'high',
            'variant_dimension' => (string) ($item['variantDimension'] ?? $plan->variantDimension),
            'serving_weight_g' => $item['servingWeightG'] ?? null,
            'package_size' => $item['packageSize'] ?? null,
            'evidence_text' => $item['evidenceText'] ?? null,
            'verification_confidence' => $verificationConfidence,
            'fetched_at' => gmdate('c'),
            'match_decision' => $item['matchDecision'] ?? null,
            'match_score' => $item['matchScore'] ?? null,
            'match_reasons' => $item['matchReasons'] ?? null,
        ];

        return $candidate;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function logProductMatch(array $candidate): void
    {
        $reasons = $candidate['match_reasons'] ?? null;
        if (!is_array($reasons)) {
            $reasons = [
                'query_product_name' => $candidate['base_product_name'] ?? null,
                'query_brand_name' => $candidate['brand'] ?? null,
                'candidate_product_name' => $candidate['product_name'] ?? null,
                'page_title' => $candidate['source_title'] ?? null,
                'url' => $candidate['source_url'] ?? null,
                'decision' => $candidate['match_decision'] ?? null,
                'total_score' => $candidate['match_score'] ?? null,
            ];
        }

        error_log('[ai_web_search_product_match] ' . json_encode($reasons, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function formatConfirmationOnlyResponse(
        FoodWebSearchPlan $plan,
        array $candidates,
        WebSearchDiagnostics $diagnostics,
    ): array {
        $diagnostics->setFinalCandidateCount(count($candidates));
        $diagnostics->setStoppedReason('sufficient_variants');
        $diagnostics->addFallbackReason('product_match_needs_confirmation');

        return [
            'needs_confirmation' => true,
            'reason' => 'identity_ambiguous',
            'web_search_status' => 'needs_variant_confirmation',
            'product_name' => $plan->normalizedProductName,
            'variant_dimension' => $plan->variantDimension,
            'allow_manual_variant' => true,
            'allow_estimated_add' => true,
            'candidates' => array_map(
                fn (array $candidate): array => $this->formatCandidateForUi($candidate),
                $candidates,
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupeVerifiedCandidates(array $candidates): array
    {
        $byKey = [];
        foreach ($candidates as $candidate) {
            // 商品名と kcal の表記ゆれにかかわらず、同じサイズは1候補にまとめる。
            $key = $this->variantAnalyzer->buildCandidateDedupeKey($candidate);
            if (!isset($byKey[$key])) {
                $byKey[$key] = $candidate;
                continue;
            }

            $currentScore = $this->candidatePreferenceScore($byKey[$key]);
            $incomingScore = $this->candidatePreferenceScore($candidate);
            if ($incomingScore > $currentScore) {
                $byKey[$key] = $candidate;
            }
        }

        return array_values($byKey);
    }

    /**
     * @param list<array<string, mixed>> $braveConfirmation
     * @param list<array<string, mixed>> $braveAccepted
     * @param array<string, mixed>|null $claudeOutcome
     * @return list<array<string, mixed>>
     */
    private function mergeBraveAndClaudeConfirmationCandidates(
        array $braveConfirmation,
        array $braveAccepted,
        ?array $claudeOutcome,
        FoodWebSearchPlan $plan,
    ): array {
        $merged = $braveConfirmation;

        // 自動確定できなかった accepted も確認候補へ回す
        foreach ($braveAccepted as $candidate) {
            $merged[] = $candidate;
        }

        if ($claudeOutcome !== null) {
            if (($claudeOutcome['needs_confirmation'] ?? false) === true) {
                $claudeCandidates = $claudeOutcome['candidates'] ?? [];
                if (is_array($claudeCandidates)) {
                    foreach ($claudeCandidates as $candidate) {
                        if (is_array($candidate)) {
                            $merged[] = $candidate;
                        }
                    }
                }
            } elseif ((int) ($claudeOutcome['kcal'] ?? 0) > 0) {
                $merged[] = [
                    'product_name' => $claudeOutcome['product_name'] ?? $plan->normalizedProductName,
                    'brand' => $claudeOutcome['brand'] ?? null,
                    'kcal' => (int) $claudeOutcome['kcal'],
                    'source_url' => $claudeOutcome['source_url'] ?? null,
                    'source' => $claudeOutcome['source'] ?? 'claude_web_search',
                    'identity_confidence' => $claudeOutcome['identity_confidence'] ?? 'medium',
                    'verification_confidence' => $claudeOutcome['verification_confidence'] ?? 'medium',
                    'is_official_url' => isset($claudeOutcome['source_url'])
                        && $this->officialSiteBrandResolver->isOfficialUrl(
                            (string) $claudeOutcome['source_url'],
                            $plan->normalizedProductName,
                            $plan->brandName,
                        ),
                    'base_product_name' => $claudeOutcome['base_product_name'] ?? $claudeOutcome['product_name'] ?? null,
                    'variant_label' => $claudeOutcome['variant_label'] ?? '通常サイズ',
                    'package_size' => $claudeOutcome['package_size'] ?? null,
                    'match_score' => $claudeOutcome['match_score'] ?? null,
                ];
            }
        }

        return $this->finalizeConfirmationCandidates(
            $this->dedupeConfirmationCandidates($merged),
        );
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupeConfirmationCandidates(array $candidates): array
    {
        $byKey = [];
        foreach ($candidates as $candidate) {
            $key = $this->confirmationDedupeKey($candidate);
            if (!isset($byKey[$key])) {
                $byKey[$key] = $candidate;
                continue;
            }

            if ($this->candidatePreferenceScore($candidate) > $this->candidatePreferenceScore($byKey[$key])) {
                $byKey[$key] = $candidate;
            }
        }

        return array_values($byKey);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function confirmationDedupeKey(array $candidate): string
    {
        $url = $this->normalizeSourceUrl((string) ($candidate['source_url'] ?? ''));
        if ($url !== '') {
            return 'url:' . $url;
        }

        $name = mb_strtolower(trim((string) ($candidate['product_name'] ?? '')));
        $kcal = (int) ($candidate['kcal'] ?? 0);
        $package = mb_strtolower(trim((string) ($candidate['package_size'] ?? '')));
        if ($name !== '' && $kcal > 0) {
            return 'npk:' . $name . '|' . $kcal . '|' . $package;
        }

        $variant = mb_strtolower(trim((string) ($candidate['variant_label'] ?? '通常サイズ')));

        return 'nv:' . $name . '|' . $variant;
    }

    private function normalizeSourceUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return mb_strtolower($trimmed);
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        $path = rtrim($path, '/') ?: '/';

        return $host . $path;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidatePreferenceScore(array $candidate): int
    {
        $score = ($candidate['is_official_url'] ?? false) ? 100 : 0;
        $score += match ($candidate['verification_confidence'] ?? 'low') {
            'high' => 20,
            'medium' => 10,
            default => 0,
        };
        $score += match ($candidate['identity_confidence'] ?? 'low') {
            'high' => 5,
            'medium' => 2,
            default => 0,
        };
        $score += (int) round(((float) ($candidate['match_score'] ?? 0)) * 0.1);
        if ((int) ($candidate['kcal'] ?? 0) > 0 && ($candidate['source_type'] ?? '') === 'html_single_product') {
            $score += 15;
        }

        return $score;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function hasSufficientCandidates(array $candidates, FoodWebSearchPlan $plan): bool
    {
        $valid = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => (int) ($candidate['kcal'] ?? 0) > 0,
        ));

        if ($valid === []) {
            return false;
        }

        if (!$plan->likelyHasVariants) {
            return count($valid) >= 1
                && ($valid[0]['identity_confidence'] ?? '') === 'high'
                && ($valid[0]['verification_confidence'] ?? '') !== 'low';
        }

        $variantKeys = [];
        foreach ($valid as $candidate) {
            $variantKeys[$this->variantAnalyzer->buildCandidateDedupeKey($candidate)] = true;
        }

        return count($variantKeys) >= 2;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function formatResponse(
        string $userInput,
        FoodWebSearchPlan $plan,
        array $candidates,
        WebSearchDiagnostics $diagnostics,
    ): array {
        $inputAnalysis = $this->variantAnalyzer->analyzeInput($userInput);
        $diagnostics->setFinalCandidateCount(count($candidates));

        if ($this->canAutoConfirm($inputAnalysis, $candidates, $plan)) {
            $diagnostics->setStoppedReason('single_candidate_confirmed');
            $single = $this->formatSingleResult($candidates[0]);

            return array_merge($single, [
                'web_search_status' => 'confirmed',
                'variant_dimension' => $plan->variantDimension,
            ]);
        }

        $reason = $this->hasDistinctVerifiedVariants($candidates)
            ? 'variant_ambiguous'
            : 'identity_ambiguous';

        $diagnostics->setStoppedReason('sufficient_variants');

        return [
            'needs_confirmation' => true,
            'reason' => $reason,
            'web_search_status' => 'needs_variant_confirmation',
            'product_name' => $plan->normalizedProductName,
            'variant_dimension' => $plan->variantDimension,
            'allow_manual_variant' => true,
            'allow_estimated_add' => true,
            'candidates' => array_map(fn (array $candidate): array => $this->formatCandidateForUi($candidate), $candidates),
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function formatSingleResult(array $candidate): array
    {
        $result = [
            'kcal' => (int) $candidate['kcal'],
            'confidence' => ($candidate['identity_confidence'] ?? 'medium') === 'high' ? 'high' : 'medium',
            'product_name' => (string) $candidate['product_name'],
            'source' => (string) ($candidate['source'] ?? 'brave_html'),
            'identity_confidence' => (string) ($candidate['identity_confidence'] ?? 'medium'),
            'needs_confirmation' => false,
            'base_product_name' => $candidate['base_product_name'] ?? null,
            'variant_label' => $candidate['variant_label'] ?? null,
            'variant_confidence' => $candidate['variant_confidence'] ?? null,
            'serving_weight_g' => $candidate['serving_weight_g'] ?? null,
            'package_size' => $candidate['package_size'] ?? null,
        ];

        if (isset($candidate['verification_confidence']) && $candidate['verification_confidence'] !== '') {
            $result['verification_confidence'] = $candidate['verification_confidence'];
        }

        // Brave / Claude web検索の自動確定でも、候補確認時と同じく参照元を必ず返す。
        foreach (['brand', 'source_url', 'source_title'] as $field) {
            if (($candidate[$field] ?? null) !== null && ($candidate[$field] ?? '') !== '') {
                $result[$field] = $candidate[$field];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function formatCandidateForUi(array $candidate): array
    {
        $item = [
            'product_name' => $candidate['product_name'],
            'kcal' => $candidate['kcal'],
            'source' => $candidate['source'],
            'identity_confidence' => $candidate['identity_confidence'],
        ];

        foreach ([
            'brand',
            'source_url',
            'source_title',
            'base_product_name',
            'variant_label',
            'variant_confidence',
            'variant_dimension',
            'serving_weight_g',
            'package_size',
            'evidence_text',
            'verification_confidence',
            'source_type',
        ] as $field) {
            if (($candidate[$field] ?? null) !== null && ($candidate[$field] ?? '') !== '') {
                $item[$field] = $candidate[$field];
            }
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $inputAnalysis
     * @param list<array<string, mixed>> $candidates
     */
    private function canAutoConfirm(array $inputAnalysis, array $candidates, FoodWebSearchPlan $plan): bool
    {
        if ($plan->likelyHasVariants && $this->hasDistinctVerifiedVariants($candidates)) {
            return false;
        }

        return $this->variantAnalyzer->canAutoConfirm($inputAnalysis, $candidates);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function hasDistinctVerifiedVariants(array $candidates): bool
    {
        $mapped = array_map(
            static fn (array $candidate): array => [
                'variant_label' => $candidate['variant_label'] ?? '通常サイズ',
                'base_product_name' => $candidate['base_product_name'] ?? $candidate['product_name'] ?? '',
                'kcal' => $candidate['kcal'] ?? 0,
            ],
            $candidates,
        );

        return $this->variantAnalyzer->hasDistinctVariants($mapped);
    }
}
