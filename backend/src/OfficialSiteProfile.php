<?php

declare(strict_types=1);

/**
 * 公式サイトの宣言的プロファイル（データのみ。ビジネスロジックなし）。
 */
final readonly class OfficialSiteProfile
{
    public const VERSION = 1;

    /**
     * @param list<string> $brandAliases
     * @param list<string> $seedPaths
     * @param list<string> $allowedPathPatterns
     * @param list<string> $detailPathPatterns
     * @param list<string> $enabledStrategies
     * @param list<string> $structuredDataTypes
     */
    public function __construct(
        public string $domain,
        public array $brandAliases = [],
        public array $seedPaths = ['/'],
        public array $allowedPathPatterns = ['/...'],
        public array $detailPathPatterns = [],
        public array $enabledStrategies = [
            'robots_sitemap',
            'sitemap',
            'structured_data',
            'listing_page',
            'embedded_json',
            'search_engine',
        ],
        public int $maxListingPages = 3,
        public int $maxSitemaps = 3,
        public int $maxCandidateUrls = 50,
        public int $crawlDepth = 1,
        public array $structuredDataTypes = ['Product', 'MenuItem', 'ItemList'],
        public int $profileVersion = self::VERSION,
    ) {
    }

    public static function generic(string $domain): self
    {
        $domain = mb_strtolower(trim($domain));

        return new self(
            domain: $domain,
            brandAliases: [],
            seedPaths: ['/'],
            allowedPathPatterns: ['/...'],
            detailPathPatterns: [
                '/product/{slug}',
                '/products/{slug}',
                '/item/{slug}',
                '/items/{slug}',
                '/menu/detail/{numeric-id}',
                '/menu/{slug}',
            ],
            enabledStrategies: [
                'robots_sitemap',
                'sitemap',
                'structured_data',
                'listing_page',
                'search_engine',
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function absoluteSeedUrls(): array
    {
        $urls = [];
        foreach ($this->seedPaths as $path) {
            $path = '/' . ltrim(trim($path), '/');
            if ($path === '/') {
                $urls[] = 'https://' . $this->domain . '/';
            } else {
                $urls[] = 'https://' . $this->domain . rtrim($path, '/');
            }
        }

        return array_values(array_unique($urls));
    }
}
