<?php

declare(strict_types=1);

/**
 * サイトマップから公式詳細 URL を探す汎用提供者。
 */
final class GenericSitemapCatalogProvider implements OfficialCatalogProvider
{
    /** @var callable(string): ?string|null */
    private $xmlFetcher;

    /**
     * @param callable(string): ?string|null $xmlFetcher
     */
    public function __construct(
        private readonly OfficialSiteBrandResolver $officialSiteBrandResolver = new OfficialSiteBrandResolver(),
        ?callable $xmlFetcher = null,
    ) {
        $this->xmlFetcher = $xmlFetcher;
    }

    public function supports(string $officialDomain, ?string $brandName): bool
    {
        return trim($officialDomain) !== '';
    }

    public function discover(FoodSearchSubject $subject, WebSearchBudget $budget): array
    {
        $domain = $this->officialSiteBrandResolver->resolveOfficialSite($subject->brandName, $subject->rawInput);
        if ($domain === null || $domain === 'nosh.jp') {
            // nosh は専用 provider を優先
            return [];
        }

        $pathHint = $this->officialSiteBrandResolver->officialDetailPathHint($subject->brandName, $subject->rawInput);
        if ($pathHint === null) {
            return [];
        }

        $sitemapUrls = [
            'https://' . $domain . '/sitemap.xml',
            'https://www.' . $domain . '/sitemap.xml',
        ];

        $queryName = trim($subject->productName !== '' ? $subject->productName : $subject->rawInput);
        $candidates = [];
        foreach ($sitemapUrls as $sitemapUrl) {
            $xml = $this->fetch($sitemapUrl);
            if ($xml === null || $xml === '') {
                continue;
            }

            if (preg_match_all('#<loc>\s*(https?://[^<]+)\s*</loc>#i', $xml, $matches) !== false) {
                foreach ($matches[1] as $loc) {
                    $loc = trim(html_entity_decode($loc));
                    if ($loc === '' || !str_contains($loc, $pathHint)) {
                        continue;
                    }
                    $host = mb_strtolower((string) parse_url($loc, PHP_URL_HOST));
                    if ($host === '' || (!str_ends_with($host, $domain) && $host !== $domain)) {
                        continue;
                    }

                    $titleGuess = $queryName;
                    $candidates[] = new OfficialCatalogCandidate(
                        url: $loc,
                        productName: $titleGuess,
                        brandName: $subject->brandName,
                    );
                    if (count($candidates) >= 5) {
                        return $candidates;
                    }
                }
            }
        }

        return $candidates;
    }

    private function fetch(string $url): ?string
    {
        if ($this->xmlFetcher !== null) {
            return ($this->xmlFetcher)($url);
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
