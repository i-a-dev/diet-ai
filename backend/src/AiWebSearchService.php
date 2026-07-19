<?php

declare(strict_types=1);

/**
 * AI Web 検索オーケストレーター。
 *
 * 変更後フロー:
 * 1. Claude Haiku で検索計画（1回）
 * 2. 固定テンプレートで Brave 検索（通常1〜2回、最大4回）
 * 3. 上位URLの HTML から複数バリアント抽出（最大8URL）
 * 4. 十分な候補が取れたら早期終了
 * 5. 候補0件のみ Claude Web Search を最大1回（AI_WEB_SEARCH_PROVIDER=auto 時）
 *
 * AI_WEB_SEARCH_PROVIDER:
 * - auto: 上記フロー
 * - brave_only: 5 をスキップ（0件は estimated_fallback）
 * - claude_only: 本クラスは使わず CalorieEstimateService が Claude Web Search 直
 *
 * 変更前フロー（廃止）:
 * - 固定 L/M/S を6回 Brave 検索
 * - Brave 後に Claude 商品名候補生成 → 候補ごと再検索
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
            $diagnostics->addFallbackReason('cache_hit');
            $diagnostics->setStoppedReason('single_candidate_confirmed');
            $diagnostics->log();

            return $cached['response'];
        }

        $plan = $this->planService->createPlan($trimmed, $apiKey, $budget);
        if ($plan === null) {
            $plan = FoodWebSearchPlan::fallbackFromInput($trimmed, $this->variantAnalyzer);
            $diagnostics->addFallbackReason('plan_parse_failed');
        }

        if ($plan === 'not_food') {
            $diagnostics->setStoppedReason('fallback');
            $diagnostics->log();
            throw new RuntimeException('カロリーを推定できませんでした。');
        }

        $diagnostics->setPlan($plan);

        // 一時無効: 自炊判定でも AI Web 検索を続ける
        if ($plan->searchMode === 'no_web_search') {
            // $response = [
            //     'web_search_status' => 'no_web_search',
            //     'fallback_to_existing_estimate' => true,
            // ];
            // $diagnostics->setStoppedReason('no_web_search');
            // $diagnostics->log();
            //
            // return $response;
            $plan = new FoodWebSearchPlan(
                isFood: $plan->isFood,
                normalizedProductName: $plan->normalizedProductName !== ''
                    ? $plan->normalizedProductName
                    : $trimmed,
                brandName: $plan->brandName,
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

        $searchCandidates = $this->collectSearchCandidates($trimmed, $plan, $budget, $diagnostics);
        $acceptedCandidates = $searchCandidates['accepted'];
        $confirmationCandidates = $searchCandidates['confirmation'];

        if (
            $acceptedCandidates === []
            && $confirmationCandidates === []
            && AiWebSearchProvider::allowsClaudeFallback($this->searchProvider)
            && $budget->canClaudeWebSearch()
            && $this->claudeWebSearchFallback !== null
        ) {
            $budget->recordClaudeWebSearch();
            $fallbackResult = ($this->claudeWebSearchFallback)($trimmed, $apiKey);
            if (is_array($fallbackResult) && $fallbackResult !== []) {
                $diagnostics->addFallbackReason('claude_web_search');
                $diagnostics->setStoppedReason('fallback');
                $diagnostics->log();

                return $fallbackResult;
            }
        }

        if ($acceptedCandidates === [] && $confirmationCandidates === []) {
            $response = [
                'web_search_status' => 'estimated_fallback',
                'message_code' => 'WEB_RESULT_NOT_FOUND',
                'allow_retry' => false,
            ];
            $diagnostics->setFinalCandidateCount(0);
            $diagnostics->setStoppedReason('no_candidates');
            $diagnostics->log();

            return $response;
        }

        if ($acceptedCandidates === [] && $confirmationCandidates !== []) {
            $response = $this->formatConfirmationOnlyResponse($plan, $confirmationCandidates, $diagnostics);
            $this->cache->put($trimmed, [
                'plan' => $plan->toArray(),
                'response' => $response,
            ], provider: $this->searchProvider);
            $diagnostics->log();

            return $response;
        }

        $response = $this->formatResponse($trimmed, $plan, $acceptedCandidates, $diagnostics);
        $this->cache->put($trimmed, [
            'plan' => $plan->toArray(),
            'response' => $response,
        ], provider: $this->searchProvider);
        $diagnostics->log();

        return $response;
    }

    /**
     * @return array{accepted: list<array<string, mixed>>, confirmation: list<array<string, mixed>>}
     */
    private function collectSearchCandidates(
        string $userInput,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
        WebSearchDiagnostics $diagnostics,
    ): array {
        $queries = $this->queryBuilder->build($plan, $userInput);
        /** @var array<string, array{title: string, url: string, description: string}> $mergedResults */
        $mergedResults = [];
        $accepted = [];
        $confirmation = [];

        foreach ($queries as $query) {
            if (!$this->shouldContinueBraveSearch($accepted, $budget)) {
                break;
            }
            if (!$budget->shouldExecuteBraveQuery($query)) {
                continue;
            }

            $search = $this->braveSearch->search($query, 10);
            $budget->recordBraveSearch($query);
            if (!$search['ok']) {
                continue;
            }

            foreach ($search['results'] as $result) {
                $url = $result['url'];
                if (!isset($mergedResults[$url])) {
                    $mergedResults[$url] = $result;
                }
            }

            $extracted = $this->extractFromRankedUrls(
                array_values($mergedResults),
                $plan,
                $budget,
                $userInput,
            );
            $accepted = $this->mergeVerifiedCandidates($accepted, $extracted['accepted']);
            $confirmation = $this->mergeConfirmationCandidates($confirmation, $extracted['confirmation']);

            if ($this->hasUsableAcceptedCandidates($accepted)) {
                $diagnostics->setStoppedReason('sufficient_variants');

                return [
                    'accepted' => $accepted,
                    'confirmation' => $this->finalizeConfirmationCandidates($confirmation),
                ];
            }
        }

        if ($mergedResults === []) {
            return ['accepted' => [], 'confirmation' => []];
        }

        if (!$this->hasUsableAcceptedCandidates($accepted) && $budget->hasHtmlFetchBudgetRemaining()) {
            $extracted = $this->extractFromRankedUrls(
                array_values($mergedResults),
                $plan,
                $budget,
                $userInput,
            );
            $accepted = $this->mergeVerifiedCandidates($accepted, $extracted['accepted']);
            $confirmation = $this->mergeConfirmationCandidates($confirmation, $extracted['confirmation']);

            if ($this->hasUsableAcceptedCandidates($accepted)) {
                $diagnostics->setStoppedReason('sufficient_variants');

                return [
                    'accepted' => $accepted,
                    'confirmation' => $this->finalizeConfirmationCandidates($confirmation),
                ];
            }
        }

        while ($this->shouldContinueBraveSearch($accepted, $budget)) {
            $extraQuery = $this->buildAdditionalQuery($plan, $userInput, $budget);
            if ($extraQuery === null || !$budget->shouldExecuteBraveQuery($extraQuery)) {
                break;
            }

            $search = $this->braveSearch->search($extraQuery, 10);
            $budget->recordBraveSearch($extraQuery);
            if (!$search['ok']) {
                continue;
            }

            foreach ($search['results'] as $result) {
                $url = $result['url'];
                if (!isset($mergedResults[$url])) {
                    $mergedResults[$url] = $result;
                }
            }

            $extracted = $this->extractFromRankedUrls(
                array_values($mergedResults),
                $plan,
                $budget,
                $userInput,
            );
            $accepted = $this->mergeVerifiedCandidates($accepted, $extracted['accepted']);
            $confirmation = $this->mergeConfirmationCandidates($confirmation, $extracted['confirmation']);

            if ($this->hasUsableAcceptedCandidates($accepted)) {
                $diagnostics->setStoppedReason('sufficient_variants');

                return [
                    'accepted' => $accepted,
                    'confirmation' => $this->finalizeConfirmationCandidates($confirmation),
                ];
            }
        }

        $diagnostics->setStoppedReason(
            $accepted === [] && $confirmation === [] ? 'no_candidates' : 'search_budget_exhausted',
        );

        return [
            'accepted' => $accepted,
            'confirmation' => $this->finalizeConfirmationCandidates($confirmation),
        ];
    }

    /**
     * 追加 Brave は「accepted がまだ無い」かつ「HTMLを開ける余地がある」ときだけ。
     *
     * @param list<array<string, mixed>> $accepted
     */
    private function shouldContinueBraveSearch(array $accepted, WebSearchBudget $budget): bool
    {
        if ($this->hasUsableAcceptedCandidates($accepted)) {
            return false;
        }

        if (!$budget->hasHtmlFetchBudgetRemaining()) {
            return false;
        }

        return $budget->canBraveSearch();
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
     * @return array{accepted: list<array<string, mixed>>, confirmation: list<array<string, mixed>>}
     */
    private function extractFromRankedUrls(
        array $results,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
        string $userInput,
    ): array {
        $ranked = $this->urlRanker->rank(
            $results,
            $plan->normalizedProductName,
            $plan->brandName,
            $plan->searchMode,
        );
        $accepted = [];
        $confirmation = [];

        foreach ($ranked as $entry) {
            if (!$budget->canFetchHtml($entry['url'])) {
                continue;
            }

            $html = $this->pageExtractor->fetchPageHtml($entry['url']);
            $budget->recordHtmlFetch($entry['url']);
            if ($html === null) {
                continue;
            }

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
                    continue;
                }

                $accepted[] = $candidate;
            }

            // 単品は accepted 1件で HTML 追加取得を止める。
            // バリアントありは従来どおり十分なサイズ数が揃うまで続ける。
            if ($plan->likelyHasVariants) {
                if ($this->hasSufficientCandidates($accepted, $plan)) {
                    break;
                }
            } elseif ($this->hasUsableAcceptedCandidates($accepted)) {
                break;
            }
        }

        return [
            'accepted' => $this->dedupeVerifiedCandidates($accepted),
            'confirmation' => $confirmation,
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
            $userInput,
            $displayProductName,
            $brandName,
        );

        $source = $sourceType === 'claude_web_search' ? 'claude_web_search' : 'brave_html';

        $candidate = [
            'product_name' => $displayProductName,
            'brand' => $brandName,
            'kcal' => (int) ($item['kcal'] ?? 0),
            'source_url' => $entry['url'],
            'source_title' => $entry['title'] !== '' ? $entry['title'] : null,
            'source' => $source,
            'source_type' => $sourceType,
            'identity_confidence' => $identityConfidence,
            'is_official_url' => $this->pageExtractor->isOfficialUrl($entry['url']),
            'base_product_name' => $displayProductName,
            'variant_label' => $item['variantLabel'] ?? '通常サイズ',
            'variant_confidence' => 'high',
            'variant_dimension' => (string) ($item['variantDimension'] ?? $plan->variantDimension),
            'serving_weight_g' => $item['servingWeightG'] ?? null,
            'package_size' => $item['packageSize'] ?? null,
            'evidence_text' => $item['evidenceText'] ?? null,
            'verification_confidence' => $item['verificationConfidence'] ?? 'medium',
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

    private function buildAdditionalQuery(FoodWebSearchPlan $plan, string $userInput, WebSearchBudget $budget): ?string
    {
        $searchName = $this->queryBuilder->buildAdditionalSearchName($plan, $userInput);

        return match ($plan->searchMode) {
            'variant_list_page' => $searchName . ' カロリー 一覧',
            'product_list_page' => $searchName . ' 内容量 カロリー',
            default => $searchName . ' カロリー',
        };
    }
}
