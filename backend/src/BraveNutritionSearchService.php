<?php

declare(strict_types=1);

/**
 * Brave Search API で URL を探し、HTML からカロリーを抽出する。
 * 見つからない場合は null を返し、呼び出し元で Claude による商品名・参照 URL 特定後に HTML 抽出する。
 */
final class BraveNutritionSearchService
{
    public function __construct(
        private readonly BraveSearchService $braveSearch = new BraveSearchService(),
        private readonly NutritionPageExtractor $pageExtractor = new NutritionPageExtractor(),
    ) {
    }

    /**
     * @param list<string> $contextTexts 入力や Claude 特定名など、店舗判定に使う追加テキスト
     * @return array{kcal: int, confidence: string, product_name?: string, source_url?: string}|null
     */
    public function searchFoodCalories(string $foodName, array $contextTexts = []): ?array
    {
        $foodName = trim($foodName);

        if ($foodName === '') {
            return null;
        }

        if (trim((string) (getenv('BRAVE_SEARCH_API_KEY') ?: '')) === '') {
            return null;
        }

        $searchQuery = $this->buildCalorieSearchQuery($foodName);
        $storeContext = array_values(array_unique(array_filter(array_map(
            'trim',
            array_merge([$foodName], $contextTexts),
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

            if ($probeResult['best'] !== null) {
                return $this->toEstimateResult($foodName, $probeResult['best'], $mergedResults);
            }
        }

        return null;
    }

    public function buildCalorieSearchQuery(string $foodName): string
    {
        $trimmed = trim($foodName);
        $lower = mb_strtolower($trimmed);

        if (str_contains($lower, 'カロリー')) {
            return $trimmed;
        }

        $coreName = trim((string) preg_replace(
            '/\s*\d+(?:\.\d+)?\s*(g|ml|個|杯|切れ|袋|本)\s*$/iu',
            '',
            $trimmed,
        ));

        if ($coreName === '') {
            $coreName = $trimmed;
        }

        return $coreName . ' カロリー';
    }

    /**
     * @param array{kcal: int, url: string, score: int} $best
     * @param array<string, array{title: string, url: string, description: string}> $mergedResults
     * @return array{kcal: int, confidence: string, product_name?: string, source_url?: string}
     */
    private function toEstimateResult(string $foodName, array $best, array $mergedResults): array
    {
        return [
            'kcal' => $best['kcal'],
            'confidence' => 'high',
            'product_name' => $foodName,
            'source_url' => $best['url'],
        ];
    }
}
