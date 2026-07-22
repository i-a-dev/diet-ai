<?php

declare(strict_types=1);

/**
 * Brave 検索結果 URL を順位付けする。
 *
 * 単品モードではパス構造・タイトル一致で詳細ページを優先し、
 * サイト固有パス辞書は使わない。
 */
final class WebSearchUrlRanker
{
    /** @var list<string> */
    private const LOW_PRIORITY_HOST_MARKERS = [
        'twitter.com',
        'x.com',
        'instagram.com',
        'facebook.com',
        'tiktok.com',
        'note.com',
        'ameblo.jp',
        '5ch.net',
        '2ch.',
    ];

    /**
     * サイズ一覧・商品一覧を探すモード向けの加点キーワード。
     *
     * @var list<string>
     */
    private const LIST_PAGE_MARKERS = [
        '栄養成分',
        'nutrition',
        'calorie',
        'menu',
        'product',
        '商品情報',
        '商品一覧',
        'メニュー',
    ];

    /**
     * パス末尾がこれらだと「カタログ／インデックス」寄りとみなす（サイト固有パスではない）。
     *
     * @var list<string>
     */
    private const INDEX_LAST_SEGMENTS = [
        'menu',
        'menus',
        'product',
        'products',
        'item',
        'items',
        'list',
        'lists',
        'category',
        'categories',
        'catalog',
        'search',
        'nutrition',
        'nutritional-value',
        'calorie',
        'calories',
    ];

    public function __construct(
        private readonly NutritionPageExtractor $pageExtractor = new NutritionPageExtractor(),
        private readonly ProductMatchEvaluator $productMatchEvaluator = new ProductMatchEvaluator(),
    ) {
    }

    /**
     * @param list<array{title: string, url: string, description: string}> $results
     * @return list<array{url: string, score: int, title: string, description: string, title_match?: array<string, mixed>}>
     */
    public function rank(
        array $results,
        string $productName,
        ?string $brandName = null,
        string $searchMode = 'single_product',
    ): array {
        $normalizedProduct = $this->normalize($productName);
        $normalizedBrand = $brandName !== null ? $this->normalize($brandName) : '';
        $seen = [];
        $ranked = [];

        foreach ($results as $index => $result) {
            $url = trim($result['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $canonical = $this->canonicalUrl($url);
            if ($canonical === '' || isset($seen[$canonical])) {
                continue;
            }

            if ($this->pageExtractor->isBlockedSourceUrl($url)) {
                continue;
            }

            $seen[$canonical] = true;
            $title = trim($result['title'] ?? '');
            $description = trim($result['description'] ?? '');
            $extraSnippets = [];
            if (is_array($result['extra_snippets'] ?? null)) {
                foreach ($result['extra_snippets'] as $snippet) {
                    $text = trim((string) $snippet);
                    if ($text !== '') {
                        $extraSnippets[] = $text;
                    }
                }
            }
            $descriptionForScore = trim($description . ' ' . implode(' ', $extraSnippets));
            $titleMatch = $this->productMatchEvaluator->analyzeTitleMatch(
                $productName,
                $this->titleProductHint($title),
                $brandName,
            );
            // extra snippets 上の完全一致もタイトル一致に反映
            if ($extraSnippets !== [] && ($titleMatch['has_exact_phrase'] ?? false) !== true) {
                $snippetMatch = $this->productMatchEvaluator->analyzeTitleMatch(
                    $productName,
                    implode(' ', $extraSnippets),
                    $brandName,
                );
                if (($snippetMatch['has_exact_phrase'] ?? false) === true) {
                    $titleMatch['has_exact_phrase'] = true;
                    $titleMatch['token_coverage'] = max(
                        (float) ($titleMatch['token_coverage'] ?? 0),
                        (float) ($snippetMatch['token_coverage'] ?? 0),
                    );
                }
            }
            $score = $this->scoreUrl(
                $url,
                $title,
                $descriptionForScore,
                $normalizedProduct,
                $normalizedBrand,
                $index,
                $searchMode,
                $titleMatch,
            );

            $ranked[] = [
                'url' => $url,
                'score' => $score,
                'title' => $title,
                'description' => $description,
                'extra_snippets' => $extraSnippets,
                'title_match' => $titleMatch,
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $ranked;
    }

    /**
     * @param array{
     *   name_similarity: float,
     *   core_similarity: float,
     *   has_distinct_cores: bool,
     *   has_exact_phrase: bool,
     *   token_coverage: float
     * } $titleMatch
     */
    private function scoreUrl(
        string $url,
        string $title,
        string $description,
        string $productName,
        string $brandName,
        int $braveIndex,
        string $searchMode,
        array $titleMatch,
    ): int {
        $score = max(0, 100 - ($braveIndex * 5));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));
        $segments = $this->pathSegments($path);
        $titleLower = $this->normalize($title);
        $descLower = $this->normalize($description);
        $urlLower = $this->normalize($url);
        $preferListPages = in_array($searchMode, ['variant_list_page', 'product_list_page'], true);
        $hasExactPhrase = (bool) ($titleMatch['has_exact_phrase'] ?? false);
        $tokenCoverage = (float) ($titleMatch['token_coverage'] ?? 0.0);
        $hasDistinctCores = (bool) ($titleMatch['has_distinct_cores'] ?? false);
        $titleHasProduct = $hasExactPhrase || $tokenCoverage >= 0.67;
        $isOfficial = $this->pageExtractor->isOfficialUrl($url);

        if ($isOfficial) {
            $score += 120;
        }

        foreach (self::LOW_PRIORITY_HOST_MARKERS as $marker) {
            if (str_contains($host, $marker)) {
                $score -= 80;
            }
        }

        if ($hasExactPhrase) {
            $score += 50;
        } elseif ($tokenCoverage >= 0.67) {
            $score += 35;
        } elseif ($tokenCoverage >= 0.50) {
            $score += 15;
        }

        if ($hasDistinctCores) {
            // 公式でも別味（辛旨 vs 甘酢等）は HTML 取得上位に上げない
            $score -= $isOfficial ? 140 : 80;
        }

        if ($brandName !== '' && (str_contains($titleLower, $brandName) || str_contains($descLower, $brandName))) {
            $score += 20;
        }

        if ($preferListPages) {
            foreach (self::LIST_PAGE_MARKERS as $marker) {
                $markerLower = $this->normalize($marker);
                if (
                    str_contains($titleLower, $markerLower)
                    || str_contains($urlLower, $markerLower)
                    || str_contains($descLower, $markerLower)
                ) {
                    $score += 15;
                }
            }
        } else {
            $score += $this->scoreSingleProductStructure($segments, $titleHasProduct, $isOfficial);
        }

        if ($productName !== '' && str_contains($descLower, $productName)) {
            $score += 10;
        }

        // 公式でも全文一致が無く、トークン一致も弱い場合だけ減点する
        if (
            $isOfficial
            && !$hasExactPhrase
            && $tokenCoverage < 0.50
            && !$preferListPages
        ) {
            $score -= 40;
        }

        return $score;
    }

    /**
     * @param list<string> $segments
     */
    private function scoreSingleProductStructure(
        array $segments,
        bool $titleHasProduct,
        bool $isOfficial,
    ): int {
        $score = 0;
        $depth = count($segments);
        $last = $segments !== [] ? $segments[array_key_last($segments)] : '';

        // 末尾が数字ID・深いパス → 商品詳細寄り
        if ($this->looksLikeDetailId($last)) {
            $score += 70;
            if ($depth >= 3) {
                $score += 20;
            }
        } elseif ($depth >= 3 && $titleHasProduct) {
            // IDでなくても、深いパス + タイトル商品名一致は詳細寄り
            $score += 35;
        }

        // 末尾が menu/products 等、または浅いパスで商品名なし → 一覧寄り
        if ($this->looksLikeIndexLastSegment($last) || ($depth <= 2 && !$titleHasProduct)) {
            $score -= 55;
        }

        return $score;
    }

    private function titleProductHint(string $title): string
    {
        $hint = trim((string) preg_replace('/\s*[|｜].*$/u', '', $title));
        $hint = trim((string) preg_replace('/\s*(栄養成分|カロリー|エネルギー).*$/u', '', $hint));

        return $hint !== '' ? $hint : $title;
    }

    private function looksLikeDetailId(string $segment): bool
    {
        if ($segment === '') {
            return false;
        }

        // /menu/detail/1015 のような数値ID
        if (preg_match('/^\d{2,}$/', $segment) === 1) {
            return true;
        }

        // 英数字ID（短すぎる slug は除外）
        return preg_match('/^[a-z0-9][a-z0-9_-]{5,}$/', $segment) === 1
            && preg_match('/\d/', $segment) === 1;
    }

    private function looksLikeIndexLastSegment(string $segment): bool
    {
        if ($segment === '') {
            return true;
        }

        return in_array($segment, self::INDEX_LAST_SEGMENTS, true);
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        $parts = preg_split('/\//', $trimmed) ?: [];

        return array_values(array_filter(
            $parts,
            static fn (string $part): bool => $part !== '',
        ));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        return rtrim(mb_strtolower($path), '/') ?: '/';
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return trim($url);
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        $path = rtrim($path, '/') ?: '/';

        return $host . $path;
    }
}
