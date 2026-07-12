<?php

declare(strict_types=1);

/**
 * AI Web 検索オーケストレーター。
 *
 * 変更後フロー:
 * 1. Claude Haiku で検索計画（1回）
 * 2. 固定テンプレートで Brave 検索（通常1〜2回、最大4回）
 * 3. 上位URLの HTML から複数バリアント抽出（最大3URL）
 * 4. 十分な候補が取れたら早期終了
 * 5. 候補0件のみ Claude Web Search を最大1回
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

        $cached = $this->cache->get($trimmed);
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

        if ($plan->searchMode === 'no_web_search') {
            $response = [
                'web_search_status' => 'no_web_search',
                'fallback_to_existing_estimate' => true,
            ];
            $diagnostics->setStoppedReason('no_web_search');
            $diagnostics->log();

            return $response;
        }

        $verifiedCandidates = $this->collectVerifiedCandidates($trimmed, $plan, $budget, $diagnostics);

        if ($verifiedCandidates === [] && $budget->canClaudeWebSearch() && $this->claudeWebSearchFallback !== null) {
            $budget->recordClaudeWebSearch();
            $fallbackResult = ($this->claudeWebSearchFallback)($trimmed, $apiKey);
            if (is_array($fallbackResult) && $fallbackResult !== []) {
                $diagnostics->addFallbackReason('claude_web_search');
                $diagnostics->setStoppedReason('fallback');
                $diagnostics->log();

                return $fallbackResult;
            }
        }

        if ($verifiedCandidates === []) {
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

        $response = $this->formatResponse($trimmed, $plan, $verifiedCandidates, $diagnostics);
        $this->cache->put($trimmed, [
            'plan' => $plan->toArray(),
            'response' => $response,
        ]);
        $diagnostics->log();

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectVerifiedCandidates(
        string $userInput,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
        WebSearchDiagnostics $diagnostics,
    ): array {
        $queries = $this->queryBuilder->build($plan, $userInput);
        /** @var array<string, array{title: string, url: string, description: string}> $mergedResults */
        $mergedResults = [];
        $verified = [];

        foreach ($queries as $query) {
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

            $verified = $this->mergeVerifiedCandidates(
                $verified,
                $this->extractFromRankedUrls(
                    array_values($mergedResults),
                    $plan,
                    $budget,
                    $userInput,
                ),
            );

            if ($this->hasSufficientCandidates($verified, $plan)) {
                $diagnostics->setStoppedReason('sufficient_variants');

                return $verified;
            }
        }

        if ($mergedResults === []) {
            return [];
        }

        $verified = $this->mergeVerifiedCandidates(
            $verified,
            $this->extractFromRankedUrls(
                array_values($mergedResults),
                $plan,
                $budget,
                $userInput,
            ),
        );

        if ($this->hasSufficientCandidates($verified, $plan)) {
            $diagnostics->setStoppedReason('sufficient_variants');

            return $verified;
        }

        while ($budget->canAdditionalBraveSearch()) {
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

            $verified = $this->mergeVerifiedCandidates(
                $verified,
                $this->extractFromRankedUrls(
                    array_values($mergedResults),
                    $plan,
                    $budget,
                    $userInput,
                ),
            );

            if ($this->hasSufficientCandidates($verified, $plan)) {
                $diagnostics->setStoppedReason('sufficient_variants');

                return $verified;
            }
        }

        $diagnostics->setStoppedReason($verified === [] ? 'no_candidates' : 'search_budget_exhausted');

        return $verified;
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
     * @return list<array<string, mixed>>
     */
    private function extractFromRankedUrls(
        array $results,
        FoodWebSearchPlan $plan,
        WebSearchBudget $budget,
        string $userInput,
    ): array {
        $ranked = $this->urlRanker->rank($results, $plan->normalizedProductName, $plan->brandName);
        $verified = [];

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
                $verified[] = $this->toVerifiedCandidate(
                    $item,
                    $entry,
                    $userInput,
                    $plan,
                );
            }

            if ($this->hasSufficientCandidates($verified, $plan)) {
                break;
            }
        }

        return $this->dedupeVerifiedCandidates($verified);
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

        return [
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
        return [
            'kcal' => (int) $candidate['kcal'],
            'confidence' => ($candidate['identity_confidence'] ?? 'medium') === 'high' ? 'high' : 'medium',
            'product_name' => (string) $candidate['product_name'],
            'source_url' => $candidate['source_url'] ?? null,
            'source' => (string) ($candidate['source'] ?? 'brave_html'),
            'identity_confidence' => (string) ($candidate['identity_confidence'] ?? 'medium'),
            'needs_confirmation' => false,
            'base_product_name' => $candidate['base_product_name'] ?? null,
            'variant_label' => $candidate['variant_label'] ?? null,
            'variant_confidence' => $candidate['variant_confidence'] ?? null,
            'serving_weight_g' => $candidate['serving_weight_g'] ?? null,
            'package_size' => $candidate['package_size'] ?? null,
        ];
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
