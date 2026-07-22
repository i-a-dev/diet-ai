<?php

declare(strict_types=1);

/**
 * nosh.jp のメニュー一覧から詳細 URL を発見する。
 *
 * 設定可能なのはサイト構造のみ（商品名・IDはハードコードしない）:
 * - domain=nosh.jp
 * - seed=https://nosh.jp/menu
 * - detail pattern=/menu/detail/{numeric-id}
 */
final class NoshCatalogProvider implements OfficialCatalogProvider
{
    private const DOMAIN = 'nosh.jp';
    private const SEED_URL = 'https://nosh.jp/menu';
    private const DETAIL_PATH_PATTERN = '#https?://(?:www\.)?nosh\.jp/menu/detail/(\d+)#i';

    /** @var callable(string): ?string|null */
    private $htmlFetcher;

    /**
     * @param callable(string): ?string|null $htmlFetcher
     */
    public function __construct(
        private readonly OfficialCatalogCache $cache = new OfficialCatalogCache(),
        ?callable $htmlFetcher = null,
    ) {
        $this->htmlFetcher = $htmlFetcher;
    }

    public function supports(string $officialDomain, ?string $brandName): bool
    {
        $domain = mb_strtolower(trim($officialDomain));
        if ($domain === self::DOMAIN || str_ends_with($domain, '.' . self::DOMAIN)) {
            return true;
        }

        $brand = mb_strtolower(trim((string) $brandName));

        return $brand === 'ナッシュ' || $brand === 'nosh';
    }

    public function discover(FoodSearchSubject $subject, WebSearchBudget $budget): array
    {
        $items = $this->loadCatalogItems($budget);
        if ($items === []) {
            return [];
        }

        $queryName = trim($subject->productName !== '' ? $subject->productName : $subject->rawInput);
        if ($queryName === '') {
            return [];
        }

        $evaluator = new ProductMatchEvaluator();
        $matched = [];
        foreach ($items as $item) {
            $productName = trim((string) ($item['product_name'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($productName === '' || $url === '') {
                continue;
            }

            $titleMatch = $evaluator->analyzeTitleMatch($queryName, $productName, $subject->brandName);
            if (($titleMatch['has_distinct_cores'] ?? false) === true) {
                continue;
            }
            if (
                ($titleMatch['has_exact_phrase'] ?? false) !== true
                && (float) ($titleMatch['token_coverage'] ?? 0.0) < 0.9
                && (float) ($titleMatch['name_similarity'] ?? 0.0) < 0.9
            ) {
                continue;
            }

            $kcal = isset($item['kcal']) && is_numeric($item['kcal']) ? (int) $item['kcal'] : null;
            $matched[] = new OfficialCatalogCandidate(
                url: $url,
                productName: $productName,
                brandName: $subject->brandName ?? 'ナッシュ',
                kcal: $kcal !== null && $kcal > 0 ? $kcal : null,
                source: 'official_catalog',
            );
        }

        return $matched;
    }

    /**
     * @return list<array{url: string, product_name: string, brand_name?: string|null, kcal?: int|null}>
     */
    private function loadCatalogItems(WebSearchBudget $budget): array
    {
        $cached = $this->cache->get(self::DOMAIN);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $html = $this->fetchHtml(self::SEED_URL, $budget);
        if ($html === null || $html === '') {
            return [];
        }

        $items = $this->parseListingHtml($html);
        if ($items !== []) {
            $this->cache->put(self::DOMAIN, $items);
        }

        return $items;
    }

    /**
     * @return list<array{url: string, product_name: string, brand_name?: string|null, kcal?: int|null}>
     */
    private function parseListingHtml(string $html): array
    {
        $items = [];
        $seen = [];

        // data-menu-food-* 属性を持つカード単位で抽出
        if (preg_match_all(
            '/<[^>]+data-menu-food-name="([^"]+)"[^>]*>/iu',
            $html,
            $cardOpenMatches,
            PREG_OFFSET_CAPTURE,
        ) > 0) {
            foreach ($cardOpenMatches[0] as $index => $match) {
                $offset = (int) $match[1];
                $chunk = substr($html, $offset, 2500);
                $productName = html_entity_decode(trim($cardOpenMatches[1][$index][0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($productName === '') {
                    continue;
                }

                if (preg_match(self::DETAIL_PATH_PATTERN, $chunk, $urlMatch) !== 1) {
                    continue;
                }

                $url = 'https://nosh.jp/menu/detail/' . $urlMatch[1];
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                $kcal = null;
                if (preg_match('/data-calories="(\d+)"/i', $chunk, $kcalMatch) === 1) {
                    $kcal = (int) $kcalMatch[1];
                }

                $items[] = [
                    'url' => $url,
                    'product_name' => $productName,
                    'brand_name' => 'ナッシュ',
                    'kcal' => $kcal,
                ];
            }
        }

        if ($items !== []) {
            return $items;
        }

        // フォールバック: aria-label + href
        if (preg_match_all(
            '/<a[^>]+href="(https?:\/\/(?:www\.)?nosh\.jp\/menu\/detail\/\d+)"[^>]*aria-label="([^"]+)"[^>]*>/iu',
            $html,
            $anchorMatches,
            PREG_SET_ORDER,
        ) > 0) {
            foreach ($anchorMatches as $anchor) {
                $url = trim($anchor[1]);
                $productName = html_entity_decode(trim($anchor[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($url === '' || $productName === '' || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $items[] = [
                    'url' => $url,
                    'product_name' => $productName,
                    'brand_name' => 'ナッシュ',
                    'kcal' => null,
                ];
            }
        }

        return $items;
    }

    private function fetchHtml(string $url, WebSearchBudget $budget): ?string
    {
        if ($this->htmlFetcher !== null) {
            return ($this->htmlFetcher)($url);
        }

        // カタログ seed は一覧キャッシュ用。HTML予算は商品詳細用に残すため消費しない。
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DietAI/1.0)',
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $code >= 400) {
            return null;
        }

        return $body;
    }
}
