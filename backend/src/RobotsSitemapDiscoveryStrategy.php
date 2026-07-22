<?php

declare(strict_types=1);

/**
 * robots.txt から Sitemap URL を収集する。
 */
final class RobotsSitemapDiscoveryStrategy implements OfficialPageDiscoveryStrategy
{
    public function __construct(
        private readonly OfficialDiscoveryHttpClient $http = new OfficialDiscoveryHttpClient(),
        private readonly OfficialDiscoveryCandidateFactory $factory = new OfficialDiscoveryCandidateFactory(),
    ) {
    }

    public function name(): string
    {
        return 'robots_sitemap';
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
        if (!$budget->canFetchRobots()) {
            return [];
        }

        $http = $environment->hasCustomHttpFetcher()
            ? $this->http->withFetcher([$environment, 'fetch'])
            : $this->http;

        $robotsUrl = 'https://' . $site->domain() . '/robots.txt';
        $budget->recordRobotsFetch();
        $body = $http->fetch($robotsUrl, $site->domain());
        if ($body === null || $body === '') {
            return [];
        }

        $candidates = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            if (!$budget->canAcceptCandidate()) {
                $budget->markExhausted();
                break;
            }
            if (preg_match('/^\s*Sitemap\s*:\s*(\S+)/iu', $line, $m) !== 1) {
                continue;
            }
            $sitemapUrl = trim($m[1]);
            $candidate = $this->factory->create(
                $sitemapUrl,
                null,
                $this->name(),
                $subject,
                $site->profile,
                ['robots_sitemap'],
            );
            if ($candidate === null) {
                // sitemap URL は path 制限外でも保持（次 Strategy 用メタ）
                $host = mb_strtolower((string) parse_url($sitemapUrl, PHP_URL_HOST));
                if ($host === $site->domain() || str_ends_with($host, '.' . $site->domain())) {
                    $candidates[] = new DiscoveredPageCandidate(
                        url: $sitemapUrl,
                        candidateName: null,
                        discoverySource: $this->name(),
                        isOfficial: true,
                        nameSimilarity: 0.0,
                        coreSimilarity: 0.0,
                        hasDistinctCoreConflict: false,
                        evidence: ['robots_sitemap'],
                    );
                    $budget->recordCandidate();
                }
                continue;
            }
            $candidates[] = $candidate;
            $budget->recordCandidate();
        }

        return $candidates;
    }
}
