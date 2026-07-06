<?php

declare(strict_types=1);

/**
 * Claude Haiku 4.5 で食品名からカロリー（kcal）を推定するサービス。
 * ① Web検索なしで推定 → high なら終了、not_food ならエラー
 * ② medium / low のときだけ Web検索で再推定する。
 */
final class CalorieEstimateService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const SYSTEM_PROMPT = 'あなたは日本の食品・料理全般に詳しいカロリー推定の専門家です。口に入れて摂取するもの（料理・飲み物・お菓子・ゼリー・サプリ・機能性食品・市販品）は食品として扱ってください。明らかに食べないもの（洗剤・化粧品・金属など）だけ {"error":"not_food"} を返してください。商品名が不明確でも食べ物の可能性がある場合は not_food にせず推定してください。食品の場合はJSONのみ返答し、前置きや説明は不要です。';
    private const WEB_SEARCH_SYSTEM_PROMPT = 'あなたは日本の食品・料理全般に詳しいカロリー推定の専門家です。口に入れて摂取するもの（料理・飲み物・お菓子・ゼリー・サプリ・機能性食品・市販品）は食品として扱ってください。明らかに食べないものだけ {"error":"not_food"} を返してください。商品名が不明確でも食べ物の可能性がある場合は not_food にせず推定してください。食品の場合は web_search で栄養成分表・エネルギー表示のあるページを優先して確認してから回答してください。販促文の「約○○kcal」だけのページより、「栄養成分表示」「エネルギー ○○kcal」とたんぱく質・脂質・炭水化物が併記されたページを優先してください。ページにカロリー（kcal）の表示がある場合はその数値をそのまま使い、たんぱく質・脂質・糖質から再計算しないでください。最終回答はJSONのみ。前置きや説明は不要です。';
    private const WEB_SEARCH_MAX_TOKENS = 1024;
    private const MAX_WEB_SEARCH_URL_FETCHES = 5;
    private const MIN_URL_FETCH_SCORE = 0;

    /**
     * 食品名からカロリーを推定する（公開 API）。
     * mode:
     * - auto: 従来どおり high 以外で Web 検索を試行
     * - no_web: Web 検索を使わず 1 回のみ推定
     * - web: Web 検索付きで推定
     *
     * @return array{kcal: int, assumed_weight_g?: int, confidence: string, product_name?: string, source_url?: string}
     */
    public function estimate(string $foodName, string $mode = 'auto'): array
    {
        $trimmed = trim($foodName);

        if ($trimmed === '') {
            throw new InvalidArgumentException('食品名を入力してください。');
        }

        if (mb_strlen($trimmed) < 3) {
            throw new InvalidArgumentException('食品名は3文字以上で入力してください。');
        }

        if (mb_strlen($trimmed) > 200) {
            throw new InvalidArgumentException('食品名が長すぎます。');
        }

        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';

        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY が設定されていません。');
        }

        if (!in_array($mode, ['auto', 'no_web', 'web'], true)) {
            throw new InvalidArgumentException('mode must be one of auto, no_web, web.');
        }

        if ($mode === 'web') {
            $webOnly = $this->requestEstimate($trimmed, $apiKey, true);
            if ($webOnly === 'not_food' || $webOnly === null) {
                throw new RuntimeException('カロリーを推定できませんでした。');
            }
            return $webOnly;
        }

        $initial = $this->requestEstimate($trimmed, $apiKey, false);

        if ($initial === 'not_food' || $initial === null) {
            throw new RuntimeException('カロリーを推定できませんでした。');
        }

        if ($mode === 'no_web') {
            return $initial;
        }

        // autoモードはWeb検索しない・推定結果をそのまま返す
        return $initial;
    }

    /**
     * Claude API を1回呼び出し、推定結果を返す。
     *
     * @return array{kcal: int, assumed_weight_g?: int, confidence: string, product_name?: string, source_url?: string}|'not_food'|null
     */
    private function requestEstimate(string $foodName, string $apiKey, bool $withWebSearch): array|string|null
    {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => $withWebSearch ? self::WEB_SEARCH_MAX_TOKENS : 128,
            'system' => $withWebSearch ? self::WEB_SEARCH_SYSTEM_PROMPT : self::SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($foodName, $withWebSearch),
                ],
            ],
        ];

        if ($withWebSearch) {
            $payload['tools'] = [
                [
                    'type' => 'web_search_20250305',
                    'name' => 'web_search',
                    'max_uses' => 1,
                    'user_location' => [
                        'type' => 'approximate',
                        'country' => 'JP',
                        'timezone' => 'Asia/Tokyo',
                    ],
                ],
            ];
        }

        $decoded = $this->postToAnthropic($payload, $apiKey, $withWebSearch);
        $text = $this->extractText($decoded);
        $parsed = $this->parseResponse($text);

        if ($withWebSearch) {
            $htmlResult = $this->extractLabeledKcalFromWebSearchUrls($decoded);
            $sourceUrl = $htmlResult['url'] ?? $this->pickPrimaryWebSearchUrl($decoded);

            if ($htmlResult !== null) {
                if (is_array($parsed)) {
                    $parsed['kcal'] = $htmlResult['kcal'];
                    $parsed['confidence'] = 'high';
                } else {
                    $parsed = [
                        'kcal' => $htmlResult['kcal'],
                        'confidence' => 'high',
                    ];
                }
            }

            if (is_array($parsed) && $sourceUrl !== null) {
                $parsed['source_url'] = $sourceUrl;
            }
        }

        return $parsed;
    }

    /**
     * Claude の Web 検索結果 URL を順に取得し、HTML から表示カロリーを抽出する。
     *
     * @param array<string, mixed> $response
     * @return array{kcal: int, url: string}|null
     */
    private function extractLabeledKcalFromWebSearchUrls(array $response): ?array
    {
        $attempts = 0;
        $bestResult = null;

        foreach ($this->extractRankedWebSearchUrls($response) as $url) {
            if ($this->scoreWebSearchUrl($url) < self::MIN_URL_FETCH_SCORE) {
                continue;
            }

            if (!$this->isSafePublicUrl($url)) {
                continue;
            }

            if ($attempts >= self::MAX_WEB_SEARCH_URL_FETCHES) {
                break;
            }

            $attempts++;
            $html = $this->fetchPublicHtml($url);
            if ($html === null) {
                continue;
            }

            $pageBest = $this->extractBestLabeledKcalFromHtml($html);
            if ($pageBest === null) {
                continue;
            }

            $candidate = [
                'kcal' => $pageBest['kcal'],
                'url' => $url,
                'score' => $pageBest['score'],
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

        if ($bestResult === null) {
            return null;
        }

        return [
            'kcal' => $bestResult['kcal'],
            'url' => $bestResult['url'],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function pickPrimaryWebSearchUrl(array $response): ?string
    {
        foreach ($this->extractRankedWebSearchUrls($response) as $url) {
            if ($this->scoreWebSearchUrl($url) < self::MIN_URL_FETCH_SCORE) {
                continue;
            }

            if (!$this->isSafePublicUrl($url)) {
                continue;
            }

            return $url;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<string>
     */
    private function extractRankedWebSearchUrls(array $response): array
    {
        $urls = [];

        foreach ($response['content'] ?? [] as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'web_search_tool_result') {
                continue;
            }

            foreach ($block['content'] ?? [] as $item) {
                if (!is_array($item) || ($item['type'] ?? '') !== 'web_search_result') {
                    continue;
                }

                $url = trim((string) ($item['url'] ?? ''));
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        $urls = array_values(array_unique($urls));
        usort($urls, fn (string $a, string $b): int => $this->scoreWebSearchUrl($b) <=> $this->scoreWebSearchUrl($a));

        return $urls;
    }

    /**
     * Web 検索 URL の取得優先度。ホワイトリストではなくページ種別のヒューリスティックのみ。
     */
    private function scoreWebSearchUrl(string $url): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $score = 50;

        if (str_contains($path, '/menu/detail/')) {
            $score += 50;
        }

        if (
            str_contains($path, '/products/')
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

        if (str_contains($host, 'ameblo.jp') || str_contains($host, 'slism.jp')) {
            $score -= 50;
        }

        return $score;
    }

    /**
     * HTML から最も信頼できるカロリー表記を1件抽出する。
     * 栄養成分表示・エネルギーを「約」より優先し、小数表記を加点する。
     *
     * @return array{kcal: int, score: int, hasDecimal: bool}|null
     */
    private function extractBestLabeledKcalFromHtml(string $html): ?array
    {
        $patternDefs = [
            ['priority' => 100, 'pattern' => '/栄養成分表示[^0-9]{0,80}(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 90, 'pattern' => '/エネルギー[^0-9]{0,40}(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 80, 'pattern' => '/カロリー[^0-9]{0,40}(\d{1,4}(?:\.\d+)?)\s*kcal/isu'],
            ['priority' => 70, 'pattern' => '/"calories?"\s*:\s*(\d{1,4}(?:\.\d+)?)/i'],
        ];

        $best = null;

        foreach ($patternDefs as $def) {
            if (preg_match($def['pattern'], $html, $matches) !== 1) {
                continue;
            }

            $rawValue = (string) $matches[1];
            $kcal = (int) round((float) $rawValue);
            if ($kcal < 10 || $kcal > 5000) {
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

        return $best;
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
        if (!function_exists('curl_init')) {
            return null;
        }

        if (!$this->isSafePublicUrl($url)) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language: ja-JP,ja;q=0.9',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            return null;
        }

        return is_string($body) ? $body : null;
    }

    /**
     * Claude に送るユーザープロンプトを組み立てる。
     * $withWebSearch が true のときは Web 検索の指示を含める。
     */
    private function buildPrompt(string $foodName, bool $withWebSearch): string
    {
        $searchQueryHint = $this->buildNutritionSearchQueryHint($foodName);

        $webSearchSection = $withWebSearch
            ? <<<TEXT

【Web検索】
- 必ずweb_searchで検索してから回答する
- 検索クエリは次の形式を使う: 「{$searchQueryHint}」
- 量の表記（1本、1個、250g など）は検索クエリから除き、商品名の核心だけを使う
- 次のページを優先する（栄養成分表・エネルギー表示があるもの）:
  - 「栄養成分表示」「エネルギー ○○kcal」とたんぱく質・脂質・炭水化物が併記されたページ
  - メーカー公式、日本食品標準成分表、コンビニ・外食チェーンの公式栄養情報
  - 韓国食品・輸入食品 EC で成分表が載っている商品詳細ページ
- 次のページはカロリー根拠にしない（参考程度）:
  - 販促文の「約○○kcal」だけで栄養成分表がない化粧品 EC・ブランドショップの商品紹介ページ
  - レビューサイト、ブログ、まとめ記事
- 複数ページがある場合は、栄養成分表のあるページの数値を優先する
- ページにエネルギー（kcal）の表示がある場合は、その数値をそのまま kcal に使う
- たんぱく質・脂質・糖質などからカロリーを再計算しない
- 検索で公式のカロリーが見つかった場合はその値を使い、confidenceはhighにする
- 検索で商品名まで特定できた場合は、正式な商品名を product_name に入れる
- 公式ページにグラム(g)表記の重量がある場合のみ assumed_weight_g にその数値を入れる
- 検索しても特定できない場合のみ、下記の推定ルールを使う
TEXT
            : '';

        $confidenceSection = $withWebSearch
            ? <<<'TEXT'
【confidenceの基準】
- high: Web検索または公式情報で商品名とカロリーが特定できた場合（重量gが公式に無くても可）
- medium: 重さを仮定した、または調理法が不明な場合
- low: 食品名が曖昧、または量が全く不明な場合
- 揚げ物・中華料理など油の量が不明な場合は必ずmediumにする
- Web検索しても正確なカロリーが不明な場合はlowにする
TEXT
            : <<<'TEXT'
【confidenceの基準】
- high: 料理名・重さ・カロリーすべて明確に特定できる場合のみ（量の表記がある、または一般的な単位で一意に決まる）
- medium: 重さを仮定した、または調理法が不明な場合
- low: 食品名が曖昧、市販品・外食・定食など公式確認が必要な場合
- 揚げ物・中華料理など油の量が不明な場合は必ずmediumにする
- コンビニ・外食チェーン・市販品・宅配弁当は公式カロリー確認が必要なためhighにしない
TEXT;

        return <<<PROMPT
以下の入力が食品（食べ物・飲み物）かどうかをまず判定し、食品の場合のみカロリーを推定してください。
量の表記がなければ一般的な1食分を仮定してください。

【食品の判定】
- 口に入れて摂取するものは食品として扱う（料理・飲み物・お菓子・ゼリー・サプリ・機能性食品・市販品を含む）
- ダイエットゼリーやサプリメントも、食べる・飲むものであれば食品としてカロリーを推定する
- 商品名が不明確・存在が確認できない場合でも、食べ物の可能性があるなら not_food にせず confidence: low で推定する
- not_food にするのは明らかに食べないもののみ（例: 髪の毛、紙、石、金属、洗剤、化粧品、ペットフード、肥料、毒物など）
- 非食品の場合はカロリーを推定せず、次のJSONのみ返す: {"error":"not_food"}
- 非食品の場合は説明文を付けない
{$webSearchSection}
【重さの基準】
- 魚の一切れ: 100〜130g
- 肉類の一人前: 100〜150g
- ご飯一杯: 150g
- 野菜の小鉢: 80g
- 煮物・小鉢の「1個」は一口サイズ: 40〜60g
- かぼちゃの煮付け1個（一口サイズ）: 40〜60g
- パン一枚: 60g
- 卵1個: 60g
- 揚げ鶏・唐揚げ・油淋鶏など揚げ鶏1人前: 150〜200g

{$confidenceSection}

【ルール】
- 「一切れ」「一人前」「1個」などの単位は日本の一般的な家庭料理の量を基準にする
- 煮物・炒め物など調理法が含まれる場合は調理後の重さで計算する
- 煮汁・タレのカロリーも含めて計算する
- 揚げ物・炒め物は素材のカロリーに油・衣で1.3〜1.5倍を加算する
- 量が全く不明な場合は一般的な1食分を仮定する
- 定食は主菜・ご飯・味噌汁・小鉢・漬物を含むものとして計算する
- 定食のご飯は150gを標準とする
- 定食・セットメニューは構成が不明なためconfidenceはlowにする
- コンビニ・外食チェーン・市販品など商品名が含まれる場合はその商品の公式カロリーを優先する
- 商品名が特定できない場合は同カテゴリの平均値で推定する
- 市販品・宅配弁当など正確なカロリーが不明な場合はconfidenceをlowにする

最終回答はJSONのみ。前置きや説明は不要。

食品の場合の形式:
- 通常: {"kcal": 整数, "confidence": "high"|"medium"|"low"}
- ページにカロリー表示がある場合: {"labeled_kcal": 整数, "kcal": 同じ整数, "confidence": "high"}
- 重量(g)が公式または推定で分かる場合: {"kcal": 整数, "assumed_weight_g": 整数, "confidence": "high"|"medium"|"low"}
- 商品名が特定できた場合: 上記に "product_name": "正式な商品名" を追加
- labeled_kcal がある場合は kcal も必ず同じ値にする
- 公式ページに重量(g)が無い high の場合は assumed_weight_g を含めない
非食品の場合の形式: {"error":"not_food"}

食品名: {$foodName}
PROMPT;
    }

    /**
     * Web 検索用クエリのヒント文字列を組み立てる。
     */
    private function buildNutritionSearchQueryHint(string $foodName): string
    {
        $coreName = trim((string) preg_replace(
            '/\s*\d+(?:\.\d+)?\s*(g|ml|個|杯|切れ|袋|本)\s*$/iu',
            '',
            trim($foodName),
        ));

        if ($coreName === '') {
            $coreName = trim($foodName);
        }

        return $coreName . ' 栄養成分 エネルギー kcal';
    }

    /**
     * Anthropic Messages API に POST リクエストを送る。
     * curl で JSON を送信し、レスポンス body を連想配列として返す。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postToAnthropic(array $payload, string $apiKey, bool $withWebSearch): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl 拡張が有効になっていません。');
        }

        $ch = curl_init(self::API_URL);

        if ($ch === false) {
            throw new RuntimeException('カロリー推定サービスへの接続を開始できませんでした。');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $withWebSearch ? 90 : 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException(
                $curlError !== ''
                    ? 'カロリー推定サービスへの接続に失敗しました: ' . $curlError
                    : 'カロリー推定サービスへの接続に失敗しました。',
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('カロリー推定サービスの応答を解析できませんでした。');
        }

        if ($httpCode >= 400) {
            $message = is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? 'カロリー推定に失敗しました。')
                : 'カロリー推定に失敗しました。';
            throw new RuntimeException($message);
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $message = (string) ($decoded['error']['message'] ?? 'カロリー推定に失敗しました。');
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    /**
     * API レスポンスからテキストブロックだけを取り出す。
     * Web検索時は説明文と JSON が分かれることがあるため改行で連結する。
     *
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): string
    {
        $content = $response['content'] ?? [];

        if (!is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * Claude のテキスト応答を解析する。
     * 非食品は 'not_food'、食品推定成功は配列、パース失敗は null を返す。
     *
     * @return array{kcal: int, assumed_weight_g?: int, confidence: string, product_name?: string, source_url?: string}|'not_food'|null
     */
    private function parseResponse(string $text): array|string|null
    {
        if ($text === '') {
            return null;
        }

        foreach ($this->extractJsonCandidates($text) as $candidate) {
            $json = json_decode($candidate, true);

            if (!is_array($json)) {
                continue;
            }

            if ($this->isNotFoodResponse($json)) {
                return 'not_food';
            }

            $parsed = $this->normalizeEstimate($json);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * 応答 JSON が非食品（{"error":"not_food"}）かどうかを判定する。
     *
     * @param array<string, mixed> $json
     */
    private function isNotFoodResponse(array $json): bool
    {
        return ($json['error'] ?? '') === 'not_food';
    }

    /**
     * テキストから JSON 候補文字列を抽出する。
     * コードフェンス（```json）の除去と、本文中の JSON 断片の検出に対応する。
     *
     * @return array<int, string>
     */
    private function extractJsonCandidates(string $text): array
    {
        $candidates = [];
        $trimmed = trim($text);
        $withoutFence = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);
        $candidates[] = trim($withoutFence ?? $trimmed);

        if (preg_match_all('/\{[^{}]*(?:"error"\s*:\s*"not_food"|"kcal"\s*:\s*\d+)[^{}]*\}/s', $text, $matches) === 1) {
            foreach ($matches[0] as $match) {
                $candidates[] = $match;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * パース済み JSON を画面用の推定結果に正規化する。
     * kcal・assumed_weight_g・confidence のバリデーションと型変換を行う。
     *
     * @param array<string, mixed> $json
     * @return array{kcal: int, assumed_weight_g?: int, confidence: string, product_name?: string, source_url?: string}|null
     */
    private function normalizeEstimate(array $json): ?array
    {
        if (!isset($json['kcal']) || !is_numeric($json['kcal'])) {
            return null;
        }

        $confidence = (string) ($json['confidence'] ?? '');

        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            return null;
        }

        $kcal = (int) round((float) $json['kcal']);
        if (isset($json['labeled_kcal']) && is_numeric($json['labeled_kcal'])) {
            $labeledKcal = (int) round((float) $json['labeled_kcal']);
            if ($labeledKcal > 0) {
                $kcal = $labeledKcal;
            }
        }

        if ($kcal <= 0) {
            return null;
        }

        $normalized = [
            'kcal' => $kcal,
            'confidence' => $confidence,
        ];

        if (isset($json['assumed_weight_g']) && is_numeric($json['assumed_weight_g'])) {
            $assumedWeightG = (int) round((float) $json['assumed_weight_g']);
            if ($assumedWeightG > 0) {
                $normalized['assumed_weight_g'] = $assumedWeightG;
            }
        }

        $productName = trim((string) ($json['product_name'] ?? ''));
        if ($productName !== '') {
            $normalized['product_name'] = $productName;
        }

        return $normalized;
    }
}
