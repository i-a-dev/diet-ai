<?php

declare(strict_types=1);

class BraveSearchService
{
    private const API_URL = 'https://api.search.brave.com/res/v1/web/search';

    /**
     * @return array{
     *   ok: bool,
     *   http_code: int,
     *   error: string|null,
     *   urls: list<string>,
     *   results: list<array{title: string, url: string, description: string, extra_snippets?: list<string>}>
     * }
     */
    public function search(string $query, int $count = 10): array
    {
        $apiKey = trim((string) (getenv('BRAVE_SEARCH_API_KEY') ?: ''));

        if ($apiKey === '') {
            return [
                'ok' => false,
                'http_code' => 0,
                'error' => 'BRAVE_SEARCH_API_KEY が未設定です',
                'urls' => [],
                'results' => [],
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'http_code' => 0,
                'error' => 'curl 拡張が有効になっていません',
                'urls' => [],
                'results' => [],
            ];
        }

        // Brave の search_lang は ISO 639-1 の ja ではなく jp。要件の「日本語検索」は jp で満たす。
        // ui_lang は ja-JP。extra_snippets で追加抜粋を取得する。
        $params = http_build_query([
            'q' => $query,
            'count' => max(1, min($count, 20)),
            'country' => 'JP',
            'search_lang' => 'jp',
            'ui_lang' => 'ja-JP',
            'extra_snippets' => 'true',
        ]);

        $ch = curl_init(self::API_URL . '?' . $params);

        if ($ch === false) {
            return [
                'ok' => false,
                'http_code' => 0,
                'error' => 'Brave Search API への接続を開始できませんでした',
                'urls' => [],
                'results' => [],
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Subscription-Token: ' . $apiKey,
            ],
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => $curlError !== '' ? $curlError : 'Brave Search API リクエストに失敗しました',
                'urls' => [],
                'results' => [],
            ];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => 'Brave Search API の応答を解析できませんでした',
                'urls' => [],
                'results' => [],
            ];
        }

        if ($httpCode >= 400) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => $this->extractErrorMessage($decoded),
                'urls' => [],
                'results' => [],
            ];
        }

        return $this->buildSearchResult($decoded, $httpCode);
    }

    /**
     * 複数クエリを制限付き並列で実行する（結果はクエリ順）。
     *
     * @param list<string> $queries
     * @return list<array{
     *   query: string,
     *   ok: bool,
     *   http_code: int,
     *   error: string|null,
     *   urls: list<string>,
     *   results: list<array{title: string, url: string, description: string, extra_snippets?: list<string>}>,
     *   duration_ms: int
     * }>
     */
    public function searchMany(array $queries, int $count = 10, ?SearchRuntimeContext $runtime = null): array
    {
        $queries = array_values(array_filter(array_map(
            static fn ($q): string => trim((string) $q),
            $queries,
        ), static fn (string $q): bool => $q !== ''));

        if ($queries === []) {
            return [];
        }

        if (count($queries) === 1 || !function_exists('curl_multi_init') || static::class !== self::class) {
            $out = [];
            foreach ($queries as $query) {
                $t0 = hrtime(true);
                $result = $this->search($query, $count);
                $durationMs = (int) round((hrtime(true) - $t0) / 1e6);
                $runtime?->timing->recordHttp([
                    'request_type' => 'brave_search',
                    'summary' => mb_substr($query, 0, 80),
                    'duration_ms' => $durationMs,
                    'http_status' => $result['http_code'] ?? null,
                    'response_size' => null,
                    'timeout' => false,
                    'cache_hit' => false,
                ]);
                $out[] = array_merge($result, ['query' => $query, 'duration_ms' => $durationMs]);
            }

            return $out;
        }

        $apiKey = trim((string) (getenv('BRAVE_SEARCH_API_KEY') ?: ''));
        if ($apiKey === '') {
            return array_map(static fn (string $query): array => [
                'query' => $query,
                'ok' => false,
                'http_code' => 0,
                'error' => 'BRAVE_SEARCH_API_KEY が未設定です',
                'urls' => [],
                'results' => [],
                'duration_ms' => 0,
            ], $queries);
        }

        $connectMs = $runtime?->connectTimeoutMs ?? 2_000;
        $requestMs = $runtime?->requestTimeoutMs ?? 4_000;

        return $this->searchManyWithCurlMulti($queries, $count, $runtime, $apiKey, $connectMs, $requestMs);
    }

    /**
     * @param list<string> $queries
     * @return list<array<string, mixed>>
     */
    private function searchManyWithCurlMulti(
        array $queries,
        int $count,
        ?SearchRuntimeContext $runtime,
        string $apiKey,
        int $connectMs,
        int $requestMs,
    ): array {
        $mh = curl_multi_init();
        if ($mh === false) {
            $out = [];
            foreach ($queries as $query) {
                $t0 = hrtime(true);
                $result = $this->search($query, $count);
                $out[] = array_merge($result, [
                    'query' => $query,
                    'duration_ms' => (int) round((hrtime(true) - $t0) / 1e6),
                ]);
            }

            return $out;
        }

        /** @var list<array{query: string, ch: \CurlHandle, started: int|float}> $jobs */
        $jobs = [];
        foreach ($queries as $query) {
            $params = http_build_query([
                'q' => $query,
                'count' => max(1, min($count, 20)),
                'country' => 'JP',
                'search_lang' => 'jp',
                'ui_lang' => 'ja-JP',
                'extra_snippets' => 'true',
            ]);
            $ch = curl_init(self::API_URL . '?' . $params);
            if ($ch === false) {
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => $connectMs,
                CURLOPT_TIMEOUT_MS => $requestMs,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'X-Subscription-Token: ' . $apiKey,
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $jobs[] = ['query' => $query, 'ch' => $ch, 'started' => hrtime(true)];
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($jobs as $job) {
            $ch = $job['ch'];
            $query = $job['query'];
            $body = curl_multi_getcontent($ch);
            $errno = curl_errno($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $durationMs = (int) round((hrtime(true) - $job['started']) / 1e6);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $runtime?->timing->recordHttp([
                'request_type' => 'brave_search',
                'summary' => mb_substr($query, 0, 80),
                'duration_ms' => $durationMs,
                'http_status' => $httpCode,
                'response_size' => is_string($body) ? strlen($body) : 0,
                'timeout' => in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED], true),
                'cache_hit' => false,
            ]);

            if ($body === false || $errno !== 0) {
                $out[] = [
                    'query' => $query,
                    'ok' => false,
                    'http_code' => $httpCode,
                    'error' => 'Brave Search API リクエストに失敗しました',
                    'urls' => [],
                    'results' => [],
                    'duration_ms' => $durationMs,
                ];
                continue;
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded) || $httpCode >= 400) {
                $out[] = [
                    'query' => $query,
                    'ok' => false,
                    'http_code' => $httpCode,
                    'error' => is_array($decoded) ? $this->extractErrorMessage($decoded) : 'Brave Search API の応答を解析できませんでした',
                    'urls' => [],
                    'results' => [],
                    'duration_ms' => $durationMs,
                ];
                continue;
            }

            $built = $this->buildSearchResult($decoded, $httpCode);
            $out[] = array_merge($built, ['query' => $query, 'duration_ms' => $durationMs]);
        }

        curl_multi_close($mh);

        return $out;
    }

    /**
     * @param list<string> $contextTexts 入力や Claude 特定名など、店舗判定に使う追加テキスト
     * @return list<string>
     */
    public function buildFallbackQueries(string $query, array $contextTexts = []): array
    {
        $haystack = mb_strtolower(trim($query . ' ' . implode(' ', $contextTexts)));
        $fallbacks = [];

        $convenienceRules = [
            [
                'needles' => [
                    'セブンイレブン',
                    'セブン‐イレブン',
                    'セブン-イレブン',
                    'セブンプレミアム',
                    '７プレミアム',
                    '7premium',
                    'ななチキ',
                    'セブン',
                ],
                'sites' => ['sej.co.jp', '7premium.jp'],
            ],
            [
                'needles' => ['ファミリーマート', 'ファミマ', 'ファミチキ'],
                'sites' => ['family.co.jp'],
            ],
            [
                'needles' => ['ローソン', 'からあげ', 'プレミアムロール'],
                'sites' => ['lawson.co.jp'],
            ],
        ];

        foreach ($convenienceRules as $rule) {
            foreach ($rule['needles'] as $needle) {
                if (mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                    foreach ($rule['sites'] as $site) {
                        $fallbacks[] = $this->appendSiteOperator($query, $site);
                    }

                    $coreProductQuery = $this->buildCoreProductCalorieQuery($query);
                    if ($coreProductQuery !== '' && $coreProductQuery !== trim($query)) {
                        foreach ($rule['sites'] as $site) {
                            $fallbacks[] = $this->appendSiteOperator($coreProductQuery, $site);
                        }
                    }
                    break;
                }
            }
        }

        $siteRules = [
            ['needles' => ['午後の紅茶', 'キリン'], 'site' => 'products.kirin.co.jp'],
            ['needles' => ['明治', 'エッセル', 'meiji'], 'site' => 'meiji.co.jp'],
            ['needles' => ['日清', 'カップヌードル', 'nissin'], 'site' => 'nissin.com'],
            ['needles' => ['ブルダック', '三養', 'samyang'], 'site' => 'samyangfoods.co.jp'],
            ['needles' => ['カルビー', 'じゃがりこ', '堅あげ'], 'site' => 'calbee.co.jp'],
            ['needles' => ['森永', 'inゼリー', 'in jelly'], 'site' => 'morinaga.co.jp'],
            ['needles' => ['ニチレイ'], 'site' => 'nichirei.co.jp'],
            ['needles' => ['味の素', 'シュウマイ'], 'site' => 'ajinomoto.co.jp'],
            ['needles' => ['スターバックス', 'starbucks', 'フラペチーノ'], 'site' => 'starbucks.co.jp'],
            ['needles' => ['サーティワン', '31アイス', '31ice'], 'site' => '31ice.co.jp'],
            ['needles' => ['農心', '辛ラーメン'], 'site' => 'nongshim.co.jp'],
        ];

        foreach ($siteRules as $rule) {
            foreach ($rule['needles'] as $needle) {
                if (mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                    $fallbacks[] = $this->appendSiteOperator($query, $rule['site']);
                    break;
                }
            }
        }

        return array_values(array_unique($fallbacks));
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{
     *   ok: bool,
     *   http_code: int,
     *   error: string|null,
     *   urls: list<string>,
     *   results: list<array{title: string, url: string, description: string, extra_snippets?: list<string>}>
     * }
     */
    private function buildSearchResult(array $decoded, int $httpCode): array
    {
        $results = [];
        $urls = [];

        foreach ($decoded['web']['results'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $extraSnippets = [];
            if (is_array($item['extra_snippets'] ?? null)) {
                foreach ($item['extra_snippets'] as $snippet) {
                    $text = trim((string) $snippet);
                    if ($text !== '') {
                        $extraSnippets[] = $text;
                    }
                }
            }

            $result = [
                'title' => trim((string) ($item['title'] ?? '')),
                'url' => $url,
                'description' => trim((string) ($item['description'] ?? '')),
            ];
            if ($extraSnippets !== []) {
                $result['extra_snippets'] = $extraSnippets;
            }

            $results[] = $result;
            $urls[] = $url;
        }

        return [
            'ok' => true,
            'http_code' => $httpCode,
            'error' => null,
            'urls' => $urls,
            'results' => $results,
        ];
    }

    private function appendSiteOperator(string $query, string $site): string
    {
        if (str_contains($query, 'site:')) {
            return $query;
        }

        return trim($query) . ' site:' . $site;
    }

    private function buildCoreProductCalorieQuery(string $query): string
    {
        $normalized = trim((string) preg_replace(
            '/\b(栄養成分|エネルギー|kcal|カロリー|site:[^\s]+)\b/u',
            ' ',
            $query,
        ));
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        $brandPrefixes = [
            'セブンイレブン',
            'セブン‐イレブン',
            'セブン-イレブン',
            'セブンプレミアム',
            '７プレミアム',
            '7premium',
            'ファミリーマート',
            'ファミマ',
            'ローソン',
        ];

        foreach ($brandPrefixes as $prefix) {
            $normalized = trim((string) preg_replace(
                '/^' . preg_quote($prefix, '/') . '\s*/u',
                '',
                $normalized,
            ));
        }

        $normalized = trim($normalized);
        if ($normalized === '') {
            return '';
        }

        if (!str_contains(mb_strtolower($normalized), 'カロリー')) {
            $normalized .= ' カロリー';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractErrorMessage(array $decoded): string
    {
        if (is_array($decoded['error'] ?? null)) {
            $error = $decoded['error'];
            $detail = trim((string) ($error['detail'] ?? ''));
            if ($detail !== '') {
                return $detail;
            }
        }

        return 'Brave Search API エラー';
    }
}
