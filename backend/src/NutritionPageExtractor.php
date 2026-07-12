<?php

declare(strict_types=1);

/**
 * Brave Search の検索結果 URL から、HTML 上の栄養成分・カロリー表記を抽出する。
 */
final class NutritionPageExtractor
{
    private const MAX_URL_FETCHES = 8;
    private const MIN_URL_FETCH_SCORE = 0;
    private const FETCH_MAX_ATTEMPTS = 2;

    /** @var list<string> ログイン必須など、参照元として不適切なホスト */
    private const BLOCKED_SOURCE_HOSTS = [
        'eatsmart.jp',
        'calomeal.com',
    ];

    /** @var list<string> */
    private array $queryKeywords = [];

    /** @var list<string> */
    private array $queryPenaltyTerms = [];

    /** @var array<string, array{title: string, description: string}> */
    private array $resultMetaByUrl = [];

    /**
     * @param list<string> $urls
     * @param array{
     *   query?: string,
     *   results?: list<array{title?: string, url?: string, description?: string}>
     * } $context
     * @return list<array{url: string, score: int}>
     */
    public function rankUrls(array $urls, array $context = []): array
    {
        $query = trim((string) ($context['query'] ?? ''));
        $this->queryKeywords = $this->extractQueryKeywords($query);
        $this->queryPenaltyTerms = $this->buildPenaltyTerms($query);
        $this->resultMetaByUrl = [];

        foreach ($context['results'] ?? [] as $result) {
            if (!is_array($result)) {
                continue;
            }

            $url = trim((string) ($result['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $this->resultMetaByUrl[$url] = [
                'title' => trim((string) ($result['title'] ?? '')),
                'description' => trim((string) ($result['description'] ?? '')),
            ];
        }

        $unique = array_values(array_unique(array_filter(array_map('trim', $urls))));

        usort(
            $unique,
            fn (string $a, string $b): int => $this->scoreWebSearchUrl($b, $query) <=> $this->scoreWebSearchUrl($a, $query),
        );

        return array_map(
            fn (string $url): array => ['url' => $url, 'score' => $this->scoreWebSearchUrl($url, $query)],
            $unique,
        );
    }

    /**
     * Claude が返した URL 1件だけを検証する（総当たりしない）。
     *
     * @return array{kcal: int, url: string, score: int}|null
     */
    public function isBlockedSourceUrl(string $url): bool
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (self::BLOCKED_SOURCE_HOSTS as $blockedHost) {
            if ($host === $blockedHost || str_ends_with($host, '.' . $blockedHost)) {
                return true;
            }
        }

        return false;
    }

    public function probeSingleUrl(string $url, string $productName): ?array
    {
        $url = trim($url);
        $productName = trim($productName);

        if ($url === '' || $productName === '') {
            return null;
        }

        $probeQuery = $this->simplifyProbeQuery($productName);
        $ranked = [[
            'url' => $url,
            'score' => $this->scoreWebSearchUrl($url, $probeQuery),
        ]];
        $result = $this->probeUrls($ranked, ['query' => $probeQuery], 1, false);

        return $result['best'];
    }

    /**
     * メーカー公式など、1商品詳細ページから商品名・内容量・kcal を抽出する。
     *
     * @return array{
     *   productName: string,
     *   kcal: int,
     *   packageSize: string|null,
     *   evidenceText: string|null
     * }|null
     */
    public function extractSingleProductCandidate(string $html, string $query, string $url = ''): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        $probeQuery = $this->simplifyProbeQuery(trim($query));
        $kcalResult = $this->extractBestLabeledKcalFromHtml($html, $probeQuery, $url);
        if ($kcalResult === null) {
            return null;
        }

        $heading = $this->extractPageHeadingText($html);
        $productName = $this->extractSingleProductPageName($html, $probeQuery);
        $packageSize = $this->extractPackageSizeFromPageText($html);
        $evidenceText = trim($productName . ($packageSize !== null ? ' ' . $packageSize : '') . ' ' . $kcalResult['kcal'] . 'kcal');

        return [
            'productName' => $productName,
            'kcal' => (int) $kcalResult['kcal'],
            'packageSize' => $packageSize,
            'evidenceText' => $evidenceText !== '' ? mb_substr($evidenceText, 0, 120) : null,
        ];
    }

    /**
     * 公開 URL から HTML を取得する（AI Web 検索の複数バリアント抽出用）。
     */
    public function fetchPageHtml(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || $this->isBlockedSourceUrl($url) || !$this->isSafePublicUrl($url)) {
            return null;
        }

        return $this->fetchPublicHtml($url);
    }

    /**
     * HTML 抽出用に、検索クエリを商品名+サイズ中心の短い文字列へ整える。
     */
    public function simplifyProbeQuery(string $query): string
    {
        $value = trim($query);
        $value = (string) preg_replace('/\s*(カロリー|栄養成分|エネルギー|kcal).*$/iu', '', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        if (preg_match('/^(.*?)(L|M|S)サイズ$/iu', $value, $match) === 1) {
            $base = trim($match[1]);
            $size = strtoupper($match[2]);

            if (mb_strpos($base, 'ポテト') !== false || mb_strpos($base, 'マック') !== false) {
                return 'マックフライポテト ' . $size;
            }

            return $base . ' ' . $size;
        }

        if (preg_match('/^(.*?)(L|M|S)サイズ$/iu', $value, $match) !== 1
            && preg_match('/\b(l|m|s)\b/u', mb_strtolower($value), $sizeMatch) === 1) {
            $size = strtoupper($sizeMatch[1]);
            if (mb_strpos($value, 'ポテト') !== false || mb_strpos($value, 'マック') !== false) {
                return 'マックフライポテト ' . $size;
            }
        }

        return $value;
    }

    /**
     * @param list<array{url: string, score: int}> $rankedUrls
     * @param array{query?: string} $context
     * @return array{
     *   attempts: list<array{url: string, score: int, fetch: string, kcal: int|null, pattern_score: int|null, note?: string}>,
     *   best: array{kcal: int, url: string, score: int}|null
     * }
     */
    public function probeUrls(
        array $rankedUrls,
        array $context = [],
        int $maxFetches = self::MAX_URL_FETCHES,
        bool $requireMinScore = true,
    ): array {
        $query = trim((string) ($context['query'] ?? ''));
        if ($this->queryKeywords === [] && $query !== '') {
            $this->queryKeywords = $this->extractQueryKeywords($query);
            $this->queryPenaltyTerms = $this->buildPenaltyTerms($query);
        }

        $attempts = [];
        $bestResult = null;
        $fetchCount = 0;

        foreach ($rankedUrls as $entry) {
            $url = $entry['url'];
            $score = $entry['score'];

            if ($requireMinScore && $score < self::MIN_URL_FETCH_SCORE) {
                $attempts[] = $this->attemptRow($url, $score, 'skipped_low_score', null, null);
                continue;
            }

            if ($this->isBlockedSourceUrl($url)) {
                $attempts[] = $this->attemptRow($url, $score, 'skipped_blocked_host', null, null);
                continue;
            }

            if (!$this->isSafePublicUrl($url)) {
                $attempts[] = $this->attemptRow($url, $score, 'skipped_unsafe_url', null, null);
                continue;
            }

            if ($fetchCount >= $maxFetches) {
                $attempts[] = $this->attemptRow($url, $score, 'skipped_fetch_limit', null, null);
                continue;
            }

            $fetchCount++;
            $html = $this->fetchPublicHtml($url);

            if ($html === null) {
                $attempts[] = $this->attemptRow($url, $score, 'fetch_failed', null, null);
                continue;
            }

            $resolvedUrl = $this->resolveKaloriVariantUrl($html, $url, $query);
            if ($resolvedUrl !== null && $resolvedUrl !== $url) {
                $resolvedHtml = $this->fetchPublicHtml($resolvedUrl);
                if ($resolvedHtml !== null) {
                    $url = $resolvedUrl;
                    $html = $resolvedHtml;
                }
            }

            $pageBest = $this->extractBestLabeledKcalFromHtml($html, $query, $url);

            if ($pageBest === null && $this->isKirinDetailUrl($url)) {
                $nutritionHtml = $this->fetchKirinNutritionHtml($url);
                if ($nutritionHtml !== null) {
                    $pageBest = $this->extractKirinNutritionRowKcal($nutritionHtml, $query);
                    if ($pageBest !== null) {
                        $pageBest['note'] = 'kirin_nutrition_table';
                    }
                }
            }

            if ($pageBest === null && $this->isFamilyMartGoodsDetailUrl($url)) {
                $pageBest = $this->extractFamilyMartNutritionKcal($html, $query);
            }

            if ($pageBest !== null && !$this->extractedKcalMatchesQueryVariant($html, $query)) {
                $pageBest = null;
            }

            if ($pageBest === null) {
                $attempts[] = $this->attemptRow($url, $score, 'ok_no_kcal', null, null);
                continue;
            }

            $combinedScore = $score * 10 + $pageBest['score'];
            $attempts[] = $this->attemptRow(
                $url,
                $score,
                'ok',
                $pageBest['kcal'],
                $pageBest['score'],
                $pageBest['note'] ?? null,
            );

            $candidate = [
                'kcal' => $pageBest['kcal'],
                'url' => $url,
                'score' => $combinedScore,
                'hasDecimal' => $pageBest['hasDecimal'],
            ];

            if (
                $bestResult === null
                || $candidate['score'] > $bestResult['score']
                || (
                    $candidate['score'] === $bestResult['score']
                    && $candidate['hasDecimal']
                    && !$bestResult['hasDecimal']
                )
            ) {
                $bestResult = $candidate;
            }
        }

        return [
            'attempts' => $attempts,
            'best' => $bestResult === null
                ? null
                : [
                    'kcal' => $bestResult['kcal'],
                    'url' => $bestResult['url'],
                    'score' => $bestResult['score'],
                ],
        ];
    }

    /**
     * @return array{url: string, score: int, fetch: string, kcal: int|null, pattern_score: int|null, note?: string}
     */
    private function attemptRow(
        string $url,
        int $score,
        string $fetch,
        ?int $kcal,
        ?int $patternScore,
        ?string $note = null,
    ): array {
        $row = [
            'url' => $url,
            'score' => $score,
            'fetch' => $fetch,
            'kcal' => $kcal,
            'pattern_score' => $patternScore,
        ];

        if ($note !== null) {
            $row['note'] = $note;
        }

        return $row;
    }

    private function scoreWebSearchUrl(string $url, string $query = ''): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $score = 50;

        $officialDomains = [
            'products.kirin.co.jp' => 45,
            'www.meiji.co.jp' => 45,
            'meiji.co.jp' => 45,
            'www.nissin.com' => 45,
            'nissin.com' => 45,
            'store.nissin.com' => 35,
            'samyangfoods.co.jp' => 45,
            'www.sej.co.jp' => 40,
            '7premium.jp' => 45,
            'www.lawson.co.jp' => 40,
            'www.family.co.jp' => 40,
            'www.calbee.co.jp' => 45,
            'calbee.co.jp' => 45,
            'www.morinaga.co.jp' => 45,
            'morinaga.co.jp' => 45,
            'www.nichirei.co.jp' => 45,
            'nichirei.co.jp' => 45,
            'www.ajinomoto.co.jp' => 45,
            'ajinomoto.co.jp' => 45,
            'www.starbucks.co.jp' => 45,
            'starbucks.co.jp' => 45,
            'www.31ice.co.jp' => 45,
            '31ice.co.jp' => 45,
            'www.nongshim.co.jp' => 45,
            'nongshim.co.jp' => 45,
            'www.muji.com' => 40,
            'greenbeans.com' => 25,
            'kalori.jp' => 20,
            'mcdonalds.co.jp' => 50,
            'www.mcdonalds.co.jp' => 50,
        ];

        foreach ($officialDomains as $domain => $bonus) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $score += $bonus;
                break;
            }
        }

        if (str_contains($path, '/menu/detail/')) {
            $score += 50;
        }

        if (
            str_contains($path, '/products/')
            || str_contains($path, '/product/')
            || str_contains($path, '/goods/')
            || str_contains($path, '/store/g/')
            || str_contains($path, '/Goods/Goods.aspx')
            || str_contains($path, '/item/')
        ) {
            $score += 30;
        }

        if (str_contains($host, 'search.') || str_contains($path, '/search/')) {
            $score -= 60;
        }

        if (str_contains($path, '/review/') || str_contains($host, 'prtimes.jp')) {
            $score -= 40;
        }

        if (
            str_contains($host, 'ameblo.jp')
            || str_contains($host, 'slism.jp')
            || str_contains($host, 'fatsecret.jp')
            || str_contains($host, 'yahoo.co.jp')
            || str_contains($host, 'news.yahoo.co.jp')
        ) {
            $score -= 50;
        }

        if (str_contains($host, 'kurashiru.com') && str_contains($path, '/articles/')) {
            $score -= 55;
        }

        if ($this->isBlockedSourceUrl($url)) {
            $score -= 200;
        }

        if (str_contains($host, 'cookpad.com') && str_contains($path, '/recipe/')) {
            $score -= 35;
        }

        $meta = $this->resultMetaByUrl[$url] ?? ['title' => '', 'description' => ''];
        $haystack = $this->normalizeProductMatchText($url . ' ' . $meta['title'] . ' ' . $meta['description']);
        $metaHaystack = $this->normalizeProductMatchText($meta['title'] . ' ' . $meta['description']);
        $normalizedKeywords = array_map(
            fn (string $keyword): string => $this->normalizeProductMatchText($keyword),
            $this->queryKeywords,
        );
        $keywordMatchCount = $this->countKeywordMatches($haystack, $normalizedKeywords);

        if ($this->queryKeywords !== []) {
            $score += $keywordMatchCount * 15;

            if (!$this->isOfficialHost($host) && $keywordMatchCount === 0) {
                $score -= 60;
            }
        }

        foreach ($this->queryPenaltyTerms as $term) {
            $termLower = mb_strtolower($term);

            if (in_array($termLower, ['pro', 'ｐｒｏ'], true)) {
                if (preg_match('/\bpro\b/ui', $metaHaystack) === 1) {
                    $score -= 35;
                }
                continue;
            }

            if (mb_strpos($metaHaystack, $termLower) !== false) {
                $score -= 35;
            }
        }

        if ($query !== '' && mb_strpos($haystack, '無糖') !== false && mb_strpos(mb_strtolower($query), '無糖') === false) {
            $score -= 40;
        }

        if ($query !== '' && mb_strpos($haystack, 'おいしい無糖') !== false && mb_strpos(mb_strtolower($query), '無糖') === false) {
            $score -= 50;
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function extractQueryKeywords(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        $normalized = (string) preg_replace(
            '/\b(栄養成分|エネルギー|kcal|カロリー|site:[^\s]+)\b/u',
            ' ',
            $normalized,
        );
        $normalized = (string) preg_replace('/\s*\d+(?:\.\d+)?\s*(g|ml|個|杯|切れ|袋|本)\s*/iu', ' ', $normalized);
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        $parts = preg_split('/[\s　]+/u', trim($normalized)) ?: [];
        $keywords = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            $keywords[] = $part;
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @return list<string>
     */
    private function buildPenaltyTerms(string $query): array
    {
        $penalties = [];
        $lower = mb_strtolower($query);

        if (mb_strpos($lower, '無糖') === false) {
            $penalties[] = '無糖';
            $penalties[] = 'おいしい無糖';
            $penalties[] = 'ゼロ';
        }

        if (mb_strpos($lower, 'ビッグ') === false) {
            $penalties[] = 'ビッグ';
        }

        if (mb_strpos($lower, 'プロ') === false && mb_strpos($lower, 'pro') === false) {
            $penalties[] = 'pro';
            $penalties[] = 'ＰＲＯ';
        }

        if (mb_strpos($lower, 'カルボナーラ') === false) {
            $penalties[] = 'カルボナーラ';
        }

        if (mb_strpos($lower, 'ヤンニョム') === false) {
            $penalties[] = 'ヤンニョム';
        }

        if (mb_strpos($lower, 'あっさり') === false) {
            $penalties[] = 'あっさり';
        }

        return $penalties;
    }

    /**
     * @return array{kcal: int, score: int, hasDecimal: bool, note?: string}|null
     */
    private function extractBestLabeledKcalFromHtml(string $html, string $query = '', string $url = ''): ?array
    {
        $keywords = $query !== '' ? $this->extractQueryKeywords($query) : [];
        if ($this->queryKeywords === [] && $keywords !== []) {
            $this->queryKeywords = $keywords;
        }

        $pageHeadingText = $this->normalizeProductMatchText($this->extractPageHeadingText($html));
        $normalizedKeywords = array_map(
            fn (string $keyword): string => $this->normalizeProductMatchText($keyword),
            $keywords,
        );
        $pageKeywordScore = $this->countKeywordMatches($pageHeadingText, $normalizedKeywords);
        $pageMatchesProduct = $keywords !== []
            && $pageKeywordScore >= min(2, count($keywords));

        $patternDefs = [
            ['priority' => 100, 'pattern' => '/栄養成分表示[^0-9]{0,80}(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 98, 'pattern' => '/エネルギー<\/th>[\s\S]{0,120}?>(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 97, 'pattern' => '/熱量[：:]\s*(\d{1,4}(?:\.\d+)?)\s*kcal/iu'],
            ['priority' => 96, 'pattern' => '/熱量[\s\S]{0,80}?<td[^>]*>\s*(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 95, 'pattern' => '/エネルギー\s*(\d{1,4}(?:\.\d+)?)\s*\(\s*[Kk]cal\s*\)/u'],
            ['priority' => 92, 'pattern' => '/エネルギー[^0-9]{0,40}(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 91, 'pattern' => '/<h3[^>]*>[\s\S]*?カロリー[\s\S]*?<\/h3>[\s\S]{0,160}?<p[^>]*>\s*(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 90, 'pattern' => '/>(\d{1,4}(?:\.\d+)?)\s*kcal\s*<\/td>/iu'],
            ['priority' => 89, 'pattern' => '/>(\d{1,4}(?:\.\d+)?)kcal\s*<\//iu'],
            ['priority' => 88, 'pattern' => '/>(\d{1,4}(?:\.\d+)?)\s*kcal\s*<\/div>/iu'],
            ['priority' => 87, 'pattern' => '/>(\d{1,4}(?:\.\d+)?)\s*kcal\s*<\/p>/iu'],
            ['priority' => 85, 'pattern' => '/カロリー[^0-9]{0,40}(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 75, 'pattern' => '/"energy(?:_kcal)?"\s*:\s*"?(\d{1,4}(?:\.\d+)?)"?/iu'],
            ['priority' => 74, 'pattern' => '/"calories?"\s*:\s*(\d{1,4}(?:\.\d+)?)/i'],
            ['priority' => 72, 'pattern' => '/"kcal"\s*:\s*(\d{1,4}(?:\.\d+)?)/i'],
        ];

        /** @var list<array{kcal: int, score: int, hasDecimal: bool, contextKeywordScore: int}> $candidates */
        $candidates = [];

        foreach ($patternDefs as $def) {
            if (preg_match_all($def['pattern'], $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($matches as $match) {
                $rawValue = (string) ($match[1][0] ?? '');
                $offset = (int) ($match[1][1] ?? 0);
                $kcal = (int) round((float) $rawValue);

                if ($kcal < 10 || $kcal > 5000) {
                    continue;
                }

                if ($this->shouldRejectKcalCandidate($kcal, $query, $url, $html)) {
                    continue;
                }

                $context = $this->extractHtmlContext($html, $offset);
                $contextKeywordScore = $this->countKeywordMatches($context, $keywords);
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));

                if ($pageMatchesProduct && $contextKeywordScore === 0) {
                    $contextKeywordScore = 1;
                }

                if ($keywords !== [] && $contextKeywordScore === 0 && !$pageMatchesProduct) {
                    continue;
                }

                $hasDecimal = str_contains($rawValue, '.');
                $score = $def['priority'] + ($hasDecimal ? 10 : 0) + ($contextKeywordScore * 15);
                $candidates[] = [
                    'kcal' => $kcal,
                    'score' => $score,
                    'hasDecimal' => $hasDecimal,
                    'contextKeywordScore' => $contextKeywordScore,
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        $distinctKcals = array_values(array_unique(array_map(
            fn (array $candidate): int => $candidate['kcal'],
            $candidates,
        )));

        if (count($distinctKcals) >= 2) {
            $matchedCandidates = array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => $candidate['contextKeywordScore'] > 0,
            ));

            if ($matchedCandidates === []) {
                return null;
            }

            $candidates = $matchedCandidates;
        }

        $best = null;

        foreach ($candidates as $candidate) {
            if (
                $best === null
                || $candidate['score'] > $best['score']
                || (
                    $candidate['score'] === $best['score']
                    && $candidate['hasDecimal']
                    && !$best['hasDecimal']
                )
            ) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'kcal' => $best['kcal'],
            'score' => $best['score'],
            'hasDecimal' => $best['hasDecimal'],
        ];
    }

    /**
     * @param list<string> $keywords
     */
    private function countKeywordMatches(string $haystack, array $keywords): int
    {
        if ($keywords === []) {
            return 0;
        }

        $lowerHaystack = mb_strtolower($haystack);
        $count = 0;

        foreach ($keywords as $keyword) {
            if (mb_strpos($lowerHaystack, mb_strtolower($keyword)) !== false) {
                $count++;
            }
        }

        return $count;
    }

    private function normalizeProductMatchText(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = str_replace(
            ['７プレミアム', '7premium', 'セブン‐イレブン', 'セブン-イレブン', 'セブンイレブン'],
            ['セブンプレミアム', 'セブンプレミアム', 'セブン', 'セブン', 'セブン'],
            $normalized,
        );
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return $normalized;
    }

    private function extractPageHeadingText(string $html): string
    {
        $parts = [];

        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $match) === 1) {
            $parts[] = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/iu', $html, $match) === 1) {
            $parts[] = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/isu', $html, $match) === 1) {
            $parts[] = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return implode(' ', $parts);
    }

    private function extractSingleProductPageName(string $html, string $fallback): string
    {
        foreach ([
            '/<h1[^>]*>(.*?)<\/h1>/isu',
            '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/iu',
            '/<title[^>]*>(.*?)<\/title>/isu',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match) !== 1) {
                continue;
            }

            $name = $this->normalizeSingleProductPageName(
                html_entity_decode(strip_tags((string) $match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                '',
            );
            if ($name !== '') {
                return $name;
            }
        }

        return $fallback;
    }

    private function normalizeSingleProductPageName(string $heading, string $fallback): string
    {
        $value = html_entity_decode(trim($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/\s*[|｜]\s*.+$/u', '', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        if ($value === '' || mb_strlen($value) < 2) {
            return $fallback;
        }

        return $value;
    }

    private function extractPackageSizeFromPageText(string $html): ?string
    {
        $text = $this->normalizeWhitespace(strip_tags($html));
        $patterns = [
            '/(?:内容量|規格)[:：]?\s*(\d+(?:\.\d+)?)\s*(g|ml|m l|l|リットル)\b/iu',
            '/[（(]\s*(\d+(?:\.\d+)?)\s*(g|ml)\s*[）)]/iu',
            '#(\d+(?:\.\d+)?)\s*(g|ml)\s*[／/]\s*\d*\s*(?:袋|個|本|パック)#iu',
            '/(\d+(?:\.\d+)?)\s*(g|ml)\s*(?:×|x|\*|入|袋|個|本|パック|あたり|当たり|入り)/iu',
            '/(?:袋|個|本|パック|瓶|缶)\s*[（(]?\s*(\d+(?:\.\d+)?)\s*(g|ml)\s*[）)]?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $amount = (string) ($match[1][0] ?? '');
            $unit = (string) ($match[2][0] ?? '');
            $offset = (int) ($match[0][1] ?? 0);
            if ($amount === '' || $unit === '') {
                continue;
            }

            if ($this->isNutritionComponentContext($text, $offset)) {
                continue;
            }

            $normalized = $this->normalizePackageSizeLabel($amount, $unit);
            if ($this->isPlausiblePackageSize($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private function isNutritionComponentContext(string $text, int $byteOffset): bool
    {
        $contextBefore = substr($text, max(0, $byteOffset - 40), min(40, $byteOffset));
        $markers = ['脂質', 'たんぱく質', 'タンパク質', '炭水化物', '食物繊維', '糖質', 'ナトリウム', '塩分'];

        foreach ($markers as $marker) {
            if (mb_strpos($contextBefore, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalizePackageSizeLabel(string $amount, string $unit): string
    {
        $unit = mb_strtolower(str_replace(' ', '', $unit));
        if ($unit === 'l' || $unit === 'リットル') {
            return $amount . 'L';
        }

        return $amount . ($unit === 'ml' || $unit === 'm l' ? 'ml' : 'g');
    }

    private function isPlausiblePackageSize(string $amount): bool
    {
        if (preg_match('/^(\d+(?:\.\d+)?)g$/iu', $amount, $match) === 1) {
            return (float) $match[1] >= 10;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)ml$/iu', $amount, $match) === 1) {
            return (float) $match[1] >= 30;
        }

        return false;
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function extractHtmlContext(string $html, int $offset, int $radius = 180): string
    {
        if ($html === '') {
            return '';
        }

        $length = mb_strlen($html);
        $start = max(0, $offset - $radius);
        $end = min($length, $offset + $radius);

        return (string) mb_substr($html, $start, $end - $start);
    }

    public function isOfficialUrl(string $url): bool
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return $host !== '' && $this->isOfficialHost($host);
    }

    /**
     * ユーザー入力と候補商品名の同一性を判定する。
     *
     * @return 'high'|'medium'|'low'
     */
    public function assessProductIdentity(
        string $userInput,
        string $candidateProductName,
        ?string $brand = null,
    ): string {
        $userNormalized = $this->normalizeIdentityText($userInput);
        $candidateNormalized = $this->normalizeIdentityText($candidateProductName);

        if ($userNormalized === '' || $candidateNormalized === '') {
            return 'low';
        }

        if ($userNormalized === $candidateNormalized) {
            return 'high';
        }

        $userTokens = $this->extractIdentityTokens($userInput);
        $candidateTokens = $this->extractIdentityTokens($candidateProductName);

        if ($userTokens === [] || $candidateTokens === []) {
            return 'low';
        }

        $brandNormalized = $brand !== null && trim($brand) !== ''
            ? $this->normalizeIdentityText($brand)
            : '';
        $userHasBrandHint = $this->inputContainsBrandHint($userNormalized, $brandNormalized, $candidateTokens);
        $extraCandidateTokens = array_values(array_diff($candidateTokens, $userTokens));
        $missingUserTokens = array_values(array_diff($userTokens, $candidateTokens));

        if ($missingUserTokens !== []) {
            $matchedUserTokens = array_values(array_intersect($userTokens, $candidateTokens));
            if ($matchedUserTokens === []) {
                return 'low';
            }

            return 'medium';
        }

        if ($extraCandidateTokens === []) {
            return 'high';
        }

        $extraBrandTokens = array_values(array_filter(
            $extraCandidateTokens,
            fn (string $token): bool => $this->isKnownBrandToken($token),
        ));

        if ($extraBrandTokens !== [] && !$userHasBrandHint) {
            return 'medium';
        }

        if ($this->tokensAreEquivalentSuperset($userTokens, $candidateTokens)) {
            return $userHasBrandHint ? 'high' : 'medium';
        }

        return 'medium';
    }

    /**
     * Brave 検索結果のタイトル等から商品名らしき文字列を推定する。
     */
    public function inferProductNameFromMeta(string $url, string $fallback = ''): string
    {
        $meta = $this->resultMetaByUrl[$url] ?? ['title' => '', 'description' => ''];
        $title = trim((string) ($meta['title'] ?? ''));

        if ($title === '') {
            return $fallback;
        }

        $title = (string) preg_replace('/\s*[|｜\-–—].*$/u', '', $title);
        $title = (string) preg_replace('/\s*(栄養成分|カロリー|エネルギー).*$/u', '', $title);
        $title = (string) preg_replace('/\s*\d+(?:\.\d+)?\s*kcal.*$/iu', '', $title);
        $title = (string) preg_replace('/\s+\d{2,4}$/u', '', $title);
        $title = (string) preg_replace('/の$/u', '', $title);
        $title = trim($title);

        return $title !== '' ? $title : $fallback;
    }

    private function isOfficialHost(string $host): bool
    {
        $officialDomains = [
            'products.kirin.co.jp',
            'meiji.co.jp',
            'nissin.com',
            'samyangfoods.co.jp',
            'sej.co.jp',
            '7premium.jp',
            'lawson.co.jp',
            'family.co.jp',
            'calbee.co.jp',
            'morinaga.co.jp',
            'nichirei.co.jp',
            'ajinomoto.co.jp',
            'starbucks.co.jp',
            '31ice.co.jp',
            'nongshim.co.jp',
            'muji.com',
            'mcdonalds.co.jp',
            'nosh.jp',
        ];

        foreach ($officialDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * kalori.jp の商品ページで、クエリのサイズに合う兄弟商品 URL を返す。
     */
    private function resolveKaloriVariantUrl(string $html, string $url, string $query): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || !str_contains($host, 'kalori.jp')) {
            return null;
        }

        $variantMarker = $this->extractKaloriVariantMarker($query);
        if ($variantMarker === null) {
            return null;
        }

        if (preg_match_all(
            '/href="(\/ja\/shops\/[^"]+\/products\/\d+\/)"[^>]*>([^<]*)</iu',
            $html,
            $matches,
            PREG_SET_ORDER,
        ) >= 1) {
            foreach ($matches as $match) {
                $linkText = (string) ($match[2] ?? '');
                if (mb_strpos($linkText, $variantMarker) !== false) {
                    return 'https://kalori.jp' . $match[1];
                }
            }
        }

        return null;
    }

    private function extractedKcalMatchesQueryVariant(string $html, string $query): bool
    {
        $variantMarker = $this->extractKaloriVariantMarker($query);
        if ($variantMarker === null) {
            return true;
        }

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $match) === 1) {
            $title = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($title === '' && preg_match('/property="og:title"\s+content="([^"]+)"/iu', $html, $match) === 1) {
            $title = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $haystack = mb_strtolower($title);

        return match ($variantMarker) {
            '(L)' => preg_match('/\(l\)|lサイズ|ｌサイズ/u', $haystack) === 1,
            '(M)' => preg_match('/\(m\)|mサイズ|ｍサイズ/u', $haystack) === 1,
            '(S)' => preg_match('/\(s\)|sサイズ|ｓサイズ/u', $haystack) === 1,
            default => true,
        };
    }

    private function extractKaloriVariantMarker(string $query): ?string
    {
        $normalized = mb_strtolower(trim($query));

        if (preg_match('/\(l\)|lサイズ|ｌサイズ|ポテト l|フライド.*\bl\b/u', $normalized) === 1) {
            return '(L)';
        }

        if (preg_match('/\(m\)|mサイズ|ｍサイズ|ポテト m|フライド.*\bm\b/u', $normalized) === 1) {
            return '(M)';
        }

        if (preg_match('/\(s\)|sサイズ|ｓサイズ|ポテト s|フライド.*\bs\b/u', $normalized) === 1) {
            return '(S)';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractIdentityTokens(string $text): array
    {
        $normalized = $this->normalizeIdentityText($text);
        $normalized = (string) preg_replace(
            '/\b(栄養成分|エネルギー|kcal|カロリー)\b/u',
            ' ',
            $normalized,
        );
        $normalized = (string) preg_replace('/\s*\d+(?:\.\d+)?\s*(g|ml|個|杯|切れ|袋|本)\s*/iu', ' ', $normalized);
        $normalized = (string) preg_replace('/\s+/u', ' ', trim($normalized));

        $parts = preg_split('/[\s　]+/u', $normalized) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    private function normalizeIdentityText(string $text): string
    {
        return $this->normalizeProductMatchText($text);
    }

    /**
     * @param list<string> $candidateTokens
     */
    private function inputContainsBrandHint(
        string $userNormalized,
        string $brandNormalized,
        array $candidateTokens,
    ): bool {
        if ($brandNormalized !== '' && str_contains($userNormalized, $brandNormalized)) {
            return true;
        }

        foreach ($candidateTokens as $token) {
            if (!$this->isKnownBrandToken($token)) {
                continue;
            }

            if (str_contains($userNormalized, $token)) {
                return true;
            }
        }

        return $this->textContainsAny($userNormalized, [
            'セブン',
            'セブンプレミアム',
            'ファミリーマート',
            'ファミマ',
            'ローソン',
            'カルビー',
            '東ハト',
            '明治',
            '森永',
            '日清',
            '味の素',
        ]);
    }

    private function isKnownBrandToken(string $token): bool
    {
        return $this->textContainsAny($token, [
            'セブン',
            'セブンプレミアム',
            'ファミリーマート',
            'ファミマ',
            'ローソン',
            'カルビー',
            '東ハト',
            '明治',
            '森永',
            '日清',
            '味の素',
            'キリン',
            'サントリー',
            'コカコーラ',
            'ポッキー',
            'グリコ',
            'ハウス',
            '永谷園',
            'ナッシュ',
            'nosh',
        ]);
    }

    /**
     * @param list<string> $userTokens
     * @param list<string> $candidateTokens
     */
    private function tokensAreEquivalentSuperset(array $userTokens, array $candidateTokens): bool
    {
        foreach ($userTokens as $token) {
            if (!in_array($token, $candidateTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $needles
     */
    private function textContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }

    private function shouldRejectKcalCandidate(int $kcal, string $query, string $url, string $html): bool
    {
        $lowerQuery = mb_strtolower($query);

        if (
            mb_strpos($lowerQuery, '無糖') === false
            && mb_strpos(mb_strtolower($url . ' ' . $html), '無糖') !== false
            && $kcal <= 30
        ) {
            return true;
        }

        if (
            mb_strpos($lowerQuery, 'ミルクティー') !== false
            && mb_strpos(mb_strtolower($url), 'id=8223') !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * @return array{kcal: int, score: int, hasDecimal: bool, note?: string}|null
     */
    private function extractKirinNutritionRowKcal(string $html, string $query): ?array
    {
        if (!preg_match_all(
            '/<tr>[\s\S]*?<\/tr>/iu',
            $html,
            $rows,
        )) {
            return null;
        }

        $best = null;

        foreach ($rows[0] as $rowHtml) {
            if (!preg_match('/午後の紅茶|キリン/u', $rowHtml)) {
                continue;
            }

            $matchScore = 0;
            foreach ($this->queryKeywords as $keyword) {
                if (mb_stripos($rowHtml, $keyword) !== false) {
                    $matchScore += 10;
                }
            }

            if ($matchScore < 20) {
                continue;
            }

            if (
                mb_stripos($query, '無糖') === false
                && (mb_stripos($rowHtml, '無糖') !== false || mb_stripos($rowHtml, 'ゼロ') !== false)
            ) {
                continue;
            }

            if (!preg_match(
                '/製品100ml当たり[\s\S]*?<td[^>]*class="[^"]*align-c[^"]*"[^>]*>\s*(\d{1,4}(?:\.\d+)?)\s*<\/td>/iu',
                $rowHtml,
                $energyMatch,
            )) {
                continue;
            }

            $per100ml = (float) $energyMatch[1];
            if ($per100ml <= 0) {
                continue;
            }

            $volumeMl = 100;
            if (preg_match('/(\d{2,4})\s*ml/iu', $rowHtml, $volumeMatch) === 1) {
                $volumeMl = (int) $volumeMatch[1];
            }

            $kcal = (int) round($per100ml * ($volumeMl / 100));
            if ($kcal < 10 || $kcal > 5000) {
                continue;
            }

            $candidate = [
                'kcal' => $kcal,
                'score' => 105 + $matchScore,
                'hasDecimal' => false,
                'note' => "kirin_table_{$volumeMl}ml",
            ];

            if ($best === null || $candidate['score'] > $best['score']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function isKirinDetailUrl(string $url): bool
    {
        return str_contains($url, 'products.kirin.co.jp/softdrink/softdrink/detail.html');
    }

    private function isFamilyMartGoodsDetailUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host !== 'www.family.co.jp' && $host !== 'family.co.jp') {
            return false;
        }

        return preg_match('#/goods/[^/]+/\d+\.html$#', $path) === 1;
    }

    /**
     * ファミリーマート公式商品ページの栄養成分表（item_nutritional_info）から熱量を抽出する。
     *
     * @return array{kcal: int, score: int, hasDecimal: bool, note?: string}|null
     */
    private function extractFamilyMartNutritionKcal(string $html, string $query): ?array
    {
        if (!preg_match(
            '/<div[^>]*class="item_nutritional_info"[^>]*>[\s\S]*?<td[^>]*class="tit_nut"[^>]*>\s*熱量[\s\S]*?<\/tr>\s*<tr>[\s\S]*?<td[^>]*class="con_nut"[^>]*>\s*(\d{1,4}(?:\.\d+)?)\s*<\/td>/iu',
            $html,
            $match,
        )) {
            return null;
        }

        $rawValue = (string) ($match[1] ?? '');
        $kcal = (int) round((float) $rawValue);

        if ($kcal < 10 || $kcal > 5000) {
            return null;
        }

        if ($this->shouldRejectKcalCandidate($kcal, $query, '', $html)) {
            return null;
        }

        return [
            'kcal' => $kcal,
            'score' => 110,
            'hasDecimal' => str_contains($rawValue, '.'),
            'note' => 'family_mart_nutrition_table',
        ];
    }

    private function fetchKirinNutritionHtml(string $detailUrl): ?string
    {
        if (!preg_match('/[?&]id=(\d+)/', $detailUrl, $matches)) {
            return null;
        }

        $nutritionUrl = 'https://products.kirin.co.jp/softdrink/nutrition/softdrink/?id=' . $matches[1];

        return $this->fetchPublicHtml($nutritionUrl);
    }

    private function isSafePublicUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || $host === 'localhost') {
            return false;
        }

        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = gethostbynamel($host);

            if ($resolved === false || $resolved === []) {
                return false;
            }

            $ips = $resolved;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($ip);

            return $normalized !== '::1'
                && !str_starts_with($normalized, 'fe80:')
                && !str_starts_with($normalized, 'fc')
                && !str_starts_with($normalized, 'fd');
        }

        return false;
    }

    private const MAX_HTML_BYTES = 3145728;

    private function fetchPublicHtml(string $url): ?string
    {
        if (!function_exists('curl_init') || !$this->isSafePublicUrl($url)) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        for ($attempt = 1; $attempt <= self::FETCH_MAX_ATTEMPTS; $attempt++) {
            $ch = curl_init($url);

            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: ja-JP,ja;q=0.9,en;q=0.8',
                    'Referer: https://' . $host . '/',
                ],
            ]);

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if (
                $body !== false
                && $httpCode < 400
                && is_string($body)
                && $body !== ''
                && str_contains(mb_strtolower($contentType), 'html')
                && strlen($body) <= self::MAX_HTML_BYTES
            ) {
                return $body;
            }

            if ($attempt < self::FETCH_MAX_ATTEMPTS) {
                usleep(300000);
            }
        }

        return null;
    }
}
