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
    private const SYSTEM_PROMPT = 'あなたは日本の食品・料理全般に詳しいカロリー推定の専門家です。食べ物・飲み物として一般的に人が摂取するものだけを対象にしてください。食品でないものは推定せず、{"error":"not_food"} のみ返答してください。食品の場合はJSONのみ返答し、前置きや説明は不要です。';
    private const WEB_SEARCH_SYSTEM_PROMPT = 'あなたは日本の食品・料理全般に詳しいカロリー推定の専門家です。食べ物・飲み物として一般的に人が摂取するものだけを対象にしてください。食品でないものは {"error":"not_food"} のみ返答してください。食品の場合は web_search で公式の栄養成分・カロリー情報を確認してから回答してください。検索結果は要点のみ抽出し、カロリーと重量の数値だけを使ってください。検索結果の文章をそのまま処理しないでください。最終回答はJSONのみ。前置きや説明は不要です。';

    /**
     * 食品名からカロリーを推定する（公開 API）。
     * mode:
     * - auto: 従来どおり high 以外で Web 検索を試行
     * - no_web: Web 検索を使わず 1 回のみ推定
     * - web: Web 検索付きで推定
     *
     * @return array{kcal: int, assumed_weight_g: int, confidence: string, product_name?: string}
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

        if ($initial['confidence'] === 'high') {
            return $initial;
        }

        $refined = $this->requestEstimate($trimmed, $apiKey, true);

        if ($refined === 'not_food' || $refined === null) {
            return $initial;
        }

        return $refined;
    }

    /**
     * Claude API を1回呼び出し、推定結果を返す。
     *
     * @return array{kcal: int, assumed_weight_g: int, confidence: string, product_name?: string}|'not_food'|null
     */
    private function requestEstimate(string $foodName, string $apiKey, bool $withWebSearch): array|string|null
    {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => $withWebSearch ? 512 : 128,
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
                    'max_uses' => 3,
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

        return $this->parseResponse($text);
    }

    /**
     * Claude に送るユーザープロンプトを組み立てる。
     * $withWebSearch が true のときは Web 検索の指示を含める。
     */
    private function buildPrompt(string $foodName, bool $withWebSearch): string
    {
        $webSearchSection = $withWebSearch
            ? <<<'TEXT'

【Web検索】
- 必ずweb_searchで食品名・商品名の公式カロリー・栄養成分を検索してから回答する
- 日本食品標準成分表、メーカー公式サイト、コンビニ・外食チェーンの公式栄養情報を優先する
- 検索で公式値が見つかった場合はその値を使い、confidenceはhighにする
- 検索で商品名まで特定できた場合は、正式な商品名を product_name に入れる
- 検索しても特定できない場合のみ、下記の推定ルールを使う
TEXT
            : '';

        $confidenceSection = $withWebSearch
            ? <<<'TEXT'
【confidenceの基準】
- high: Web検索または公式情報で料理名・重さ・カロリーが特定できた場合
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

【非食品の判定】
- 人が通常の食事として食べないものは食品ではない
- 例: 髪の毛、紙、石、金属、木、プラスチック、洗剤、薬、化粧品、ペットフード、肥料、毒物など
- 食品名に見えても、実際に食べないものは非食品とする
- 食品かどうか判断できない・曖昧な場合も非食品として扱う
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
- 通常: {"kcal": 整数, "assumed_weight_g": 整数, "confidence": "high"|"medium"|"low"}
- 商品名が特定できた場合: {"kcal": 整数, "assumed_weight_g": 整数, "confidence": "high"|"medium"|"low", "product_name": "正式な商品名"}
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
     * @return array{kcal: int, assumed_weight_g: int, confidence: string, product_name?: string}|'not_food'|null
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
     * @return array{kcal: int, assumed_weight_g: int, confidence: string, product_name?: string}|null
     */
    private function normalizeEstimate(array $json): ?array
    {
        if (!isset($json['kcal']) || !is_numeric($json['kcal'])) {
            return null;
        }

        if (!isset($json['assumed_weight_g']) || !is_numeric($json['assumed_weight_g'])) {
            return null;
        }

        $confidence = (string) ($json['confidence'] ?? '');

        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            return null;
        }

        $kcal = (int) round((float) $json['kcal']);
        $assumedWeightG = (int) round((float) $json['assumed_weight_g']);

        if ($kcal <= 0 || $assumedWeightG <= 0) {
            return null;
        }

        $normalized = [
            'kcal' => $kcal,
            'assumed_weight_g' => $assumedWeightG,
            'confidence' => $confidence,
        ];

        $productName = trim((string) ($json['product_name'] ?? ''));
        if ($productName !== '') {
            $normalized['product_name'] = $productName;
        }

        return $normalized;
    }
}
