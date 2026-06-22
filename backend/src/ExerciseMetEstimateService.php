<?php

declare(strict_types=1);

/**
 * 運動名・時間/回数から METs を推定し、消費カロリー計算に必要な値を返す。
 * フロー:
 * 1) ローカル exercise_mets を完全一致検索（無料）
 * 2) ヒットしない場合は LLM で minutes/mets/confidence を推定
 */
final class ExerciseMetEstimateService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const SYSTEM_PROMPT = 'あなたは運動生理学の専門家です。入力運動を METs に正規化してください。返答はJSONのみ。';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{
     *   exercise:string,
     *   minutes:int,
     *   mets:float,
     *   confidence:string,
     *   note:string,
     *   source:string,
     *   isEstimated:bool,
     *   calories:int
     * }
     */
    public function estimate(string $exerciseName, int $amount, string $unit, float $weightKg): array
    {
        $trimmedName = trim($exerciseName);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('exerciseName is required');
        }
        if ($amount <= 0 || $amount > 10000) {
            throw new InvalidArgumentException('amount must be between 1 and 10000');
        }
        if ($unit !== 'min' && $unit !== 'rep') {
            throw new InvalidArgumentException('unit must be min|rep');
        }
        if ($weightKg <= 0 || $weightKg > 300) {
            throw new InvalidArgumentException('weight must be between 0 and 300 kg');
        }

        $localMets = $this->findLocalMets($trimmedName);
        if ($localMets !== null && $unit === 'min') {
            $minutes = $amount;
            $calories = $this->calculateCalories($weightKg, $localMets, $minutes);
            return [
                'exercise' => $trimmedName,
                'minutes' => $minutes,
                'mets' => $localMets,
                'confidence' => 'high',
                'note' => 'ローカルMETsテーブルに一致しました',
                'source' => 'local_db',
                'isEstimated' => false,
                'calories' => $calories,
            ];
        }

        $llm = $this->estimateByLlm($trimmedName, $amount, $unit);
        $calories = $this->calculateCalories($weightKg, $llm['mets'], $llm['minutes']);

        return [
            'exercise' => $llm['exercise'],
            'minutes' => $llm['minutes'],
            'mets' => $llm['mets'],
            'confidence' => $llm['confidence'],
            'note' => $llm['note'],
            'source' => 'llm_estimate',
            'isEstimated' => true,
            'calories' => $calories,
        ];
    }

    private function findLocalMets(string $exerciseName): ?float
    {
        $statement = $this->db->prepare(
            'SELECT mets FROM exercise_mets WHERE lower(exercise_name) = lower(:exercise_name) LIMIT 1'
        );
        $statement->execute(['exercise_name' => trim($exerciseName)]);
        $row = $statement->fetch();
        if ($row === false || !isset($row['mets']) || !is_numeric($row['mets'])) {
            return null;
        }

        $mets = round((float) $row['mets'], 1);
        return $mets > 0 ? $mets : null;
    }

    /**
     * @return array{exercise:string, minutes:int, mets:float, confidence:string, note:string}
     */
    private function estimateByLlm(string $exerciseName, int $amount, string $unit): array
    {
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
                    'content' => $this->buildPrompt($exerciseName, $amount, $unit),
                ],
            ],
        ];

        $response = $this->postToAnthropic($payload, $apiKey);
        $text = $this->extractText($response);
        $parsed = $this->parseResponse($text);
        if ($parsed === null) {
            throw new RuntimeException('運動METsを推定できませんでした。');
        }

        return $parsed;
    }

    private function buildPrompt(string $exerciseName, int $amount, string $unit): string
    {
        $unitLabel = $unit === 'min' ? '分' : '回';

        return <<<PROMPT
入力テキストから運動名・minutes・mets・confidenceを推定し、JSONのみ返してください。
入力が回数の場合は、一般的なテンポで minutes に分換算してください。

返却JSON形式:
{
  "exercise": "運動名",
  "minutes": 整数,
  "mets": 小数,
  "confidence": "high"|"medium"|"low",
  "note": "推定根拠（任意）"
}

confidence基準:
- high: 正式な運動名・時間が明確
- medium: 運動名は推定・時間は明確
- low: 運動名も時間も曖昧

ルール:
- minutes は 1〜600 の整数
- mets は 1.0〜25.0 の小数
- 運動らしくない入力でも、可能な限り近い運動へ正規化
- note は短く（50文字以内）
- 前置きや説明文は禁止、JSONのみ

入力:
- exerciseName: {$exerciseName}
- amount: {$amount}
- unit: {$unitLabel}
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
            throw new RuntimeException('運動METs推定サービスへの接続を開始できませんでした。');
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
                $curlError !== '' ? '運動METs推定サービスへの接続に失敗しました: ' . $curlError : '運動METs推定サービスへの接続に失敗しました。',
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('運動METs推定サービスの応答を解析できませんでした。');
        }

        if ($httpCode >= 400 || (isset($decoded['error']) && is_array($decoded['error']))) {
            $message = is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? '運動METs推定に失敗しました。')
                : '運動METs推定に失敗しました。';
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
     * @return array{exercise:string, minutes:int, mets:float, confidence:string, note:string}|null
     */
    private function parseResponse(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        $candidate = trim((string) (preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text));
        $json = json_decode($candidate, true);
        if (!is_array($json)) {
            return null;
        }

        $exercise = trim((string) ($json['exercise'] ?? ''));
        $minutes = $json['minutes'] ?? null;
        $mets = $json['mets'] ?? null;
        $confidence = (string) ($json['confidence'] ?? '');
        $note = trim((string) ($json['note'] ?? ''));

        if ($exercise === '' || !is_numeric($minutes) || !is_numeric($mets)) {
            return null;
        }

        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            return null;
        }

        $minutesValue = (int) round((float) $minutes);
        $metsValue = round((float) $mets, 1);
        if ($minutesValue < 1 || $minutesValue > 600 || $metsValue < 1.0 || $metsValue > 25.0) {
            return null;
        }

        return [
            'exercise' => $exercise,
            'minutes' => $minutesValue,
            'mets' => $metsValue,
            'confidence' => $confidence,
            'note' => $note,
        ];
    }

    private function calculateCalories(float $weightKg, float $mets, int $minutes): int
    {
        $hours = $minutes / 60;
        return max(1, (int) round($weightKg * $mets * $hours));
    }
}
