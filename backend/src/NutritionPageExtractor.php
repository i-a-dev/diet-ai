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

        $ranked = $this->rankUrls([$url], ['query' => $productName]);
        $result = $this->probeUrls($ranked, ['query' => $productName], 1);

        return $result['best'];
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
            ['priority' => 90, 'pattern' => '/>(\d{1,4}(?:\.\d+)?)\s*kcal\s*<\/td>/iu'],
            ['priority' => 88, 'pattern' => '/>(\d{1,4}(?:\.\d+)?)\s*kcal\s*<\/div>/iu'],
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
        ];

        foreach ($officialDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
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
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
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
            curl_close($ch);

            if ($body !== false && $httpCode < 400 && is_string($body) && $body !== '') {
                return $body;
            }

            if ($attempt < self::FETCH_MAX_ATTEMPTS) {
                usleep(300000);
            }
        }

        return null;
    }
}
