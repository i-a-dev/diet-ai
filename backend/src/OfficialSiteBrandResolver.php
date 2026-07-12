<?php

declare(strict_types=1);

/**
 * 公式サイト URL からブランド名を解決する（Haiku が brand null のときの補完用）。
 */
final class OfficialSiteBrandResolver
{
    /** @var array<string, string> */
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
