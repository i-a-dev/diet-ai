<?php

declare(strict_types=1);

/**
 * CalorieEstimateService の HTML 抽出・URL スコアリングを検証用に切り出したクラス。
 * 本番実装前の Brave Search 連携テストで使用する。
 */
final class NutritionKcalProbe
{
    private const MAX_URL_FETCHES = 8;
    private const MIN_URL_FETCH_SCORE = 0;
    private const FETCH_MAX_ATTEMPTS = 2;

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
     * @param list<array{url: string, score: int}> $rankedUrls
     * @param array{query?: string} $context
     * @return array{
     *   attempts: list<array{url: string, score: int, fetch: string, kcal: int|null, pattern_score: int|null, note?: string}>,
     *   best: array{kcal: int, url: string, score: int}|null
     * }
     */
    public function probeUrls(array $rankedUrls, array $context = [], int $maxFetches = self::MAX_URL_FETCHES): array
    {
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

            if ($score < self::MIN_URL_FETCH_SCORE) {
                $attempts[] = $this->attemptRow($url, $score, 'skipped_low_score', null, null);
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

        $meta = $this->resultMetaByUrl[$url] ?? ['title' => '', 'description' => ''];
        $haystack = mb_strtolower($url . ' ' . $meta['title'] . ' ' . $meta['description']);

        foreach ($this->queryKeywords as $keyword) {
            if (mb_strpos($haystack, mb_strtolower($keyword)) !== false) {
                $score += 12;
            }
        }

        foreach ($this->queryPenaltyTerms as $term) {
            if (mb_strpos($haystack, mb_strtolower($term)) !== false) {
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
        $patternDefs = [
            ['priority' => 100, 'pattern' => '/栄養成分表示[^0-9]{0,80}(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 98, 'pattern' => '/エネルギー<\/th>[\s\S]{0,120}?>(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
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

        $best = null;

        foreach ($patternDefs as $def) {
            if (preg_match_all($def['pattern'], $html, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }

            foreach ($matches as $match) {
                $rawValue = (string) ($match[1] ?? '');
                $kcal = (int) round((float) $rawValue);

                if ($kcal < 10 || $kcal > 5000) {
                    continue;
                }

                if ($this->shouldRejectKcalCandidate($kcal, $query, $url, $html)) {
                    continue;
                }

                $hasDecimal = str_contains($rawValue, '.');
                $score = $def['priority'] + ($hasDecimal ? 10 : 0);
                $candidate = [
                    'kcal' => $kcal,
                    'score' => $score,
                    'hasDecimal' => $hasDecimal,
                ];

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
        }

        return $best;
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
