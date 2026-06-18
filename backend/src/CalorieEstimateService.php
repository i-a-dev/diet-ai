<?php

declare(strict_types=1);

/**
 * Claude Haiku 4.5 を使って食品名からカロリー（kcal）を推定するサービス。
 * 非食品は {"error":"not_food"} として弾き、食品のみカロリーを返す。
 */
final class CalorieEstimateService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const SYSTEM_PROMPT = 'あなたは日本の食品・料理全般に詳しいカロリー推定の専門家です。食べ物・飲み物として一般的に人が摂取するものだけを対象にしてください。食品でないものは推定せず、{"error":"not_food"} のみ返答してください。食品の場合はJSONのみ返答し、前置きや説明は不要です。';

    /**
     * 食品名からカロリーを推定する（公開 API）。
     * Claude にプロンプトを送り、返ってきた JSON を解析して結果を返す。
     * 非食品・パース失敗時は例外を投げる。
     *
     * @return array{kcal: int, assumed_weight_g: int, confidence: string}
     */
    public function estimate(string $foodName): array
    {
        $trimmed = trim($foodName);

        if ($trimmed === '') {
            throw new InvalidArgumentException('食品名を入力してください。');
        }

        if (mb_strlen($trimmed) > 200) {
            throw new InvalidArgumentException('食品名が長すぎます。');
        }

        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';

        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY が設定されていません。');
        }

        $prompt = $this->buildPrompt($trimmed);

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 128,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ];

        $decoded = $this->postToAnthropic($payload, $apiKey);
        $text = $this->extractText($decoded);
        $result = $this->parseEstimate($text);

        if ($result === null) {
            throw new RuntimeException('カロリーを推定できませんでした。');
        }

        return $result;
    }

    /**
     * Claude に送るユーザープロンプトを組み立てる。
     * 非食品判定・重さ基準・confidence ルールなどを含める。
     */
    private function buildPrompt(string $foodName): string
    {
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

【confidenceの基準】
- high: 料理名・重さ・カロリーすべて明確に特定できる場合のみ
- medium: 重さを仮定した、または調理法が不明な場合
- low: 食品名が曖昧、または量が全く不明な場合
- 揚げ物・中華料理など油の量が不明な場合は必ずmediumにする
- 市販品・宅配弁当など正確なカロリーが不明な場合はlowにする

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

回答はJSONのみ。前置きや説明は不要。

食品の場合の形式: {"kcal": 整数, "assumed_weight_g": 整数, "confidence": "high"|"medium"|"low"}
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
    private function postToAnthropic(array $payload, string $apiKey): array
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
            CURLOPT_TIMEOUT => 30,
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
     * content 配列内の type=text の text を連結して返す。
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

        return trim(implode('', $parts));
    }

    /**
     * Claude のテキスト応答を解析し、推定結果を返す。
     * 非食品（not_food）・不正 JSON・必須項目欠落の場合は null を返す。
     *
     * @return array{kcal: int, assumed_weight_g: int, confidence: string}|null
     */
    private function parseEstimate(string $text): ?array
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
                return null;
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
     * @return array{kcal: int, assumed_weight_g: int, confidence: string}|null
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

        return [
            'kcal' => $kcal,
            'assumed_weight_g' => $assumedWeightG,
            'confidence' => $confidence,
        ];
    }
}
