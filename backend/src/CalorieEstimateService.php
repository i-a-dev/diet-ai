<?php

declare(strict_types=1);

/**
 * Claude Haiku 4.5 で食品名からカロリー（kcal）を推定するサービス。
 * mode=web のとき:
 * 1. Claude web search で正式な商品名を特定
 * 2. Brave Search（正式商品名 カロリー）→ HTML 抽出
 * 3. 見つからなければ Claude の参照 URL から HTML 抽出
 *    （HTML 失敗時は Claude の kcal をフォールバック）
 */
final class CalorieEstimateService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const SYSTEM_PROMPT = 'あなたは日本の食品・料理全般に詳しいカロリー推定の専門家です。口に入れて摂取するもの（料理・飲み物・お菓子・ゼリー・サプリ・機能性食品・市販品）は食品として扱ってください。明らかに食べないもの（洗剤・化粧品・金属など）だけ {"error":"not_food"} を返してください。商品名が不明確でも食べ物の可能性がある場合は not_food にせず推定してください。食品の場合はJSONのみ返答し、前置きや説明は不要です。';
    private const WEB_SEARCH_SYSTEM_PROMPT = 'あなたは日本の食品・市販品に詳しい専門家です。口に入れて摂取するものは食品として扱ってください。明らかに食べないものだけ {"error":"not_food"} を返してください。食品の場合は web_search で商品を調べ、正式な商品名とその商品の栄養成分・カロリーが載っているページ URL を返してください。まとめ記事・ブログよりメーカー公式・商品詳細ページを優先してください。最終回答はJSONのみ。前置きや説明は不要です。';
    private const WEB_SEARCH_MAX_TOKENS = 1024;
    private const MAX_WEB_SEARCH_URL_FETCHES = 5;

    public function __construct(
        private readonly ?BraveNutritionSearchService $braveNutritionSearch = null,
        private readonly ?NutritionPageExtractor $nutritionPageExtractor = null,
    ) {
    }

    /**
     * 食品名からカロリーを推定する（公開 API）。
     * mode:
     * - auto: 従来どおり high 以外で Web 検索を試行
     * - no_web: Web 検索を使わず 1 回のみ推定
     * - web: Claude で商品名特定 → Brave 検索 → HTML 抽出
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
            $brave = $this->resolveBraveNutritionSearch();

            $claudeResult = $this->requestClaudeWebIdentification($trimmed, $apiKey);
            if ($claudeResult === 'not_food' || $claudeResult === null) {
                throw new RuntimeException('カロリーを推定できませんでした。');
            }

            $productName = $claudeResult['product_name'];
            $braveResult = $brave->searchFoodCalories($productName, [$trimmed, $productName]);
            if ($braveResult !== null) {
                return [
                    'kcal' => $braveResult['kcal'],
                    'confidence' => $braveResult['confidence'],
                    'product_name' => $productName,
                    'source_url' => $braveResult['source_url'],
                ];
            }

            $htmlResult = $this->probeClaudeSourceUrls(
                $claudeResult['source_urls'],
                $productName,
                [$trimmed, $productName],
            );

            if ($htmlResult !== null) {
                return [
                    'kcal' => $htmlResult['kcal'],
                    'confidence' => 'high',
                    'product_name' => $productName,
                    'source_url' => $htmlResult['url'],
                ];
            }

            $fallbackConfidence = $claudeResult['confidence'] === 'high' ? 'medium' : $claudeResult['confidence'];

            return [
                'kcal' => $claudeResult['kcal'],
                'confidence' => $fallbackConfidence,
                'product_name' => $productName,
            ];
        }

        $initial = $this->requestEstimate($trimmed, $apiKey);

        if ($initial === 'not_food' || $initial === null) {
            throw new RuntimeException('カロリーを推定できませんでした。');
        }

        if ($mode === 'no_web') {
            return $initial;
        }

        // autoモードはWeb検索しない・推定結果をそのまま返す
        return $initial;
    }

    private function resolveBraveNutritionSearch(): BraveNutritionSearchService
    {
        return $this->braveNutritionSearch ?? new BraveNutritionSearchService();
    }

    private function resolveNutritionPageExtractor(): NutritionPageExtractor
    {
        return $this->nutritionPageExtractor ?? new NutritionPageExtractor();
    }

    /**
     * Claude API を1回呼び出し、推定結果を返す。
     *
     * @return array{kcal: int, assumed_weight_g?: int, confidence: string, product_name?: string, source_url?: string}|'not_food'|null
     */
    private function requestEstimate(string $foodName, string $apiKey): array|string|null
    {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 128,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($foodName),
                ],
            ],
        ];

        $decoded = $this->postToAnthropic($payload, $apiKey, false);
        $text = $this->extractText($decoded);
        $parsed = $this->parseResponse($text);

        return $parsed;
    }

    /**
     * Claude Web 検索で商品名と参照 URL を特定する（mode=web のフォールバック用）。
     *
     * @return array{product_name: string, source_urls: list<string>, kcal: int, confidence: string}|'not_food'|null
     */
    private function requestClaudeWebIdentification(string $foodName, string $apiKey): array|string|null
    {
        $searchQueryHint = $this->resolveBraveNutritionSearch()->buildCalorieSearchQuery($foodName);
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => self::WEB_SEARCH_MAX_TOKENS,
            'system' => self::WEB_SEARCH_SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildClaudeWebIdentificationPrompt($foodName, $searchQueryHint),
                ],
            ],
            'tools' => [
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
            ],
        ];

        $decoded = $this->postToAnthropic($payload, $apiKey, true);
        $text = $this->extractText($decoded);
        $parsed = $this->parseClaudeWebIdentificationResponse($text, $foodName);

        if (!is_array($parsed)) {
            return $parsed;
        }

        $parsed['source_urls'] = $this->mergeSourceUrls(
            $parsed['source_urls'],
            $this->extractWebSearchResultUrls($decoded),
        );

        return $parsed;
    }

    private function buildClaudeWebIdentificationPrompt(string $foodName, string $searchQueryHint): string
    {
        $storeSourceHint = $this->buildConvenienceStoreSourceHint($foodName);

        return <<<PROMPT
以下の入力が食品かどうかを判定し、食品の場合は正式な商品名と、その商品のカロリーが記載されているページ URL を特定してください。

入力: {$foodName}

【Web検索】
- 必ず web_search で検索してから回答する
- 検索クエリは次の形式を使う: 「{$searchQueryHint}」
- メーカー公式・コンビニ公式・商品詳細ページを優先する
{$storeSourceHint}- レビューサイト、ブログ、まとめ記事は source_urls に入れない
- ログイン必須のサイト（eatsmart.jp など）は source_urls に入れない
- source_urls には特定した商品の栄養成分・カロリーが載っている URL のみ入れる（最大5件）
- kcal はページに記載があればその値、なければ推定値を入れる（HTML 抽出失敗時のフォールバック用）

最終回答は JSON のみ。前置きや説明は不要。

食品の場合:
{"product_name": "正式な商品名", "source_urls": ["URL1", "URL2"], "kcal": 整数, "confidence": "high"|"medium"|"low"}
非食品の場合: {"error":"not_food"}
PROMPT;
    }

    /**
     * @param list<string> $texts
     */
    private function buildConvenienceStoreSourceHint(string ...$texts): string
    {
        $haystack = mb_strtolower(trim(implode(' ', $texts)));
        $hints = [];

        if ($this->textContainsAny($haystack, [
            'セブンイレブン',
            'セブン‐イレブン',
            'セブン-イレブン',
            'セブンプレミアム',
            '７プレミアム',
            '7premium',
            'ななチキ',
            'セブン',
        ])) {
            $hints[] = 'セブン‐イレブン・セブンプレミアム商品は www.sej.co.jp の商品詳細ページを source_urls の最優先にする';
        }

        if ($this->textContainsAny($haystack, ['ファミリーマート', 'ファミマ', 'ファミチキ'])) {
            $hints[] = 'ファミリーマート商品は www.family.co.jp の商品詳細ページを source_urls の最優先にする';
        }

        if ($this->textContainsAny($haystack, ['ローソン', 'からあげ', 'プレミアムロール'])) {
            $hints[] = 'ローソン商品は www.lawson.co.jp の商品詳細ページを source_urls の最優先にする';
        }

        if ($hints === []) {
            return '';
        }

        return implode("\n", array_map(static fn (string $hint): string => '- ' . $hint, $hints)) . "\n";
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

    /**
     * @param list<string> $fromJson
     * @param list<string> $fromApi
     * @return list<string>
     */
    private function mergeSourceUrls(array $fromJson, array $fromApi): array
    {
        $merged = [];

        foreach (array_merge($fromJson, $fromApi) as $url) {
            $url = trim($url);

            if ($url === '' || in_array($url, $merged, true)) {
                continue;
            }

            $merged[] = $url;

            if (count($merged) >= self::MAX_WEB_SEARCH_URL_FETCHES) {
                break;
            }
        }

        return $merged;
    }

    /**
     * @param list<string> $sourceUrls
     * @param list<string> $storeContextTexts
     * @return array{kcal: int, url: string, score: int}|null
     */
    private function probeClaudeSourceUrls(
        array $sourceUrls,
        string $productName,
        array $storeContextTexts = [],
    ): ?array {
        if ($sourceUrls === []) {
            return null;
        }

        $sourceUrls = $this->prioritizeOfficialStoreUrls($sourceUrls, $storeContextTexts);

        $extractor = $this->resolveNutritionPageExtractor();
        $rankedUrls = $extractor->rankUrls($sourceUrls, ['query' => $productName]);
        $probeResult = $extractor->probeUrls(
            $rankedUrls,
            ['query' => $productName],
            self::MAX_WEB_SEARCH_URL_FETCHES,
            false,
        );

        return $probeResult['best'];
    }

    /**
     * @param list<string> $sourceUrls
     * @param list<string> $storeContextTexts
     * @return list<string>
     */
    private function prioritizeOfficialStoreUrls(array $sourceUrls, array $storeContextTexts): array
    {
        $preferredHosts = $this->detectPreferredOfficialStoreHosts($storeContextTexts);

        if ($preferredHosts === []) {
            return $sourceUrls;
        }

        $ranked = $sourceUrls;

        usort(
            $ranked,
            function (string $a, string $b) use ($preferredHosts): int {
                $scoreA = $this->scoreOfficialStoreUrl($a, $preferredHosts);
                $scoreB = $this->scoreOfficialStoreUrl($b, $preferredHosts);

                return $scoreB <=> $scoreA;
            },
        );

        return array_values(array_unique($ranked));
    }

    /**
     * @param list<string> $texts
     * @return list<string>
     */
    private function detectPreferredOfficialStoreHosts(array $texts): array
    {
        $haystack = mb_strtolower(trim(implode(' ', $texts)));
        $hosts = [];

        if ($this->textContainsAny($haystack, [
            'セブンイレブン',
            'セブン‐イレブン',
            'セブン-イレブン',
            'セブンプレミアム',
            '７プレミアム',
            '7premium',
            'ななチキ',
            'セブン',
        ])) {
            $hosts[] = 'sej.co.jp';
            $hosts[] = '7premium.jp';
        }

        if ($this->textContainsAny($haystack, ['ファミリーマート', 'ファミマ', 'ファミチキ'])) {
            $hosts[] = 'family.co.jp';
        }

        if ($this->textContainsAny($haystack, ['ローソン', 'からあげ', 'プレミアムロール'])) {
            $hosts[] = 'lawson.co.jp';
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param list<string> $preferredHosts
     */
    private function scoreOfficialStoreUrl(string $url, array $preferredHosts): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $score = 0;

        foreach ($preferredHosts as $index => $preferredHost) {
            if ($host === $preferredHost || str_ends_with($host, '.' . $preferredHost)) {
                $score += 100 - $index;
                break;
            }
        }

        if (str_contains($path, '/products/a/item/') || str_contains($path, '/product/')) {
            $score += 20;
        }

        return $score;
    }

    /**
     * @return array{product_name: string, source_urls: list<string>, kcal: int, confidence: string}|'not_food'|null
     */
    private function parseClaudeWebIdentificationResponse(string $text, string $foodName): array|string|null
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

            if (!isset($json['kcal']) || !is_numeric($json['kcal'])) {
                continue;
            }

            $confidence = (string) ($json['confidence'] ?? 'medium');
            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                $confidence = 'medium';
            }

            $kcal = (int) round((float) $json['kcal']);
            if ($kcal <= 0) {
                continue;
            }

            $productName = trim((string) ($json['product_name'] ?? ''));
            if ($productName === '') {
                $productName = $foodName;
            }

            return [
                'product_name' => $productName,
                'source_urls' => $this->normalizeSourceUrls($json['source_urls'] ?? []),
                'kcal' => $kcal,
                'confidence' => $confidence,
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizeSourceUrls(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $extractor = $this->resolveNutritionPageExtractor();
        $urls = [];

        foreach ($value as $item) {
            $url = trim((string) $item);

            if ($url !== '' && !$extractor->isBlockedSourceUrl($url)) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param array<string, mixed> $response
     * @return list<string>
     */
    private function extractWebSearchResultUrls(array $response): array
    {
        $extractor = $this->resolveNutritionPageExtractor();
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
                if ($url !== '' && !$extractor->isBlockedSourceUrl($url)) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Claude に送るユーザープロンプトを組み立てる。
     */
    private function buildPrompt(string $foodName): string
    {
        $confidenceSection = <<<'TEXT'
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

        if (preg_match_all('/\{[^{}]*(?:"error"\s*:\s*"not_food"|"kcal"\s*:\s*\d+|"product_name"\s*:|"source_urls"\s*:)[^{}]*\}/s', $text, $matches) === 1) {
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
