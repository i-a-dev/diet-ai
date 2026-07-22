<?php

declare(strict_types=1);

/**
 * Claude 検索計画から Brave 検索クエリを固定テンプレートで生成する。
 */
final class NutritionSearchQueryBuilder
{
    public function __construct(
        private readonly OfficialSiteBrandResolver $officialSiteBrandResolver = new OfficialSiteBrandResolver(),
    ) {
    }

    /**
     * @return list<string> 最大4件（互換用。buildSearchQueries へ委譲）
     */
    public function build(FoodWebSearchPlan $plan, string $userInput = ''): array
    {
        return $this->buildSearchQueries($userInput, $plan);
    }

    /**
     * 目的の異なる Brave 検索クエリを最大4件生成する。
     *
     * 推奨順:
     * 1. 公式商品ページ検索
     * 2. 一般的な商品名＋カロリー検索
     * 3. ブランド＋主要商品トークン＋栄養成分検索
     * 4. バリアント・サイズ・商品一覧検索（likelyHasVariants 時のみ）
     *
     * @return list<string>
     */
    public function buildSearchQueries(string $userInput, FoodWebSearchPlan $plan): array
    {
        if ($plan->searchMode === 'no_web_search' || !$plan->isFood) {
            return [];
        }

        $subject = (new FoodSearchSubjectNormalizer($this->officialSiteBrandResolver, $this))->normalize(
            $userInput !== '' ? $userInput : trim(($plan->brandName ?? '') . ' ' . $plan->normalizedProductName),
        );

        $productName = $plan->normalizedProductName !== ''
            ? $plan->normalizedProductName
            : $subject->productName;
        // plan にブランドが残っている場合は subject の brand-free 名を優先
        if (
            $subject->productName !== ''
            && $plan->brandName === null
            && $subject->brandName !== null
            && str_contains($productName, $subject->brandName)
        ) {
            $productName = $subject->productName;
        }
        if ($productName === '') {
            return [];
        }

        $brandName = $plan->brandName ?? $subject->brandName;
        $rawInput = $subject->rawInput !== '' ? $subject->rawInput : $this->normalizeUserSearchBase($userInput);
        $officialSite = $this->resolveOfficialSite($brandName, $productName !== '' ? $productName : $rawInput);
        $coreTokens = $subject->productTokens !== []
            ? $subject->productTokens
            : $this->extractCoreSearchTokens($productName, $brandName);
        $coreTokenText = $coreTokens !== [] ? implode(' ', $coreTokens) : $productName;

        $ordered = [];

        // 1. 公式詳細向け site クエリ（ブランド名は冗長なので付けない）
        if ($officialSite !== null) {
            $pathHint = $this->officialSiteBrandResolver->officialDetailPathHint($brandName, $productName);
            if ($pathHint !== null && $coreTokenText !== '') {
                $ordered[] = 'site:' . $officialSite . $pathHint . ' ' . $coreTokenText;
            }
            $ordered[] = 'site:' . $officialSite . ' ' . $productName;
        }

        // 2. raw input を含む一般カロリー検索
        if ($rawInput !== '') {
            $ordered[] = trim($rawInput . ' カロリー');
        }

        // 3. 商品名 + ブランドエイリアス + 栄養成分（バリアント検索が必要なときは後回し）
        $aliases = $this->officialSiteBrandResolver->brandAliases($brandName);
        $aliasHint = '';
        foreach ($aliases as $alias) {
            if ($alias !== $brandName && !str_contains(mb_strtolower($productName), mb_strtolower($alias))) {
                $aliasHint = $alias;
                break;
            }
        }
        $nutritionQuery = trim($productName . ($aliasHint !== '' ? ' ' . $aliasHint : '') . ' 栄養成分');

        // 4. バリアント・サイズ・一覧（根拠があるときだけ・上限内に残す）
        if ($plan->likelyHasVariants) {
            $variantBase = trim(($brandName !== null ? $brandName . ' ' : '') . $productName);
            $ordered[] = match ($plan->searchMode) {
                'product_list_page' => trim($variantBase . ' 商品一覧 内容量'),
                default => trim($variantBase . ' サイズ カロリー'),
            };
            if ($nutritionQuery !== '栄養成分') {
                $ordered[] = $nutritionQuery;
            }
        } elseif ($nutritionQuery !== '栄養成分') {
            $ordered[] = $nutritionQuery;
        }

        $deduped = $this->uniqueNormalizedQueries($ordered);

        // raw user input を含む検索を最低1件残す
        if ($rawInput !== '' && !$this->queriesContainRawInput($deduped, $rawInput)) {
            array_unshift($deduped, trim($rawInput . ' カロリー'));
            $deduped = $this->uniqueNormalizedQueries($deduped);
        }

        return array_slice($deduped, 0, WebSearchBudget::MAX_TOTAL_BRAVE_SEARCHES);
    }

    /**
     * 追加 Brave 検索用の商品名部分（ユーザー入力の語を落とさない）。
     */
    public function buildAdditionalSearchName(FoodWebSearchPlan $plan, string $userInput = ''): string
    {
        return $this->resolveSearchName($plan, $userInput);
    }

    /** @internal テスト用 */
    public function resolveOfficialSiteForTest(?string $brandName, string $searchName = ''): ?string
    {
        return $this->resolveOfficialSite($brandName, $searchName);
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
        foreach (array_keys($this->officialSiteBrandResolver->brandOfficialSites()) as $brand) {
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
        return $this->officialSiteBrandResolver->resolveOfficialSite($brandName, $searchName);
    }

    /**
     * @param list<string> $queries
     * @return list<string>
     */
    private function uniqueNormalizedQueries(array $queries): array
    {
        $unique = [];
        $seen = [];
        foreach ($queries as $query) {
            $query = trim($query);
            if ($query === '') {
                continue;
            }
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''));
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $unique[] = $query;
        }

        return $unique;
    }

    /**
     * @param list<string> $queries
     */
    private function queriesContainRawInput(array $queries, string $rawInput): bool
    {
        $rawNormalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $rawInput) ?? ''));
        if ($rawNormalized === '') {
            return true;
        }

        foreach ($queries as $query) {
            $queryNormalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''));
            if (str_contains($queryNormalized, $rawNormalized)) {
                return true;
            }
        }

        return false;
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
