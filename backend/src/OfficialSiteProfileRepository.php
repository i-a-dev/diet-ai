<?php

declare(strict_types=1);

/**
 * 公式サイト Profile リポジトリ（宣言的データ）。
 */
final class OfficialSiteProfileRepository
{
    /** @var array<string, OfficialSiteProfile>|null */
    private ?array $profilesByDomain = null;

    public function __construct(
        private readonly string $configPath = '',
    ) {
    }

    public function findByDomain(string $domain): ?OfficialSiteProfile
    {
        $domain = mb_strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $map = $this->all();

        return $map[$domain] ?? null;
    }

    public function resolveContext(?string $domain): ?OfficialSiteContext
    {
        if ($domain === null || trim($domain) === '') {
            return null;
        }

        $domain = mb_strtolower(trim($domain));
        $registered = $this->findByDomain($domain);
        if ($registered !== null) {
            return new OfficialSiteContext($registered, 'registered');
        }

        return new OfficialSiteContext(OfficialSiteProfile::generic($domain), 'generic');
    }

    /**
     * @return array<string, OfficialSiteProfile>
     */
    public function all(): array
    {
        if ($this->profilesByDomain !== null) {
            return $this->profilesByDomain;
        }

        $path = $this->configPath !== ''
            ? $this->configPath
            : __DIR__ . '/../config/official_site_profiles.php';

        $rows = [];
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $rows = $loaded;
            }
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['domain'])) {
                continue;
            }
            $domain = mb_strtolower(trim((string) $row['domain']));
            if ($domain === '') {
                continue;
            }
            $map[$domain] = new OfficialSiteProfile(
                domain: $domain,
                brandAliases: array_values(array_map('strval', $row['brandAliases'] ?? [])),
                seedPaths: array_values(array_map('strval', $row['seedPaths'] ?? ['/'])),
                allowedPathPatterns: array_values(array_map('strval', $row['allowedPathPatterns'] ?? ['/...'])),
                detailPathPatterns: array_values(array_map('strval', $row['detailPathPatterns'] ?? [])),
                enabledStrategies: array_values(array_map('strval', $row['enabledStrategies'] ?? [
                    'robots_sitemap',
                    'sitemap',
                    'listing_page',
                    'structured_data',
                    'embedded_json',
                    'search_engine',
                ])),
                maxListingPages: (int) ($row['maxListingPages'] ?? 3),
                maxSitemaps: (int) ($row['maxSitemaps'] ?? 3),
                maxCandidateUrls: (int) ($row['maxCandidateUrls'] ?? 50),
                crawlDepth: (int) ($row['crawlDepth'] ?? 1),
                structuredDataTypes: array_values(array_map('strval', $row['structuredDataTypes'] ?? ['Product', 'MenuItem', 'ItemList'])),
                profileVersion: (int) ($row['profileVersion'] ?? OfficialSiteProfile::VERSION),
            );
        }

        $this->profilesByDomain = $map;

        return $map;
    }
}
