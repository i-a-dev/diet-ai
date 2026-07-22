<?php

declare(strict_types=1);

/**
 * 公式ページ探索専用予算（詳細HTML取得予算とは分離）。
 */
final class OfficialDiscoveryBudget
{
    public const MAX_ROBOTS_FETCHES = 1;
    public const MAX_SITEMAP_FETCHES = 3;
    public const MAX_SITEMAP_URLS_INSPECTED = 500;
    public const MAX_LISTING_PAGE_FETCHES = 3;
    public const MAX_LISTING_LINKS_INSPECTED = 300;
    public const MAX_CRAWL_DEPTH = 1;
    public const MAX_DISCOVERED_CANDIDATES = 50;
    public const MAX_SITEMAP_INDEX_DEPTH = 2;

    private int $robotsFetches = 0;
    private int $sitemapFetches = 0;
    private int $sitemapUrlsInspected = 0;
    private int $listingPageFetches = 0;
    private int $listingLinksInspected = 0;
    private int $discoveredCandidates = 0;
    private bool $exhausted = false;

    public function canFetchRobots(): bool
    {
        return $this->robotsFetches < self::MAX_ROBOTS_FETCHES;
    }

    public function recordRobotsFetch(): void
    {
        $this->robotsFetches++;
    }

    public function canFetchSitemap(): bool
    {
        return $this->sitemapFetches < self::MAX_SITEMAP_FETCHES;
    }

    public function recordSitemapFetch(): void
    {
        $this->sitemapFetches++;
    }

    public function canInspectSitemapUrl(): bool
    {
        return $this->sitemapUrlsInspected < self::MAX_SITEMAP_URLS_INSPECTED;
    }

    public function recordSitemapUrlInspected(): void
    {
        $this->sitemapUrlsInspected++;
    }

    public function canFetchListingPage(): bool
    {
        return $this->listingPageFetches < self::MAX_LISTING_PAGE_FETCHES;
    }

    public function recordListingPageFetch(): void
    {
        $this->listingPageFetches++;
    }

    public function canInspectListingLink(): bool
    {
        return $this->listingLinksInspected < self::MAX_LISTING_LINKS_INSPECTED;
    }

    public function recordListingLinkInspected(): void
    {
        $this->listingLinksInspected++;
    }

    public function canAcceptCandidate(): bool
    {
        return $this->discoveredCandidates < self::MAX_DISCOVERED_CANDIDATES;
    }

    public function recordCandidate(): void
    {
        $this->discoveredCandidates++;
        if ($this->discoveredCandidates >= self::MAX_DISCOVERED_CANDIDATES) {
            $this->exhausted = true;
        }
    }

    public function markExhausted(): void
    {
        $this->exhausted = true;
    }

    public function isExhausted(): bool
    {
        return $this->exhausted;
    }

    /**
     * @return array<string, int|bool>
     */
    public function snapshot(): array
    {
        return [
            'robotsFetches' => $this->robotsFetches,
            'sitemapFetches' => $this->sitemapFetches,
            'sitemapUrlsInspected' => $this->sitemapUrlsInspected,
            'listingPageFetches' => $this->listingPageFetches,
            'listingLinksInspected' => $this->listingLinksInspected,
            'discoveredCandidates' => $this->discoveredCandidates,
            'exhausted' => $this->exhausted,
        ];
    }
}
