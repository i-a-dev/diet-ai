<?php

declare(strict_types=1);

final class BraveSearchClient
{
    private const API_URL = 'https://api.search.brave.com/res/v1/web/search';

    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    /**
     * クエリ内容に応じて site: 演算子付きのフォールバック検索語を返す。
     *
     * @return list<string>
     */
    public function buildFallbackQueries(string $query): array
    {
        $fallbacks = [];
        $lower = mb_strtolower($query);

        $siteRules = [
            ['needles' => ['午後の紅茶', 'キリン'], 'site' => 'products.kirin.co.jp'],
            ['needles' => ['明治', 'エッセル', 'meiji'], 'site' => 'meiji.co.jp'],
            ['needles' => ['日清', 'カップヌードル', 'nissin'], 'site' => 'nissin.com'],
            ['needles' => ['ブルダック', '三養', 'samyang'], 'site' => 'samyangfoods.co.jp'],
            ['needles' => ['セブン', '7premium', 'ななチキ'], 'site' => '7premium.jp'],
            ['needles' => ['ローソン', 'からあげ', 'プレミアムロール'], 'site' => 'lawson.co.jp'],
            ['needles' => ['ファミマ', 'ファミリーマート', 'ファミチキ'], 'site' => 'family.co.jp'],
            ['needles' => ['カルビー', 'じゃがりこ', '堅あげ'], 'site' => 'calbee.co.jp'],
            ['needles' => ['森永', 'inゼリー', 'in jelly'], 'site' => 'morinaga.co.jp'],
            ['needles' => ['ニチレイ'], 'site' => 'nichirei.co.jp'],
            ['needles' => ['味の素', 'シュウマイ'], 'site' => 'ajinomoto.co.jp'],
            ['needles' => ['スターバックス', 'starbucks', 'フラペチーノ'], 'site' => 'starbucks.co.jp'],
            ['needles' => ['サーティワン', '31アイス', '31ice'], 'site' => '31ice.co.jp'],
            ['needles' => ['農心', '辛ラーメン', '農心ジャパン'], 'site' => 'nongshim.co.jp'],
        ];

        foreach ($siteRules as $rule) {
            foreach ($rule['needles'] as $needle) {
                if (mb_strpos($lower, mb_strtolower($needle)) !== false) {
                    $fallbacks[] = $this->appendSiteOperator($query, $rule['site']);
                    break;
                }
            }
        }

        return array_values(array_unique($fallbacks));
    }

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
        if ($this->apiKey === '') {
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
                'X-Subscription-Token: ' . $this->apiKey,
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
            $preview = trim(substr($body, 0, 200));

            return [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => $preview !== ''
                    ? 'Brave Search API の応答を解析できませんでした: ' . $preview
                    : 'Brave Search API の応答を解析できませんでした',
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

            $message = trim((string) ($error['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        if (is_array($decoded['message'] ?? null)) {
            return json_encode($decoded['message'], JSON_UNESCAPED_UNICODE) ?: 'Brave Search API エラー';
        }

        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return 'Brave Search API エラー';
    }
}
