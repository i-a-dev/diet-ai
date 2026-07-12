<?php

declare(strict_types=1);

/**
 * Claude 計画をユーザー入力に照らして補正する。
 * 入力にないブランド推測を Brave クエリへ載せない。
 */
final class FoodWebSearchPlanInputGuard
{
    /** @var array<string, list<string>> */
    private const BRAND_ALIASES = [
        'マクドナルド' => ['マック', 'mcdonald', 'mcdonalds'],
        'マック' => ['マクドナルド', 'mcdonald', 'mcdonalds'],
        'スターバックス' => ['starbucks', 'スタバ'],
        'starbucks' => ['スターバックス', 'スタバ'],
        'セブンイレブン' => ['セブン', '7-11'],
        'セブン' => ['セブンイレブン', '7-11'],
        'ファミリーマート' => ['ファミマ'],
        'ファミマ' => ['ファミリーマート'],
    ];

    /** @var list<string> */
    private const GENERIC_QUERY_TERMS = [
        'カロリー',
        '栄養成分',
        'エネルギー',
        '商品情報',
        '内容量',
        'サイズ',
        'サイズ一覧',
        'メニュー',
        '商品一覧',
        '商品',
    ];

    public function apply(string $userInput, FoodWebSearchPlan $plan): FoodWebSearchPlan
    {
        $brandName = $this->resolveBrandNameForInput($userInput, $plan);
        $queryTerms = $this->sanitizeQueryTerms($userInput, $plan->queryTerms, $plan->normalizedProductName, $brandName);

        if ($brandName === $plan->brandName && $queryTerms === $plan->queryTerms) {
            return $plan;
        }

        return new FoodWebSearchPlan(
            isFood: $plan->isFood,
            normalizedProductName: $plan->normalizedProductName,
            brandName: $brandName,
            productType: $plan->productType,
            likelyHasVariants: $plan->likelyHasVariants,
            variantDimension: $plan->variantDimension,
            expectedLabels: $plan->expectedLabels,
            variantConfidence: $plan->variantConfidence,
            searchMode: $plan->searchMode,
            queryTerms: $queryTerms,
        );
    }

    private function resolveBrandNameForInput(string $userInput, FoodWebSearchPlan $plan): ?string
    {
        $brandName = $plan->brandName;
        if ($brandName === null || trim($brandName) === '') {
            return null;
        }

        if (!$this->isBrandExplicitInInput($userInput, $brandName, $plan->normalizedProductName)) {
            return null;
        }

        return trim($brandName);
    }

    private function isBrandExplicitInInput(string $userInput, string $brandName, string $productName): bool
    {
        $input = mb_strtolower(trim($userInput));
        $product = mb_strtolower(trim($productName));

        if ($input === '' || $this->looksLikeInventedBrandName($brandName)) {
            return false;
        }

        $brandCore = $this->normalizeBrandCore($brandName);
        if ($brandCore === '') {
            return false;
        }

        $brandLower = mb_strtolower($brandCore);

        if ($brandLower === $product || ($product !== '' && str_contains($product, $brandLower) && $input === $product)) {
            return false;
        }

        if (mb_strpos($input, $brandLower) !== false) {
            return true;
        }

        return $this->brandAliasMatchesInput($input, $brandCore);
    }

    private function looksLikeInventedBrandName(string $brandName): bool
    {
        return preg_match('/(?:等|メーカー|製造|不明|推定|おそらく|など|工房)/u', $brandName) === 1;
    }

    private function normalizeBrandCore(string $brandName): string
    {
        $value = trim($brandName);
        $value = (string) preg_replace('/[（(].*[）)]/u', '', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        return $value;
    }

    private function brandAliasMatchesInput(string $inputLower, string $brandCore): bool
    {
        $brandLower = mb_strtolower($brandCore);

        foreach (self::BRAND_ALIASES[$brandCore] ?? self::BRAND_ALIASES[$brandLower] ?? [] as $alias) {
            if (mb_strpos($inputLower, mb_strtolower($alias)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private function sanitizeQueryTerms(
        string $userInput,
        array $terms,
        string $productName,
        ?string $brandName,
    ): array {
        $inputLower = mb_strtolower(trim($userInput));
        $productLower = mb_strtolower(trim($productName));
        $filtered = [];

        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }

            if (in_array($term, self::GENERIC_QUERY_TERMS, true)) {
                $filtered[] = $term;
                continue;
            }

            $termLower = mb_strtolower($term);
            if ($termLower === $productLower || mb_strpos($inputLower, $termLower) !== false) {
                $filtered[] = $term;
                continue;
            }

            if ($brandName !== null && mb_strpos(mb_strtolower($brandName), $termLower) !== false) {
                continue;
            }
        }

        if ($filtered !== []) {
            return array_values(array_unique($filtered));
        }

        return ['カロリー', '栄養成分'];
    }
}
