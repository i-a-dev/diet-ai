<?php

declare(strict_types=1);

/**
 * Claude Haiku 4.5 を使って食品名からカロリー（kcal）を推定するサービス。
 */
final class CalorieEstimateService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const SYSTEM_PROMPT = 'あなたはカロリー推定の専門家です。JSONのみ返答してください。前置きや説明は不要です。';

    /**
     * 食品名からカロリーを推定する。
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

        $prompt = <<<PROMPT
あなたは、カロリー推定の専門家です。
以下のルールに従って、食品名のカロリーを推定してください。
量の表記がなければ一般的な1食分を仮定してください。

【重さの基準】
- 魚の一切れ: 100〜130g
- 肉類の一人前: 100〜150g
- ご飯一杯: 150g
- 野菜の小鉢: 80g
- 煮物・小鉢の「1個」は一口サイズ: 40〜60g
- かぼちゃの煮付け1個（一口サイズ）: 40〜60g
- パン一枚: 60g
- 卵1個: 60g

【confidenceの基準】
- high: 料理名・重さ・カロリーすべて明確に特定できる場合のみ
- medium: 重さを仮定した、または調理法が不明な場合
- low: 食品名が曖昧、または量が全く不明な場合
- 揚げ物・中華料理など油の量が不明な場合は必ずmediumにする

【ルール】
- 「一切れ」「一人前」「1個」などの単位は日本の一般的な家庭料理の量を基準にする
- 煮物・炒め物など調理法が含まれる場合は調理後の重さで計算する
- 煮汁・タレのカロリーも含めて計算する
- 量が全く不明な場合は一般的な1食分を仮定する
- 定食は主菜・ご飯・味噌汁・小鉢・漬物を含むものとして計算する
- 定食のご飯は150gを標準とする



回答はJSONのみ。前置きや説明は不要。

形式: {"kcal": 整数, "assumed_weight_g": 整数, "confidence": "high"|"medium"|"low"}

食品名: {$trimmed}
PROMPT;

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
     * @return array{kcal: int, assumed_weight_g: int, confidence: string}|null
     */
    private function parseEstimate(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
        $json = json_decode(trim($cleaned ?? $text), true);

        if (!is_array($json)) {
            return null;
        }

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
