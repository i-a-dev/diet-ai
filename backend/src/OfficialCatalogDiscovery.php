<?php

declare(strict_types=1);

/**
 * 公式カタログ提供者を束ね、検索ミス時に商品 URL を補完する。
 */
final class OfficialCatalogDiscovery
{
    /** @var list<OfficialCatalogProvider> */
    private array $providers;

    /**
     * @param list<OfficialCatalogProvider>|null $providers
     */
    public function __construct(
        private readonly OfficialSiteBrandResolver $officialSiteBrandResolver = new OfficialSiteBrandResolver(),
        ?array $providers = null,
    ) {
        $this->providers = $providers ?? [
            new NoshCatalogProvider(),
            new GenericSitemapCatalogProvider(),
            new GenericListingPageCatalogProvider(),
        ];
    }

    /**
     * @param list<array{title?: string, url?: string, description?: string}> $searchResults
     */
    public function shouldRun(FoodSearchSubject $subject, array $searchResults): bool
    {
        $domain = $this->officialSiteBrandResolver->resolveOfficialSite($subject->brandName, $subject->rawInput);
        if ($domain === null) {
            return false;
        }

        $pathHint = $this->officialSiteBrandResolver->officialDetailPathHint($subject->brandName, $subject->rawInput);
        $productName = trim($subject->productName);
        $evaluator = new ProductMatchEvaluator();

        foreach ($searchResults as $result) {
            $url = trim((string) ($result['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== $domain && !str_ends_with($host, '.' . $domain)) {
                continue;
            }
            if ($pathHint !== null && !str_contains(mb_strtolower((string) parse_url($url, PHP_URL_PATH)), mb_strtolower($pathHint))) {
                continue;
            }

            $title = trim((string) ($result['title'] ?? ''));
            if ($productName === '' || $title === '') {
                return false;
            }

            $match = $evaluator->analyzeTitleMatch($productName, $title, $subject->brandName);
            if (
                ($match['has_distinct_cores'] ?? false) !== true
                && (
                    ($match['has_exact_phrase'] ?? false) === true
                    || (float) ($match['token_coverage'] ?? 0.0) >= 0.9
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<OfficialCatalogCandidate>
     */
    public function discover(FoodSearchSubject $subject, WebSearchBudget $budget): array
    {
        $domain = $this->officialSiteBrandResolver->resolveOfficialSite($subject->brandName, $subject->rawInput);
        if ($domain === null) {
            return [];
        }

        $all = [];
        $seen = [];
        foreach ($this->providers as $provider) {
            if (!$provider->supports($domain, $subject->brandName)) {
                continue;
            }
            foreach ($provider->discover($subject, $budget) as $candidate) {
                $url = trim($candidate->url);
                if ($url === '' || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $all[] = $candidate;
            }
        }

        return $all;
    }
}
