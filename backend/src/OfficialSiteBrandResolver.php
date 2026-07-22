<?php

declare(strict_types=1);

/**
 * 公式サイトのブランド解決・ドメイン判定を一元化する。
 */
final class OfficialSiteBrandResolver
{
    /** @var array<string, string> domain => brand */
    private const DOMAIN_BRANDS = [
        'nosh.jp' => 'ナッシュ',
        'mcdonalds.co.jp' => 'マクドナルド',
        'starbucks.co.jp' => 'スターバックス',
        'sej.co.jp' => 'セブンイレブン',
        '7premium.jp' => 'セブンイレブン',
        'lawson.co.jp' => 'ローソン',
        'family.co.jp' => 'ファミリーマート',
        'calbee.co.jp' => 'カルビー',
        'meiji.co.jp' => '明治',
        'morinaga.co.jp' => '森永',
        'nissin.com' => '日清',
        'ajinomoto.co.jp' => '味の素',
        'nichirei.co.jp' => 'ニチレイ',
        'samyangfoods.co.jp' => '三養食品',
        'nongshim.co.jp' => '農心',
        'products.kirin.co.jp' => 'キリン',
        '31ice.co.jp' => 'サーティワン',
        'muji.com' => '無印良品',
        'sanyo-seika.co.jp' => '山芳製菓',
        '8044.jp' => '山芳製菓',
        'sukiya.jp' => 'すき家',
        'yoshinoya.com' => '吉野家',
        'matsuyafoods.co.jp' => '松屋',
    ];

    /** @var array<string, string> brand alias => primary domain */
    private const BRAND_OFFICIAL_SITES = [
        'マクドナルド' => 'mcdonalds.co.jp',
        'マック' => 'mcdonalds.co.jp',
        'mcdonald' => 'mcdonalds.co.jp',
        'すき家' => 'sukiya.jp',
        '吉野家' => 'yoshinoya.com',
        '松屋' => 'matsuyafoods.co.jp',
        'スターバックス' => 'starbucks.co.jp',
        'スタバ' => 'starbucks.co.jp',
        'starbucks' => 'starbucks.co.jp',
        'セブンイレブン' => 'sej.co.jp',
        'セブン' => 'sej.co.jp',
        'ローソン' => 'lawson.co.jp',
        'ファミリーマート' => 'family.co.jp',
        'ファミマ' => 'family.co.jp',
        '山芳製菓' => 'sanyo-seika.co.jp',
        'ナッシュ' => 'nosh.jp',
        'nosh' => 'nosh.jp',
        'カルビー' => 'calbee.co.jp',
        '明治' => 'meiji.co.jp',
        '森永' => 'morinaga.co.jp',
        '日清' => 'nissin.com',
        '味の素' => 'ajinomoto.co.jp',
        'ニチレイ' => 'nichirei.co.jp',
        '三養食品' => 'samyangfoods.co.jp',
        '農心' => 'nongshim.co.jp',
        'キリン' => 'products.kirin.co.jp',
        'サーティワン' => '31ice.co.jp',
        '無印良品' => 'muji.com',
    ];

    public function resolveFromUrl(string $url, ?string $pageTitle = null): ?string
    {
        $host = mb_strtolower(trim((string) parse_url(trim($url), PHP_URL_HOST)));
        if ($host === '') {
            return $this->resolveFromPageTitle($pageTitle);
        }

        foreach (self::DOMAIN_BRANDS as $domain => $brand) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $brand;
            }
        }

        return $this->resolveFromPageTitle($pageTitle);
    }

    /**
     * @return list<string>
     */
    public function resolveOfficialDomains(string $userInput, ?string $brandName): array
    {
        $domains = [];
        $haystack = mb_strtolower(trim(($brandName ?? '') . ' ' . $userInput));

        foreach (self::BRAND_OFFICIAL_SITES as $needle => $site) {
            if ($haystack !== '' && mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                $domains[] = $site;
            }
        }

        if ($brandName !== null && trim($brandName) !== '') {
            $normalizedBrand = $this->normalizeBrandName(trim($brandName));
            foreach (self::DOMAIN_BRANDS as $domain => $brand) {
                if ($brand === $normalizedBrand || mb_strtolower($brand) === mb_strtolower($normalizedBrand)) {
                    $domains[] = $domain;
                }
            }
        }

        return array_values(array_unique($domains));
    }

    public function isOfficialUrl(string $url, string $userInput = '', ?string $brandName = null): bool
    {
        $host = mb_strtolower(trim((string) parse_url(trim($url), PHP_URL_HOST)));
        if ($host === '') {
            return false;
        }

        if ($this->isOfficialHost($host)) {
            if ($userInput === '' && ($brandName === null || trim($brandName) === '')) {
                return true;
            }

            $contextDomains = $this->resolveOfficialDomains($userInput, $brandName);
            if ($contextDomains === []) {
                return true;
            }

            foreach ($contextDomains as $domain) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    return true;
                }
            }

            // 文脈ブランドと不一致でも、既知公式ホストなら公式扱い（ランキング加点用）
            return true;
        }

        return false;
    }

    public function isOfficialHost(string $host): bool
    {
        $host = mb_strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        foreach (array_keys(self::DOMAIN_BRANDS) as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    public function resolveOfficialSite(?string $brandName, string $searchName = ''): ?string
    {
        $haystack = mb_strtolower(trim(($brandName ?? '') . ' ' . $searchName));
        if ($haystack === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach (self::BRAND_OFFICIAL_SITES as $needle => $site) {
            $needleLower = mb_strtolower($needle);
            if ($needleLower === '') {
                continue;
            }
            if (mb_strpos($haystack, $needleLower) !== false && mb_strlen($needleLower) > $bestLen) {
                $best = $site;
                $bestLen = mb_strlen($needleLower);
            }
        }

        return $best;
    }

    /**
     * @return array<string, string>
     */
    public function brandOfficialSites(): array
    {
        return self::BRAND_OFFICIAL_SITES;
    }

    /**
     * @return array<string, string>
     */
    public function domainBrands(): array
    {
        return self::DOMAIN_BRANDS;
    }

    public function officialScoreBonus(string $host): int
    {
        $host = mb_strtolower(trim($host));
        foreach (self::DOMAIN_BRANDS as $domain => $_brand) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return match ($domain) {
                    'mcdonalds.co.jp', 'sukiya.jp', 'yoshinoya.com', 'matsuyafoods.co.jp' => 50,
                    'sej.co.jp', 'lawson.co.jp', 'family.co.jp', 'muji.com' => 40,
                    '7premium.jp' => 45,
                    default => 45,
                };
            }
        }

        return 0;
    }

    /**
     * 入力文字列からブランドを解決する（最長一致）。
     */
    public function detectBrandFromInput(string $userInput): ?string
    {
        $normalized = mb_strtolower(trim($userInput));
        if ($normalized === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach (array_keys(self::BRAND_OFFICIAL_SITES) as $alias) {
            $aliasLower = mb_strtolower($alias);
            if ($aliasLower === '') {
                continue;
            }
            if (mb_strpos($normalized, $aliasLower) !== false && mb_strlen($aliasLower) > $bestLen) {
                $best = $this->normalizeBrandName($alias === 'nosh' ? 'ナッシュ' : $alias);
                $bestLen = mb_strlen($aliasLower);
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    public function brandAliases(?string $brandName): array
    {
        if ($brandName === null || trim($brandName) === '') {
            return [];
        }

        $canonical = $this->normalizeBrandName(trim($brandName));
        $aliases = [$canonical];

        foreach (self::BRAND_OFFICIAL_SITES as $alias => $domain) {
            if ($this->normalizeBrandName($alias === 'nosh' ? 'ナッシュ' : $alias) === $canonical) {
                $aliases[] = $alias;
            }
        }

        // ドメイン先頭ラベル（nosh.jp → nosh）も検索エイリアスに含める
        $site = $this->resolveOfficialSite($canonical, $canonical);
        if ($site !== null) {
            $hostLabel = explode('.', $site)[0] ?? '';
            if ($hostLabel !== '' && $hostLabel !== 'www') {
                $aliases[] = $hostLabel;
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $aliases))));
    }

    /**
     * 公式サイトの詳細ページ向け path hint（商品名ハードコードではない）。
     */
    public function officialDetailPathHint(?string $brandName, string $haystack = ''): ?string
    {
        $site = $this->resolveOfficialSite($brandName, $haystack);
        if ($site === null) {
            return null;
        }

        return match ($site) {
            'nosh.jp' => '/menu/detail',
            'mcdonalds.co.jp' => '/product',
            'sej.co.jp', 'lawson.co.jp', 'family.co.jp' => '/products',
            default => null,
        };
    }

    private function normalizeBrandName(string $brand): string
    {
        return match ($brand) {
            'マック', 'mcdonald' => 'マクドナルド',
            'セブン' => 'セブンイレブン',
            'ファミマ' => 'ファミリーマート',
            'スタバ', 'starbucks' => 'スターバックス',
            'nosh' => 'ナッシュ',
            default => $brand,
        };
    }

    private function resolveFromPageTitle(?string $pageTitle): ?string
    {
        $title = trim((string) $pageTitle);
        if ($title === '') {
            return null;
        }

        if (preg_match('/【[^】]*?(ナッシュ|nosh)[^】]*】/iu', $title) === 1) {
            return 'ナッシュ';
        }

        return null;
    }
}
