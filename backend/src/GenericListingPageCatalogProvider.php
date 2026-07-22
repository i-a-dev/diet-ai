<?php

declare(strict_types=1);

/**
 * 公式一覧ページの HTML リンクから詳細 URL を探す汎用提供者。
 */
final class GenericListingPageCatalogProvider implements OfficialCatalogProvider
{
    /** @var callable(string): ?string|null */
    private $htmlFetcher;

    /**
     * @param callable(string): ?string|null $htmlFetcher
     */
    public function __construct(
        private readonly OfficialSiteBrandResolver $officialSiteBrandResolver = new OfficialSiteBrandResolver(),
        ?callable $htmlFetcher = null,
    ) {
        $this->htmlFetcher = $htmlFetcher;
    }

    public function supports(string $officialDomain, ?string $brandName): bool
    {
        return trim($officialDomain) !== '' && $officialDomain !== 'nosh.jp';
    }

    public function discover(FoodSearchSubject $subject, WebSearchBudget $budget): array
    {
        $domain = $this->officialSiteBrandResolver->resolveOfficialSite($subject->brandName, $subject->rawInput);
        if ($domain === null || $domain === 'nosh.jp') {
            return [];
        }

        $pathHint = $this->officialSiteBrandResolver->officialDetailPathHint($subject->brandName, $subject->rawInput);
        if ($pathHint === null) {
            return [];
        }

        $seed = 'https://' . $domain . '/';
        $html = $this->fetch($seed);
        if ($html === null || $html === '') {
            return [];
        }

        $queryName = trim($subject->productName !== '' ? $subject->productName : $subject->rawInput);
        $evaluator = new ProductMatchEvaluator();
        $candidates = [];
        $seen = [];

        if (preg_match_all('/href=["\'](https?:\/\/[^"\']+|\/[^"\']+)["\']/iu', $html, $matches) === false) {
            return [];
        }

        foreach ($matches[1] as $href) {
            $url = $this->absolutize($href, $domain);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            if (!str_contains(mb_strtolower(parse_url($url, PHP_URL_PATH) ?: ''), mb_strtolower($pathHint))) {
                continue;
            }

            $seen[$url] = true;
            $path = (string) parse_url($url, PHP_URL_PATH);
            $slug = trim((string) basename($path), '/');
            $guessName = $queryName !== '' ? $queryName : $slug;
            $titleMatch = $evaluator->analyzeTitleMatch($queryName, $guessName, $subject->brandName);
            if (($titleMatch['has_distinct_cores'] ?? false) === true) {
                continue;
            }

            $candidates[] = new OfficialCatalogCandidate(
                url: $url,
                productName: $guessName,
                brandName: $subject->brandName,
            );
            if (count($candidates) >= 5) {
                break;
            }
        }

        return $candidates;
    }

    private function absolutize(string $href, string $domain): ?string
    {
        $href = trim(html_entity_decode($href));
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            $href = 'https://' . $domain . $href;
        }
        if (!str_starts_with($href, 'http')) {
            return null;
        }

        $host = mb_strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($host !== $domain && !str_ends_with($host, '.' . $domain)) {
            return null;
        }

        return $href;
    }

    private function fetch(string $url): ?string
    {
        if ($this->htmlFetcher !== null) {
            return ($this->htmlFetcher)($url);
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DietAI/1.0)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($body) && $code < 400 ? $body : null;
    }
}
