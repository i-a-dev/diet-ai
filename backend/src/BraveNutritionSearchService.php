<?php

declare(strict_types=1);

/**
 * Brave Search API で URL を探し、HTML からカロリーを抽出する。
 */
final class BraveNutritionSearchService
{
    public function __construct(
        private readonly BraveSearchService $braveSearch = new BraveSearchService(),
        private readonly NutritionPageExtractor $pageExtractor = new NutritionPageExtractor(),
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
     * 商品同一性を判定しながら Brave + HTML 抽出を行う。
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
     *   is_official_url: bool
     * }|null
     */
    public function searchWithIdentity(
        string $foodName,
        string $userInput,
        array $contextTexts = [],
        string $queryStyle = 'nutrition',
        ?string $brand = null,
    ): ?array {
        $foodName = trim($foodName);
        $userInput = trim($userInput);

        if ($foodName === '' || $userInput === '') {
            return null;
        }

        if (trim((string) (getenv('BRAVE_SEARCH_API_KEY') ?: '')) === '') {
            return null;
        }

        $searchQuery = $queryStyle === 'calorie'
            ? $this->buildCalorieSearchQuery($foodName)
            : $this->buildNutritionSearchQuery($foodName);

        $storeContext = array_values(array_unique(array_filter(array_map(
            'trim',
            array_merge([$foodName, $userInput], $contextTexts),
        ))));

        $queriesToTry = array_values(array_unique(array_merge(
            $this->braveSearch->buildFallbackQueries($searchQuery, $storeContext),
            [$searchQuery],
        )));

        /** @var array<string, array{title: string, url: string, description: string}> $mergedResults */
        $mergedResults = [];
        $mergedUrls = [];

        foreach ($queriesToTry as $query) {
            $search = $this->braveSearch->search($query, 10);

            if (!$search['ok']) {
                continue;
            }

            foreach ($search['results'] as $result) {
                $url = $result['url'];
                if (!isset($mergedResults[$url])) {
                    $mergedResults[$url] = $result;
                    $mergedUrls[] = $url;
                }
            }

            $rankedUrls = $this->pageExtractor->rankUrls($mergedUrls, [
                'query' => $foodName,
                'results' => array_values($mergedResults),
            ]);
            $probeResult = $this->pageExtractor->probeUrls($rankedUrls, ['query' => $foodName]);

            if ($probeResult['best'] === null) {
                continue;
            }

            return $this->toIdentityResult(
                $userInput,
                $foodName,
                $probeResult['best'],
                $mergedResults,
                $brand,
            );
        }

        return null;
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
     *   is_official_url: bool
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
        $brandName = $brand !== null && trim($brand) !== '' ? trim($brand) : null;
        $identityConfidence = $this->pageExtractor->assessProductIdentity(
            $userInput,
            $productName,
            $brandName,
        );
        $isOfficialUrl = $this->pageExtractor->isOfficialUrl($sourceUrl);
        $confidence = $identityConfidence === 'high' ? 'high' : 'medium';

        $result = [
            'kcal' => $best['kcal'],
            'confidence' => $confidence,
            'product_name' => $productName,
            'source_url' => $sourceUrl,
            'source' => 'brave_html',
            'identity_confidence' => $identityConfidence,
            'is_official_url' => $isOfficialUrl,
        ];

        if ($brandName !== null) {
            $result['brand'] = $brandName;
        }

        return $result;
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
