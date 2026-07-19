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
    ) {
    }

    /**
     * @param list<array{title: string, url: string, description: string}> $results
     * @return list<array{url: string, score: int, title: string, description: string}>
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
            $score = $this->scoreUrl(
                $url,
                $title,
                $description,
                $normalizedProduct,
                $normalizedBrand,
                $index,
                $searchMode,
            );

            $ranked[] = [
                'url' => $url,
                'score' => $score,
                'title' => $title,
                'description' => $description,
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $ranked;
    }

    private function scoreUrl(
        string $url,
        string $title,
        string $description,
        string $productName,
        string $brandName,
        int $braveIndex,
        string $searchMode,
    ): int {
        $score = max(0, 100 - ($braveIndex * 5));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));
        $segments = $this->pathSegments($path);
        $titleLower = $this->normalize($title);
        $descLower = $this->normalize($description);
        $urlLower = $this->normalize($url);
        $preferListPages = in_array($searchMode, ['variant_list_page', 'product_list_page'], true);
        $titleHasProduct = $productName !== '' && str_contains($titleLower, $productName);
        $isOfficial = $this->pageExtractor->isOfficialUrl($url);

        if ($isOfficial) {
            $score += 120;
        }

        foreach (self::LOW_PRIORITY_HOST_MARKERS as $marker) {
            if (str_contains($host, $marker)) {
                $score -= 80;
            }
        }

        if ($titleHasProduct) {
            $score += 40;
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

        // 公式なのにタイトルに商品名が無い（メニューTOP等）
        if ($isOfficial && !$titleHasProduct) {
            $score -= 40;
        }

        return $score;
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
