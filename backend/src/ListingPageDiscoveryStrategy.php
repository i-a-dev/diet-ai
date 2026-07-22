<?php

declare(strict_types=1);

/**
 * 一覧ページから商品詳細 URL を発見する汎用 Strategy。
 *
 * - seed / トップを取得
 * - a[href], aria-label, 一般的な data-*name 属性から候補抽出
 * - 同一公式ドメインのみ
 * - 追跡深度は原則1
 */
final class ListingPageDiscoveryStrategy implements OfficialPageDiscoveryStrategy
{
    public function __construct(
        private readonly OfficialDiscoveryHttpClient $http = new OfficialDiscoveryHttpClient(),
        private readonly OfficialDiscoveryCandidateFactory $factory = new OfficialDiscoveryCandidateFactory(),
        private readonly OfficialPathPatternMatcher $pathMatcher = new OfficialPathPatternMatcher(),
        private readonly OfficialDiscoveryIndexCache $cache = new OfficialDiscoveryIndexCache(),
    ) {
    }

    public function name(): string
    {
        return 'listing_page';
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

        $index = $this->loadOrBuildIndex($site, $budget, $http);
        $out = [];
        foreach ($index as $item) {
            if (!$budget->canAcceptCandidate()) {
                $budget->markExhausted();
                break;
            }
            $candidate = $this->factory->create(
                (string) ($item['url'] ?? ''),
                isset($item['candidate_name']) ? (string) $item['candidate_name'] : null,
                $this->name(),
                $subject,
                $site->profile,
                is_array($item['evidence'] ?? null) ? $item['evidence'] : ['listing_page'],
                isset($item['kcal_hint']) && is_numeric($item['kcal_hint']) ? (int) $item['kcal_hint'] : null,
            );
            if ($candidate === null) {
                continue;
            }
            if (!$this->factory->passesSubjectFilter($candidate, $subject)) {
                continue;
            }
            $out[] = $candidate;
            $budget->recordCandidate();
        }

        return $out;
    }

    /**
     * @return list<array{url: string, candidate_name?: string|null, discovery_source?: string, evidence?: list<string>, kcal_hint?: int|null}>
     */
    private function loadOrBuildIndex(
        OfficialSiteContext $site,
        OfficialDiscoveryBudget $budget,
        OfficialDiscoveryHttpClient $http,
    ): array {
        $cached = $this->cache->get($site->domain(), $site->profile->profileVersion);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $items = [];
        $seen = [];
        $seedUrls = $site->profile->absoluteSeedUrls();
        $maxPages = min($site->profile->maxListingPages, OfficialDiscoveryBudget::MAX_LISTING_PAGE_FETCHES);

        foreach (array_slice($seedUrls, 0, $maxPages) as $seedUrl) {
            if (!$budget->canFetchListingPage()) {
                $budget->markExhausted();
                break;
            }
            $budget->recordListingPageFetch();
            $html = $http->fetch($seedUrl, $site->domain());
            if ($html === null || $html === '') {
                continue;
            }

            foreach ($this->extractFromHtml($html, $site) as $row) {
                if (!$budget->canInspectListingLink()) {
                    $budget->markExhausted();
                    break 2;
                }
                $budget->recordListingLinkInspected();
                $url = $row['url'];
                if (isset($seen[$url])) {
                    if (($seen[$url]['candidate_name'] ?? '') === '' && ($row['candidate_name'] ?? '') !== '') {
                        $seen[$url] = $row;
                    }
                    continue;
                }
                $seen[$url] = $row;
            }
        }

        $items = array_values($seen);
        if ($items !== []) {
            $this->cache->put($site->domain(), $site->profile->profileVersion, $items);
        }

        return $items;
    }

    /**
     * @return list<array{url: string, candidate_name?: string|null, discovery_source?: string, evidence?: list<string>, kcal_hint?: int|null}>
     */
    private function extractFromHtml(string $html, OfficialSiteContext $site): array
    {
        $items = [];
        $domain = $site->domain();

        // 1) data-*name 属性ブロック近傍の詳細リンク
        if (preg_match_all(
            '/<[^>]+data-[a-z0-9_-]*name=["\']([^"\']+)["\'][^>]*>/iu',
            $html,
            $nameMatches,
            PREG_OFFSET_CAPTURE,
        ) > 0) {
            foreach ($nameMatches[0] as $index => $match) {
                $offset = (int) $match[1];
                $chunk = substr($html, $offset, 3000);
                $productName = html_entity_decode(trim($nameMatches[1][$index][0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = $this->findDetailUrlInChunk($chunk, $site);
                if ($url === null || $productName === '') {
                    continue;
                }
                $kcal = null;
                if (preg_match('/data-(?:calories|kcal|calorie)=["\'](\d+)["\']/i', $chunk, $kcalMatch) === 1) {
                    $kcal = (int) $kcalMatch[1];
                }
                $items[] = [
                    'url' => $url,
                    'candidate_name' => $productName,
                    'discovery_source' => $this->name(),
                    'evidence' => ['listing_page', 'data_name_attr'],
                    'kcal_hint' => $kcal,
                ];
            }
        }

        // 2) a[href] + aria-label / テキスト
        if (preg_match_all(
            '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu',
            $html,
            $anchorMatches,
            PREG_SET_ORDER,
        )) {
            foreach ($anchorMatches as $anchor) {
                $href = trim(html_entity_decode($anchor[1]));
                $url = $this->absolutize($href, $domain);
                if ($url === null) {
                    continue;
                }
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
                if (!$this->pathMatcher->looksLikeDetailPath($path, $site->profile)) {
                    continue;
                }
                if (!$this->pathMatcher->matchesAny($path, $site->profile->allowedPathPatterns)) {
                    continue;
                }

                $attrs = $anchor[0];
                $name = '';
                if (preg_match('/aria-label=["\']([^"\']+)["\']/iu', $attrs, $aria) === 1) {
                    $name = html_entity_decode(trim($aria[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if ($name === '') {
                    $inner = trim(html_entity_decode(strip_tags($anchor[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $inner = preg_replace('/\s+/u', ' ', $inner) ?? $inner;
                    if (mb_strlen($inner) >= 2 && mb_strlen($inner) <= 80) {
                        $name = $inner;
                    }
                }

                $items[] = [
                    'url' => $url,
                    'candidate_name' => $name !== '' ? $name : null,
                    'discovery_source' => $this->name(),
                    'evidence' => ['listing_page', 'anchor'],
                    'kcal_hint' => null,
                ];
            }
        }

        return $items;
    }

    private function findDetailUrlInChunk(string $chunk, OfficialSiteContext $site): ?string
    {
        if (preg_match_all('/href=["\']([^"\']+)["\']/iu', $chunk, $hrefMatches)) {
            foreach ($hrefMatches[1] as $href) {
                $url = $this->absolutize(html_entity_decode(trim($href)), $site->domain());
                if ($url === null) {
                    continue;
                }
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
                if ($this->pathMatcher->looksLikeDetailPath($path, $site->profile)
                    && $this->pathMatcher->matchesAny($path, $site->profile->allowedPathPatterns)
                ) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function absolutize(string $href, string $domain): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with(mb_strtolower($href), 'javascript:')) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            $href = 'https://' . $domain . $href;
        }
        if (!str_starts_with($href, 'http://') && !str_starts_with($href, 'https://')) {
            return null;
        }

        $host = mb_strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($host !== $domain && !str_ends_with($host, '.' . $domain)) {
            return null;
        }

        $parts = parse_url($href);
        if (!is_array($parts)) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        $scheme = (string) ($parts['scheme'] ?? 'https');

        return $scheme . '://' . $host . ($path !== '' ? $path : '/');
    }
}
