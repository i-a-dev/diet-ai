<?php

declare(strict_types=1);

final class BraveSearchService
{
    private const API_URL = 'https://api.search.brave.com/res/v1/web/search';

    /**
     * @return array{
     *   ok: bool,
     *   http_code: int,
     *   error: string|null,
     *   urls: list<string>,
     *   results: list<array{title: string, url: string, description: string}>
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

        $params = http_build_query([
            'q' => $query,
            'count' => max(1, min($count, 20)),
            'country' => 'JP',
            'search_lang' => 'jp',
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
     *   results: list<array{title: string, url: string, description: string}>
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

            $results[] = [
                'title' => trim((string) ($item['title'] ?? '')),
                'url' => $url,
                'description' => trim((string) ($item['description'] ?? '')),
            ];
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
