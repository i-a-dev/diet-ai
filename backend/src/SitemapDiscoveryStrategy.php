<?php

declare(strict_types=1);

/**
 * sitemap / sitemap index から商品詳細 URL 候補を抽出する。
 */
final class SitemapDiscoveryStrategy implements OfficialPageDiscoveryStrategy
{
    public function __construct(
        private readonly OfficialDiscoveryHttpClient $http = new OfficialDiscoveryHttpClient(),
        private readonly OfficialDiscoveryCandidateFactory $factory = new OfficialDiscoveryCandidateFactory(),
        private readonly OfficialPathPatternMatcher $pathMatcher = new OfficialPathPatternMatcher(),
    ) {
    }

    public function name(): string
    {
        return 'sitemap';
    }

    public function supports(OfficialSiteContext $site, DiscoveryEnvironment $environment): bool
    {
        return in_array($this->name(), $site->profile->enabledStrategies, true);
    }

    public function discover(
        FoodSearchSubject $subject,
        OfficialSiteContext $site,
        OfficialDiscoveryBudget $budget,
        DiscoveryEnvironment $environment,
    ): array {
        $http = $environment->hasCustomHttpFetcher()
            ? $this->http->withFetcher([$environment, 'fetch'])
            : $this->http;

        $sitemapUrls = [
            'https://' . $site->domain() . '/sitemap.xml',
            'https://www.' . $site->domain() . '/sitemap.xml',
        ];

        // robots Strategy 経由の sitemap URL は environment には載せないため、既定のみ
        $candidates = [];
        $visited = [];

        foreach ($sitemapUrls as $sitemapUrl) {
            if (!$budget->canFetchSitemap()) {
                $budget->markExhausted();
                break;
            }
            $this->collectFromSitemap(
                $sitemapUrl,
                $subject,
                $site,
                $budget,
                $http,
                $candidates,
                $visited,
                0,
            );
        }

        return $candidates;
    }

    /**
     * @param list<DiscoveredPageCandidate> $candidates
     * @param array<string, true> $visited
     */
    private function collectFromSitemap(
        string $sitemapUrl,
        FoodSearchSubject $subject,
        OfficialSiteContext $site,
        OfficialDiscoveryBudget $budget,
        OfficialDiscoveryHttpClient $http,
        array &$candidates,
        array &$visited,
        int $depth,
    ): void {
        $key = mb_strtolower(trim($sitemapUrl));
        if ($key === '' || isset($visited[$key])) {
            return;
        }
        $visited[$key] = true;

        if (!$budget->canFetchSitemap()) {
            $budget->markExhausted();

            return;
        }

        $budget->recordSitemapFetch();
        $xml = $http->fetch($sitemapUrl, $site->domain());
        if ($xml === null || $xml === '') {
            return;
        }

        // sitemap index
        if (str_contains($xml, '<sitemapindex') && $depth < OfficialDiscoveryBudget::MAX_SITEMAP_INDEX_DEPTH) {
            if (preg_match_all('#<\s*loc\s*>\s*(https?://[^<\s]+)\s*<\s*/\s*loc\s*>#iu', $xml, $matches)) {
                foreach ($matches[1] as $child) {
                    if (!$budget->canFetchSitemap()) {
                        break;
                    }
                    $this->collectFromSitemap(
                        html_entity_decode(trim($child)),
                        $subject,
                        $site,
                        $budget,
                        $http,
                        $candidates,
                        $visited,
                        $depth + 1,
                    );
                }
            }

            return;
        }

        if (preg_match_all('#<\s*url\s*>.*?<\s*loc\s*>\s*(https?://[^<\s]+)\s*<\s*/\s*loc\s*>.*?(?:<\s*image:title\s*>\s*([^<]+)\s*<\s*/\s*image:title\s*>)?#isu', $xml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!$budget->canInspectSitemapUrl() || !$budget->canAcceptCandidate()) {
                    $budget->markExhausted();
                    break;
                }
                $budget->recordSitemapUrlInspected();
                $url = html_entity_decode(trim($match[1]));
                $imageTitle = isset($match[2]) ? html_entity_decode(trim($match[2])) : null;
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
                if (!$this->pathMatcher->matchesAny($path, $site->profile->allowedPathPatterns)) {
                    continue;
                }
                if (!$this->pathMatcher->looksLikeDetailPath($path, $site->profile)) {
                    continue;
                }

                $tokens = $this->subjectTokens($subject);
                $haystack = mb_strtolower($url . ' ' . (string) $imageTitle);
                if ($tokens !== [] && !$this->containsAnyToken($haystack, $tokens) && $imageTitle === null) {
                    // URL にトークンが無くタイトルも無い場合はスキップ（全件 HTML 取得を避ける）
                    continue;
                }

                $candidate = $this->factory->create(
                    $url,
                    $imageTitle,
                    $this->name(),
                    $subject,
                    $site->profile,
                    ['sitemap'],
                );
                if ($candidate === null) {
                    continue;
                }
                if ($candidate->hasDistinctCoreConflict) {
                    continue;
                }
                $candidates[] = $candidate;
                $budget->recordCandidate();
            }

            return;
        }

        // 簡易 loc のみ
        if (preg_match_all('#<\s*loc\s*>\s*(https?://[^<\s]+)\s*<\s*/\s*loc\s*>#iu', $xml, $locMatches)) {
            foreach ($locMatches[1] as $loc) {
                if (!$budget->canInspectSitemapUrl() || !$budget->canAcceptCandidate()) {
                    $budget->markExhausted();
                    break;
                }
                $budget->recordSitemapUrlInspected();
                $url = html_entity_decode(trim($loc));
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
                if (!$this->pathMatcher->looksLikeDetailPath($path, $site->profile)) {
                    continue;
                }
                $candidate = $this->factory->create(
                    $url,
                    null,
                    $this->name(),
                    $subject,
                    $site->profile,
                    ['sitemap'],
                );
                if ($candidate === null || $candidate->hasDistinctCoreConflict) {
                    continue;
                }
                $candidates[] = $candidate;
                $budget->recordCandidate();
            }
        }
    }

    /**
     * @return list<string>
     */
    private function subjectTokens(FoodSearchSubject $subject): array
    {
        $name = trim($subject->productName !== '' ? $subject->productName : $subject->rawInput);
        if ($name === '') {
            return [];
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) >= 2) {
                $tokens[] = mb_strtolower($part);
            }
        }

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     */
    private function containsAnyToken(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }
}
