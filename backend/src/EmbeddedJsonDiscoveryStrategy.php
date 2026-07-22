<?php

declare(strict_types=1);

/**
 * HTML 埋め込み JSON から商品名と URL を再帰探索する（JS評価なし）。
 */
final class EmbeddedJsonDiscoveryStrategy implements OfficialPageDiscoveryStrategy
{
    private const MAX_JSON_BYTES = 400_000;
    private const MAX_DEPTH = 10;
    private const MAX_NODES = 2_000;

    private int $nodesVisited = 0;

    public function __construct(
        private readonly OfficialDiscoveryHttpClient $http = new OfficialDiscoveryHttpClient(),
        private readonly OfficialDiscoveryCandidateFactory $factory = new OfficialDiscoveryCandidateFactory(),
        private readonly OfficialPathPatternMatcher $pathMatcher = new OfficialPathPatternMatcher(),
    ) {
    }

    public function name(): string
    {
        return 'embedded_json';
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

        $candidates = [];
        foreach (array_slice($site->profile->absoluteSeedUrls(), 0, 1) as $seedUrl) {
            if (!$budget->canFetchListingPage()) {
                break;
            }
            $budget->recordListingPageFetch();
            $html = $http->fetch($seedUrl, $site->domain());
            if ($html === null || $html === '') {
                continue;
            }

            foreach ($this->extractEmbeddedJson($html) as $json) {
                $this->nodesVisited = 0;
                foreach ($this->walk($json, 0) as $pair) {
                    if (!$budget->canAcceptCandidate()) {
                        $budget->markExhausted();
                        break 3;
                    }
                    $url = $pair['url'];
                    if (str_starts_with($url, '/')) {
                        $url = 'https://' . $site->domain() . $url;
                    }
                    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
                    if (!$this->pathMatcher->looksLikeDetailPath($path, $site->profile)) {
                        continue;
                    }
                    $candidate = $this->factory->create(
                        $url,
                        $pair['name'],
                        'embedded_json',
                        $subject,
                        $site->profile,
                        ['embedded_json'],
                    );
                    if ($candidate === null || $candidate->hasDistinctCoreConflict) {
                        continue;
                    }
                    if (!$this->factory->passesSubjectFilter($candidate, $subject)) {
                        continue;
                    }
                    $candidates[] = $candidate;
                    $budget->recordCandidate();
                }
            }
        }

        return $candidates;
    }

    /**
     * @return list<array<mixed>>
     */
    private function extractEmbeddedJson(string $html): array
    {
        $blocks = [];

        // <script type="application/json">
        if (preg_match_all('/<script[^>]+type=["\']application\/json["\'][^>]*>(.*?)<\/script>/isu', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $decoded = $this->decodeJson(trim($raw));
                if ($decoded !== null) {
                    $blocks[] = $decoded;
                }
            }
        }

        // application/json 以外の巨大代入式は誤検出が多いため対象外（script JSON のみ）

        return $blocks;
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        if ($raw === '' || strlen($raw) > self::MAX_JSON_BYTES) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $node
     * @return list<array{url: string, name: ?string}>
     */
    private function walk(array $node, int $depth): array
    {
        if ($depth > self::MAX_DEPTH || $this->nodesVisited > self::MAX_NODES) {
            return [];
        }
        $this->nodesVisited++;

        $found = [];
        $url = null;
        $name = null;
        foreach (['url', 'href', 'link', 'permalink', 'detailUrl', 'detail_url'] as $urlKey) {
            if (isset($node[$urlKey]) && is_string($node[$urlKey]) && $node[$urlKey] !== '') {
                $url = $node[$urlKey];
                break;
            }
        }
        foreach (['name', 'title', 'productName', 'product_name', 'label'] as $nameKey) {
            if (isset($node[$nameKey]) && is_string($node[$nameKey]) && trim($node[$nameKey]) !== '') {
                $name = trim($node[$nameKey]);
                break;
            }
        }
        if ($url !== null) {
            $found[] = ['url' => $url, 'name' => $name];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = array_merge($found, $this->walk($value, $depth + 1));
            }
        }

        return $found;
    }
}
