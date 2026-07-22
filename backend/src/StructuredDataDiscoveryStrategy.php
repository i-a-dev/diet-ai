<?php

declare(strict_types=1);

/**
 * JSON-LD（Product / MenuItem / ItemList）から候補を抽出する。
 */
final class StructuredDataDiscoveryStrategy implements OfficialPageDiscoveryStrategy
{
    public function __construct(
        private readonly OfficialDiscoveryHttpClient $http = new OfficialDiscoveryHttpClient(),
        private readonly OfficialDiscoveryCandidateFactory $factory = new OfficialDiscoveryCandidateFactory(),
    ) {
    }

    public function name(): string
    {
        return 'structured_data';
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
        foreach (array_slice($site->profile->absoluteSeedUrls(), 0, $site->profile->maxListingPages) as $seedUrl) {
            if (!$budget->canFetchListingPage()) {
                break;
            }
            // listing と予算を共有しないよう、ここでは robots/sitemap 以外の軽量取得として listing カウントを使う
            $budget->recordListingPageFetch();
            $html = $http->fetch($seedUrl, $site->domain());
            if ($html === null || $html === '') {
                continue;
            }

            foreach ($this->extractJsonLdBlocks($html) as $json) {
                foreach ($this->walkJsonLd($json, $site->profile->structuredDataTypes) as $node) {
                    if (!$budget->canAcceptCandidate()) {
                        $budget->markExhausted();
                        break 3;
                    }
                    $name = isset($node['name']) ? trim((string) $node['name']) : null;
                    $url = isset($node['url']) ? trim((string) $node['url']) : '';
                    if ($url === '' && isset($node['@id'])) {
                        $url = trim((string) $node['@id']);
                    }
                    if ($url !== '' && str_starts_with($url, '/')) {
                        $url = 'https://' . $site->domain() . $url;
                    }
                    if ($url === '') {
                        continue;
                    }
                    $kcal = $this->extractKcalHint($node);
                    $candidate = $this->factory->create(
                        $url,
                        $name,
                        'json_ld',
                        $subject,
                        $site->profile,
                        ['json_ld', 'structured_data'],
                        $kcal,
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
     * @return list<array<string, mixed>>
     */
    private function extractJsonLdBlocks(string $html): array
    {
        $blocks = [];
        if (preg_match_all(
            '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/isu',
            $html,
            $matches,
        ) === false) {
            return [];
        }

        foreach ($matches[1] as $raw) {
            $raw = trim(html_entity_decode($raw));
            if ($raw === '' || strlen($raw) > 500_000) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $blocks[] = $decoded;
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed>|list<mixed> $node
     * @param list<string> $types
     * @return list<array<string, mixed>>
     */
    private function walkJsonLd(array $node, array $types, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $found = [];
        if ($this->isAssoc($node)) {
            $type = $node['@type'] ?? null;
            $typeOk = false;
            if (is_string($type) && in_array($type, $types, true)) {
                $typeOk = true;
            } elseif (is_array($type)) {
                foreach ($type as $t) {
                    if (is_string($t) && in_array($t, $types, true)) {
                        $typeOk = true;
                        break;
                    }
                }
            }
            if ($typeOk) {
                $found[] = $node;
            }
            if (isset($node['itemListElement']) && is_array($node['itemListElement'])) {
                foreach ($node['itemListElement'] as $item) {
                    if (is_array($item)) {
                        $found = array_merge($found, $this->walkJsonLd($item, $types, $depth + 1));
                    }
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $found = array_merge($found, $this->walkJsonLd($value, $types, $depth + 1));
                }
            }
        } else {
            foreach ($node as $value) {
                if (is_array($value)) {
                    $found = array_merge($found, $this->walkJsonLd($value, $types, $depth + 1));
                }
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function extractKcalHint(array $node): ?int
    {
        $nutrition = $node['nutrition'] ?? null;
        if (!is_array($nutrition)) {
            return null;
        }
        $calories = $nutrition['calories'] ?? $nutrition['calorie'] ?? null;
        if (is_numeric($calories)) {
            return (int) $calories;
        }
        if (is_string($calories) && preg_match('/(\d+)/', $calories, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
