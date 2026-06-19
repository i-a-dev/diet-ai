<?php

declare(strict_types=1);

/**
 * Claude を使って入力食品名を検索しやすい JSON に正規化する。
 * 例: 「焼き鮭定食」→ 焼き鮭 / 白米 / 味噌汁 などの items 配列。
 */
final class FoodNormalizeService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const SYSTEM_PROMPT = 'あなたは食品入力を構造化データに正規化するアシスタントです。返答はJSONのみ。';

    /**
     * @return array{items: array<int, array{name: string, amount: float|int, unit: string}>}
     */
    public function normalize(string $foodName): array
    {
        $trimmed = trim($foodName);

        if ($trimmed === '') {
            throw new InvalidArgumentException('食品名を入力してください。');
        }

        if (mb_strlen($trimmed) < 2) {
            throw new InvalidArgumentException('食品名は2文字以上で入力してください。');
        }

        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY が設定されていません。');
        }

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 256,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($trimmed),
                ],
            ],
        ];

        $response = $this->postToAnthropic($payload, $apiKey);
        $text = $this->extractText($response);
        $parsed = $this->parseItems($text);

        if ($parsed === []) {
            return [
                'items' => [
                    ['name' => $trimmed, 'amount' => 1, 'unit' => '食'],
                ],
            ];
        }

        return ['items' => $parsed];
    }

    private function buildPrompt(string $foodName): string
    {
        return <<<PROMPT
入力された食品名を検索用に正規化してください。
複合メニュー（例: 定食）は主な構成要素に分解してください。

【ルール】
- 必ず次のJSON形式のみを返す
- items は 1件以上
- amount は数値
- unit は短い単位文字列（g, ml, 個, 杯, 切れ, 食 など）
- 説明文・前置きは禁止

形式:
{"items":[{"name":"食品名","amount":数値,"unit":"単位"}]}

入力: {$foodName}
PROMPT;
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
            throw new RuntimeException('正規化サービスへの接続を開始できませんでした。');
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
                $curlError !== '' ? '正規化サービスへの接続に失敗しました: ' . $curlError : '正規化サービスへの接続に失敗しました。',
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('正規化サービスの応答を解析できませんでした。');
        }

        if ($httpCode >= 400 || (isset($decoded['error']) && is_array($decoded['error']))) {
            $message = is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? '正規化に失敗しました。')
                : '正規化に失敗しました。';
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

        return trim(implode("\n", $parts));
    }

    /**
     * @return array<int, array{name: string, amount: float|int, unit: string}>
     */
    private function parseItems(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $candidate = trim((string) (preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text));
        $decoded = json_decode($candidate, true);

        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            return [];
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $amount = $item['amount'] ?? null;
            $unit = trim((string) ($item['unit'] ?? ''));

            if ($name === '' || !is_numeric($amount)) {
                continue;
            }

            $amountNumber = (float) $amount;
            if ($amountNumber <= 0) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'amount' => fmod($amountNumber, 1.0) === 0.0 ? (int) $amountNumber : $amountNumber,
                'unit' => $unit !== '' ? $unit : '食',
            ];
        }

        return $items;
    }
}
