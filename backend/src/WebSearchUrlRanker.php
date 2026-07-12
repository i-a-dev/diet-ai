<?php

declare(strict_types=1);

/**
 * Brave 検索結果 URL の一覧ページ候補を順位付けする。
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

    /** @var list<string> */
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

    public function __construct(
        private readonly NutritionPageExtractor $pageExtractor = new NutritionPageExtractor(),
    ) {
    }

    /**
     * @param list<array{title: string, url: string, description: string}> $results
     * @return list<array{url: string, score: int, title: string, description: string}>
     */
    public function rank(array $results, string $productName, ?string $brandName = null): array
    {
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
            $score = $this->scoreUrl($url, $title, $description, $normalizedProduct, $normalizedBrand, $index);

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
    ): int {
        $score = max(0, 100 - ($braveIndex * 5));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $titleLower = $this->normalize($title);
        $descLower = $this->normalize($description);
        $urlLower = $this->normalize($url);

        if ($this->pageExtractor->isOfficialUrl($url)) {
            $score += 120;
        }

        foreach (self::LOW_PRIORITY_HOST_MARKERS as $marker) {
            if (str_contains($host, $marker)) {
                $score -= 80;
            }
        }

        if ($productName !== '' && str_contains($titleLower, $productName)) {
            $score += 40;
        }

        if ($brandName !== '' && (str_contains($titleLower, $brandName) || str_contains($descLower, $brandName))) {
            $score += 20;
        }

        foreach (self::LIST_PAGE_MARKERS as $marker) {
            $markerLower = $this->normalize($marker);
            if (str_contains($titleLower, $markerLower) || str_contains($urlLower, $markerLower) || str_contains($descLower, $markerLower)) {
                $score += 15;
            }
        }

        if ($productName !== '' && str_contains($descLower, $productName)) {
            $score += 10;
        }

        return $score;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
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
