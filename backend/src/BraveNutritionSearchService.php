<?php

declare(strict_types=1);

/**
 * Brave Search API で URL を探し、HTML からカロリーを抽出する。
 */
final class BraveNutritionSearchService
{
    private const MAX_CANDIDATES = 6;
    private const MAX_URL_PROBES = 8;

    public function __construct(
        private readonly BraveSearchService $braveSearch = new BraveSearchService(),
        private readonly NutritionPageExtractor $pageExtractor = new NutritionPageExtractor(),
        private readonly FoodVariantAnalyzer $variantAnalyzer = new FoodVariantAnalyzer(),
    ) {
    }

    /**
     * 従来互換: 商品名からカロリーを検索する。
     *
     * @param list<string> $contextTexts
     * @return array{kcal: int, confidence: string, product_name?: string, source_url?: string}|null
     */
    public function searchFoodCalories(string $foodName, array $contextTexts = []): ?array
    {
        $result = $this->searchWithIdentity($foodName, $foodName, $contextTexts, 'calorie');

        if ($result === null) {
            return null;
        }

        return [
            'kcal' => $result['kcal'],
            'confidence' => $result['confidence'],
            'product_name' => $result['product_name'],
            'source_url' => $result['source_url'],
        ];
    }

    /**
     * サイズ違い候補も含めて複数件収集する。
     *
     * @param list<string> $contextTexts
     * @return list<array{
     *   kcal: int,
     *   confidence: string,
     *   product_name: string,
     *   brand?: string,
     *   source_url: string,
     *   source: string,
     *   identity_confidence: string,
     *   is_official_url: bool,
     *   base_product_name: string,
     *   variant_label: string,
     *   variant_confidence: string,
     *   serving_weight_g?: int|null,
     *   package_size?: string|null
     * }>
     */
    public function collectCandidates(
        string $foodName,
        string $userInput,
        array $contextTexts = [],
        ?array $inputAnalysis = null,
    ): array {
        $foodName = trim($foodName);
        $userInput = trim($userInput);

        if ($foodName === '' || $userInput === '') {
            return [];
        }

        if (trim((string) (getenv('BRAVE_SEARCH_API_KEY') ?: '')) === '') {
            return [];
        }

        $inputAnalysis ??= $this->variantAnalyzer->analyzeInput($userInput);
        $queries = $this->buildSearchQueries($foodName, $inputAnalysis);
        $storeContext = array_values(array_unique(array_filter(array_map(
            'trim',
            array_merge([$foodName, $userInput], $contextTexts),
        ))));

        $collected = [];
        $seenKeys = [];

        foreach ($queries as $searchQuery) {
            if (count($collected) >= self::MAX_CANDIDATES) {
                break;
            }

            /** @var array<string, array{title: string, url: string, description: string}> $queryMergedResults */
            $queryMergedResults = [];
            $queryMergedUrls = [];

            $queriesToTry = array_values(array_unique(array_merge(
                $this->braveSearch->buildFallbackQueries($searchQuery, $storeContext),
                [$searchQuery],
            )));

            $queryAnalysis = $this->variantAnalyzer->analyzeInput($searchQuery);
            if (
                $this->variantAnalyzer->shouldExploreVariants($inputAnalysis)
                && ($queryAnalysis['has_explicit_variant'] ?? false) === false
            ) {
                continue;
            }

            if (($queryAnalysis['has_explicit_variant'] ?? false) === true) {
                $queriesToTry[] = $searchQuery . ' site:kalori.jp';
            }

            foreach ($queriesToTry as $query) {
                $search = $this->braveSearch->search($query, 10);
                if (!$search['ok']) {
                    continue;
                }

                foreach ($search['results'] as $result) {
                    $url = $result['url'];
                    if (!isset($queryMergedResults[$url])) {
                        $queryMergedResults[$url] = $result;
                        $queryMergedUrls[] = $url;
                    }
                }
            }

            if ($queryMergedUrls === []) {
                continue;
            }

            $rankedUrls = $this->pageExtractor->rankUrls($queryMergedUrls, [
                'query' => $searchQuery,
                'results' => array_values($queryMergedResults),
            ]);

            foreach ($rankedUrls as $entry) {
                if (count($collected) >= self::MAX_CANDIDATES) {
                    break 2;
                }

                $url = $entry['url'];
                if ($this->pageExtractor->isBlockedSourceUrl($url)) {
                    continue;
                }

                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (
                    ($queryAnalysis['has_explicit_variant'] ?? false) === true
                    && str_contains($host, 'fatsecret')
                ) {
                    continue;
                }

                $single = $this->pageExtractor->probeSingleUrl($url, $searchQuery);
                if ($single === null) {
                    continue;
                }

                $result = $this->toIdentityResult(
                    $userInput,
                    $searchQuery,
                    $single,
                    $queryMergedResults,
                    null,
                );

                $dedupeKey = $this->variantAnalyzer->buildCandidateDedupeKey($result);
                if (isset($seenKeys[$dedupeKey])) {
                    $existingIndex = $seenKeys[$dedupeKey];
                    $collected[$existingIndex] = $this->preferCandidate(
                        $collected[$existingIndex],
                        $result,
                    );
                } else {
                    $seenKeys[$dedupeKey] = count($collected);
                    $collected[] = $result;
                }

                // この searchQuery（例: Lサイズ）で1件取れたら残り URL は試さない
                break;
            }
        }

        return $this->sortCandidates($collected, $inputAnalysis);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function preferCandidate(array $current, array $incoming): array
    {
        return $this->candidateQualityScore($incoming) > $this->candidateQualityScore($current)
            ? $incoming
            : $current;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateQualityScore(array $candidate): int
    {
        $score = 0;

        if (($candidate['is_official_url'] ?? false) === true) {
            $score += 120;
        }

        $source = (string) ($candidate['source'] ?? '');
        if ($source === 'brave_html') {
            $score += 60;
        } elseif ($source === 'claude_web_search') {
            $score += 40;
        } elseif ($source === 'alias_db') {
            $score += 10;
        }

        if (!empty($candidate['source_url'])) {
            $score += 5;
        }

        $score += min(mb_strlen((string) ($candidate['product_name'] ?? '')), 40);

        return $score;
    }

    /**
     * 商品同一性を判定しながら Brave + HTML 抽出を行う（ベスト1件）。
     *
     * @param list<string> $contextTexts
     * @param 'nutrition'|'calorie' $queryStyle
     * @return array{
     *   kcal: int,
     *   confidence: string,
     *   product_name: string,
     *   brand?: string,
     *   source_url: string,
     *   source: string,
     *   identity_confidence: string,
     *   is_official_url: bool,
     *   base_product_name?: string,
     *   variant_label?: string,
     *   variant_confidence?: string,
     *   serving_weight_g?: int|null,
     *   package_size?: string|null
     * }|null
     */
    public function searchWithIdentity(
        string $foodName,
        string $userInput,
        array $contextTexts = [],
        string $queryStyle = 'nutrition',
        ?string $brand = null,
    ): ?array {
        $candidates = $this->collectCandidates($foodName, $userInput, $contextTexts);

        if ($candidates === []) {
            return null;
        }

        if ($brand !== null && trim($brand) !== '') {
            $candidates[0]['brand'] = trim($brand);
        }

        return $candidates[0];
    }

    /**
     * @param array{
     *   variant_risk: string,
     *   base_product_name: string,
     *   has_explicit_variant: bool
     * } $inputAnalysis
     * @return list<string>
     */
    private function buildSearchQueries(string $foodName, array $inputAnalysis): array
    {
        $base = $inputAnalysis['base_product_name'] !== ''
            ? $inputAnalysis['base_product_name']
            : $this->variantAnalyzer->extractBaseProductName($foodName);

        $queries = [$this->buildCalorieSearchQuery($base)];

        if ($this->variantAnalyzer->shouldExploreVariants($inputAnalysis)) {
            $queries = array_merge(
                $this->variantAnalyzer->buildVariantSearchQueries($base),
                $queries,
            );
        }

        return array_values(array_unique($queries));
    }

    public function buildNutritionSearchQuery(string $foodName): string
    {
        $coreName = $this->stripTrailingAmount(trim($foodName));

        return $coreName . ' 栄養成分 エネルギー kcal';
    }

    public function buildCalorieSearchQuery(string $foodName): string
    {
        $trimmed = trim($foodName);
        $lower = mb_strtolower($trimmed);

        if (str_contains($lower, 'カロリー')) {
            return $trimmed;
        }

        $coreName = $this->stripTrailingAmount($trimmed);

        return $coreName . ' カロリー';
    }

    /**
     * @param array{kcal: int, url: string, score: int} $best
     * @param array<string, array{title: string, url: string, description: string}> $mergedResults
     * @return array{
     *   kcal: int,
     *   confidence: string,
     *   product_name: string,
     *   brand?: string,
     *   source_url: string,
     *   source: string,
     *   identity_confidence: string,
     *   is_official_url: bool,
     *   base_product_name: string,
     *   variant_label: string,
     *   variant_confidence: string,
     *   serving_weight_g?: int|null,
     *   package_size?: string|null
     * }
     */
    private function toIdentityResult(
        string $userInput,
        string $searchName,
        array $best,
        array $mergedResults,
        ?string $brand,
    ): array {
        $sourceUrl = $best['url'];
        $inferredName = $this->pageExtractor->inferProductNameFromMeta($sourceUrl, $searchName);
        $productName = $inferredName !== '' ? $inferredName : $searchName;
        $productName = (string) preg_replace('/\s*(カロリー|栄養成分|エネルギー|kcal).*$/iu', '', $productName);
        $productName = trim($productName);
        if ($productName === '') {
            $productName = trim($searchName);
        }
        $brandName = $brand !== null && trim($brand) !== '' ? trim($brand) : null;
        $identityConfidence = $this->pageExtractor->assessProductIdentity(
            $userInput,
            $productName,
            $brandName,
        );
        $isOfficialUrl = $this->pageExtractor->isOfficialUrl($sourceUrl);
        $confidence = $identityConfidence === 'high' ? 'high' : 'medium';
        $variant = $this->variantAnalyzer->analyzeProduct($productName);

        $result = [
            'kcal' => $best['kcal'],
            'confidence' => $confidence,
            'product_name' => $productName,
            'source_url' => $sourceUrl,
            'source' => 'brave_html',
            'identity_confidence' => $identityConfidence,
            'is_official_url' => $isOfficialUrl,
            'base_product_name' => $variant['base_product_name'],
            'variant_label' => $variant['variant_label'],
            'variant_confidence' => $variant['variant_confidence'],
            'serving_weight_g' => $variant['serving_weight_g'],
            'package_size' => $variant['package_size'],
        ];

        if ($brandName !== null) {
            $result['brand'] = $brandName;
        }

        return $result;
    }

    /**
     * @param list<array{
     *   variant_label?: string,
     *   identity_confidence?: string,
     *   kcal?: int
     * }> $candidates
     * @param array{
     *   input_variant_label?: string|null,
     *   has_explicit_variant?: bool
     * } $inputAnalysis
     * @return list<array<string, mixed>>
     */
    private function sortCandidates(array $candidates, array $inputAnalysis): array
    {
        usort(
            $candidates,
            function (array $a, array $b) use ($inputAnalysis): int {
                if (($inputAnalysis['has_explicit_variant'] ?? false) === true) {
                    $aMatch = $this->variantAnalyzer->variantMatchesInput($inputAnalysis, $a) ? 1 : 0;
                    $bMatch = $this->variantAnalyzer->variantMatchesInput($inputAnalysis, $b) ? 1 : 0;
                    if ($aMatch !== $bMatch) {
                        return $bMatch <=> $aMatch;
                    }
                }

                $variantOrder = ['通常サイズ' => 0, 'Mサイズ' => 1, 'Lサイズ' => 2, 'Sサイズ' => 3, 'BIG' => 4];
                $aVariant = $variantOrder[$a['variant_label'] ?? ''] ?? 99;
                $bVariant = $variantOrder[$b['variant_label'] ?? ''] ?? 99;
                if ($aVariant !== $bVariant) {
                    return $aVariant <=> $bVariant;
                }

                return ($b['kcal'] ?? 0) <=> ($a['kcal'] ?? 0);
            },
        );

        return array_values($candidates);
    }

    private function stripTrailingAmount(string $value): string
    {
        $coreName = trim((string) preg_replace(
            '/\s*\d+(?:\.\d+)?\s*(g|ml|個|杯|切れ|袋|本)\s*$/iu',
            '',
            $value,
        ));

        return $coreName !== '' ? $coreName : $value;
    }
}
