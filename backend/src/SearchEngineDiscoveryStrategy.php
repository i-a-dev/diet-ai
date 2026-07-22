<?php

declare(strict_types=1);

/**
 * Brave 等の検索結果を公式探索候補へ統合する。
 */
final class SearchEngineDiscoveryStrategy implements OfficialPageDiscoveryStrategy
{
    public function __construct(
        private readonly OfficialDiscoveryCandidateFactory $factory = new OfficialDiscoveryCandidateFactory(),
    ) {
    }

    public function name(): string
    {
        return 'search_engine';
    }

    public function supports(OfficialSiteContext $site, DiscoveryEnvironment $environment): bool
    {
        return in_array($this->name(), $site->profile->enabledStrategies, true)
            && $environment->searchResults !== [];
    }

    public function discover(
        FoodSearchSubject $subject,
        OfficialSiteContext $site,
        OfficialDiscoveryBudget $budget,
        DiscoveryEnvironment $environment,
    ): array {
        $candidates = [];
        foreach ($environment->searchResults as $result) {
            if (!$budget->canAcceptCandidate()) {
                $budget->markExhausted();
                break;
            }
            $url = trim((string) ($result['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== $site->domain() && !str_ends_with($host, '.' . $site->domain())) {
                continue;
            }

            $title = trim((string) ($result['title'] ?? ''));
            $candidate = $this->factory->create(
                $url,
                $title !== '' ? $title : null,
                'brave',
                $subject,
                $site->profile,
                ['search_engine', 'brave'],
            );
            if ($candidate === null || $candidate->hasDistinctCoreConflict) {
                continue;
            }
            $candidates[] = $candidate;
            $budget->recordCandidate();
        }

        return $candidates;
    }
}
