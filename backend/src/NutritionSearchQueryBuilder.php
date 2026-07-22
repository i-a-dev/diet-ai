<?php

declare(strict_types=1);

/**
 * Claude 検索計画から Brave 検索クエリを固定テンプレートで生成する。
 */
final class NutritionSearchQueryBuilder
{
    /** @var array<string, string> */
    private const BRAND_OFFICIAL_SITES = [
        'マクドナルド' => 'mcdonalds.co.jp',
        'マック' => 'mcdonalds.co.jp',
        'mcdonald' => 'mcdonalds.co.jp',
        'すき家' => 'sukiya.jp',
        '吉野家' => 'yoshinoya.com',
        '松屋' => 'matsuyafoods.co.jp',
        'スターバックス' => 'starbucks.co.jp',
        'starbucks' => 'starbucks.co.jp',
        'セブンイレブン' => 'sej.co.jp',
        'セブン' => 'sej.co.jp',
        'ローソン' => 'lawson.co.jp',
        'ファミリーマート' => 'family.co.jp',
        'ファミマ' => 'family.co.jp',
        '山芳製菓' => 'sanyo-seika.co.jp',
        'ナッシュ' => 'nosh.jp',
        'nosh' => 'nosh.jp',
    ];

    /**
     * @return list<string> 最大2件
     */
    public function build(FoodWebSearchPlan $plan, string $userInput = ''): array
    {
        if ($plan->searchMode === 'no_web_search' || !$plan->isFood) {
            return [];
        }

        $productName = $plan->normalizedProductName;
        if ($productName === '') {
            return [];
        }

        $searchName = $this->resolveSearchName($plan, $userInput);
        $terms = $plan->queryTerms !== [] ? $plan->queryTerms : $this->defaultQueryTerms($plan);

        return match ($plan->searchMode) {
            'variant_list_page' => $this->buildVariantListQueries($searchName, $terms, $plan),
            'product_list_page' => $this->buildProductListQueries($searchName, $terms, $plan),
            'single_product' => $this->buildSingleProductQueries($searchName, $terms, $plan),
            default => array_slice([$this->joinTerms($searchName, $terms)], 0, 1),
        };
    }

    /**
     * 追加 Brave 検索用の商品名部分（ユーザー入力の語を落とさない）。
     */
    public function buildAdditionalSearchName(FoodWebSearchPlan $plan, string $userInput = ''): string
    {
        return $this->resolveSearchName($plan, $userInput);
    }

    /**
     * 計画の商品名よりユーザー入力の語を優先して検索ベース名を作る。
     */
    public function resolveSearchName(FoodWebSearchPlan $plan, string $userInput): string
    {
        $brandPrefix = $this->buildBrandPrefix($plan->brandName);
        $planSearchName = $brandPrefix !== ''
            ? trim($brandPrefix . ' ' . $plan->normalizedProductName)
            : trim($plan->normalizedProductName);

        $userSearchName = $this->normalizeUserSearchBase($userInput);
        if ($userSearchName === '') {
            return $planSearchName;
        }

        if ($planSearchName === '') {
            return $userSearchName;
        }

        return $this->mergeSearchTokens($userSearchName, $planSearchName);
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private function buildVariantListQueries(string $searchName, array $terms, FoodWebSearchPlan $plan): array
    {
        $primaryTerms = $this->pickTerms($terms, ['栄養成分', 'サイズ', 'サイズ一覧', 'カロリー']);
        $secondaryTerms = $this->pickTerms($terms, ['メニュー', 'カロリー', '栄養成分']);

        $queries = [
            $this->joinTerms($searchName, array_merge($primaryTerms, ['サイズ'])),
            $this->joinTerms($searchName, array_merge($secondaryTerms, ['メニュー', 'カロリー'])),
        ];

        $officialSite = $this->resolveOfficialSite($plan->brandName, $searchName);
        if ($officialSite !== null) {
            $queries[1] = 'site:' . $officialSite . ' ' . $plan->normalizedProductName . ' 栄養成分';
        }

        return $this->uniqueNonEmpty(array_slice($queries, 0, 2));
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private function buildProductListQueries(string $searchName, array $terms, FoodWebSearchPlan $plan): array
    {
        $queries = [
            $this->joinTerms($searchName, $this->pickTerms($terms, ['商品情報', '内容量', '栄養成分'])),
            $this->joinTerms($searchName, $this->pickTerms($terms, ['商品一覧', 'カロリー', '内容量'])),
        ];

        $officialSite = $this->resolveOfficialSite($plan->brandName, $searchName);
        if ($officialSite !== null) {
            $queries[1] = 'site:' . $officialSite . ' ' . $plan->normalizedProductName . ' 商品情報';
        }

        return $this->uniqueNonEmpty(array_slice($queries, 0, 2));
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private function buildSingleProductQueries(string $searchName, array $terms, FoodWebSearchPlan $plan): array
    {
        $queries = [
            $this->joinTerms($searchName, $this->pickTerms($terms, ['栄養成分', 'カロリー', 'エネルギー'])),
        ];

        $officialSite = $this->resolveOfficialSite($plan->brandName, $searchName);
        if ($officialSite !== null) {
            $coreQuery = $this->buildOfficialDetailCoreQuery($officialSite, $plan);
            // 公式サイトがあるときは、誤ったフルネーム site 検索より核心トークンで detail を狙う
            $queries[] = $coreQuery ?? ('site:' . $officialSite . ' ' . $plan->normalizedProductName . ' 栄養成分');
        }

        return $this->uniqueNonEmpty(array_slice($queries, 0, 2));
    }

    /**
     * 公式詳細ページ向け: 助詞・ブランドを除いた有意トークンで site 検索する。
     * 例: たらと辛旨チリソース → site:nosh.jp たら 辛旨 チリソース
     */
    private function buildOfficialDetailCoreQuery(string $officialSite, FoodWebSearchPlan $plan): ?string
    {
        $tokens = $this->extractCoreSearchTokens($plan->normalizedProductName, $plan->brandName);
        if ($tokens === []) {
            return null;
        }

        return trim('site:' . $officialSite . ' ' . implode(' ', $tokens));
    }

    /**
     * @return list<string>
     */
    public function extractCoreSearchTokens(string $productName, ?string $brandName = null): array
    {
        $value = trim($productName);
        if ($value === '') {
            return [];
        }

        $value = mb_strtolower($value);
        if ($brandName !== null && trim($brandName) !== '') {
            $brand = mb_strtolower(trim($brandName));
            $value = str_replace([$brand, 'nosh'], ' ', $value);
        }

        // 助詞相当の1文字接続を空白化
        $value = (string) preg_replace('/[のとをがへに]/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        $tokens = [];
        if (preg_match_all('/[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}ーｰa-z0-9]{2,}/u', $value, $matches) > 0) {
            foreach ($matches[0] as $token) {
                foreach ($this->splitMixedScriptToken((string) $token) as $part) {
                    if ($part !== '' && !in_array($part, $tokens, true)) {
                        $tokens[] = $part;
                    }
                }
            }
        }

        // 中間語（辛旨 / 旨辛）も含める。先頭+末尾だけだと別メニュー（甘酢等）に寄る。
        return $tokens;
    }

    /**
     * 漢字塊とカタカナ塊が連結した語を分割する。
     * 例: 旨辛チリソース → [旨辛, チリソース]
     *
     * @return list<string>
     */
    private function splitMixedScriptToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }

        if (preg_match_all(
            '/\p{Script=Han}+|[\p{Script=Katakana}ーｰ]+|\p{Script=Hiragana}+|[a-z0-9]+/u',
            $token,
            $parts,
        ) > 0) {
            $split = [];
            foreach ($parts[0] as $part) {
                $part = trim((string) $part);
                if (mb_strlen($part) >= 2) {
                    $split[] = $part;
                }
            }
            if ($split !== []) {
                return $split;
            }
        }

        return [$token];
    }

    /**
     * Claude Web Search 用の検索クエリヒント。
     * Brave と同じ方針: 公式 detail + 核心トークン（中間語含む）を優先する。
     *
     * @return list<string> 最大2件（先頭が最優先）
     */
    public function buildClaudeWebSearchQueryHints(string $userInput): array
    {
        $trimmed = trim($userInput);
        if ($trimmed === '') {
            return [];
        }

        $brand = $this->detectBrandFromInput($trimmed);
        $productName = $this->stripBrandPrefix($trimmed, $brand);
        if ($productName === '') {
            $productName = $trimmed;
        }

        $plan = new FoodWebSearchPlan(
            isFood: true,
            normalizedProductName: $productName,
            brandName: $brand,
            productType: 'unknown',
            likelyHasVariants: false,
            variantDimension: 'none',
            expectedLabels: [],
            variantConfidence: 'low',
            searchMode: 'single_product',
            queryTerms: ['カロリー', '栄養成分'],
        );

        $queries = $this->build($plan, $trimmed);
        $ordered = [];

        // 公式 detail / site クエリを最優先
        foreach ($queries as $query) {
            if (str_contains($query, 'site:')) {
                $ordered[] = $query;
            }
        }

        $tokens = $this->extractCoreSearchTokens($productName, $brand);
        if ($tokens !== []) {
            $coreCalorie = trim(($brand ?? '') . ' ' . implode(' ', $tokens) . ' カロリー');
            $ordered[] = $coreCalorie;
        }

        foreach ($queries as $query) {
            $ordered[] = $query;
        }

        // 最後の保険: 従来どおりフルネーム + カロリー
        $ordered[] = $trimmed . (str_contains(mb_strtolower($trimmed), 'カロリー') ? '' : ' カロリー');

        return array_slice($this->uniqueNonEmpty($ordered), 0, 2);
    }

    public function detectBrandFromInput(string $userInput): ?string
    {
        $normalized = mb_strtolower(trim($userInput));
        if ($normalized === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach (array_keys(self::BRAND_OFFICIAL_SITES) as $brand) {
            $brandLower = mb_strtolower($brand);
            if ($brandLower === '') {
                continue;
            }
            if (mb_strpos($normalized, $brandLower) !== false && mb_strlen($brandLower) > $bestLen) {
                $best = $brand === 'nosh' ? 'ナッシュ' : $brand;
                // マック → マクドナルドに正規化（公式サイト解決と揃える）
                if ($best === 'マック') {
                    $best = 'マクドナルド';
                } elseif ($best === 'セブン') {
                    $best = 'セブンイレブン';
                } elseif ($best === 'ファミマ') {
                    $best = 'ファミリーマート';
                } elseif ($best === 'スタバ' || $best === 'starbucks') {
                    $best = 'スターバックス';
                }
                $bestLen = mb_strlen($brandLower);
            }
        }

        return $best;
    }

    private function stripBrandPrefix(string $userInput, ?string $brandName): string
    {
        $value = trim($userInput);
        if ($brandName === null || $brandName === '') {
            return $value;
        }

        $aliases = [$brandName, 'nosh'];
        if ($brandName === 'マクドナルド') {
            $aliases[] = 'マック';
        } elseif ($brandName === 'セブンイレブン') {
            $aliases[] = 'セブン';
        } elseif ($brandName === 'ファミリーマート') {
            $aliases[] = 'ファミマ';
        } elseif ($brandName === 'スターバックス') {
            $aliases[] = 'スタバ';
            $aliases[] = 'starbucks';
        }

        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias === '') {
                continue;
            }
            if (mb_stripos($value, $alias) === 0) {
                $value = trim(mb_substr($value, mb_strlen($alias)));
                break;
            }
            $value = trim(str_ireplace($alias, ' ', $value));
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param list<string> $preferred
     * @param list<string> $terms
     * @return list<string>
     */
    private function pickTerms(array $terms, array $preferred): array
    {
        $picked = [];
        foreach ($preferred as $term) {
            if (in_array($term, $terms, true)) {
                $picked[] = $term;
            }
        }

        if ($picked === []) {
            return array_slice($preferred, 0, 2);
        }

        return $picked;
    }

    /**
     * @param list<string> $parts
     */
    private function joinTerms(string $base, array $parts): string
    {
        $unique = [];
        foreach (array_merge([$base], $parts) as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $unique, true)) {
                $unique[] = $part;
            }
        }

        return implode(' ', $unique);
    }

    private function buildBrandPrefix(?string $brandName): string
    {
        return $brandName !== null && trim($brandName) !== '' ? trim($brandName) : '';
    }

    private function resolveOfficialSite(?string $brandName, string $searchName): ?string
    {
        $haystack = mb_strtolower(trim(($brandName ?? '') . ' ' . $searchName));

        foreach (self::BRAND_OFFICIAL_SITES as $needle => $site) {
            if (mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                return $site;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function defaultQueryTerms(FoodWebSearchPlan $plan): array
    {
        return match ($plan->searchMode) {
            'variant_list_page' => ['栄養成分', 'サイズ', 'カロリー'],
            'product_list_page' => ['商品情報', '内容量', '栄養成分'],
            default => ['栄養成分', 'カロリー'],
        };
    }

    /**
     * @param list<string> $queries
     * @return list<string>
     */
    private function uniqueNonEmpty(array $queries): array
    {
        $unique = [];
        foreach ($queries as $query) {
            $query = trim($query);
            if ($query !== '' && !in_array($query, $unique, true)) {
                $unique[] = $query;
            }
        }

        return $unique;
    }

    private function normalizeUserSearchBase(string $userInput): string
    {
        $value = trim($userInput);
        $value = (string) preg_replace('/\s*[|｜].*$/u', '', $value);
        $value = (string) preg_replace(
            '/\b(栄養成分|エネルギー|kcal|カロリー|site:[^\s]+)\b/u',
            ' ',
            $value,
        );
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        return $value;
    }

    private function mergeSearchTokens(string $userSearchName, string $planSearchName): string
    {
        $merged = [];
        foreach ([...$this->tokenizeSearchName($userSearchName), ...$this->tokenizeSearchName($planSearchName)] as $token) {
            if (!in_array($token, $merged, true)) {
                $merged[] = $token;
            }
        }

        return implode(' ', $merged);
    }

    /**
     * @return list<string>
     */
    private function tokenizeSearchName(string $text): array
    {
        $normalized = (string) preg_replace('/\s+/u', ' ', trim($text));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[\s　]+/u', $normalized) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && mb_strlen($part) >= 1) {
                $tokens[] = $part;
            }
        }

        return $tokens;
    }
}
