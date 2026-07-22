<?php

declare(strict_types=1);

/**
 * Claude Haiku で AI Web 検索計画（バリアント仮説）を1回生成する。
 */
class FoodWebSearchPlanService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const MAX_TOKENS = 768;

    private const SYSTEM_PROMPT = <<<'PROMPT'
あなたは日本国内の食品・外食メニューに詳しい専門家です。
Web検索はせず、カロリーも推定しません。実在を保証しません。
入力をもとに、後続のWeb検索のための計画だけをJSONで返してください。
分からない場合は空配列を返し、不明な容量やサイズを作らないでください。
自炊・手作り料理は searchMode=no_web_search にしてください。
JSON以外を返さないでください。
PROMPT;

    public function __construct(
        private readonly FoodVariantAnalyzer $variantAnalyzer = new FoodVariantAnalyzer(),
        private readonly FoodWebSearchPlanInputGuard $planInputGuard = new FoodWebSearchPlanInputGuard(),
    ) {
    }

    /**
     * @return FoodWebSearchPlan|'not_food'|null
     */
    public function createPlan(string $userInput, string $apiKey, WebSearchBudget $budget): FoodWebSearchPlan|string|null
    {
        if (!$budget->canCallHaiku()) {
            return FoodWebSearchPlan::fallbackFromInput($userInput, $this->variantAnalyzer);
        }

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($userInput),
                ],
            ],
        ];

        $decoded = $this->postToAnthropic($payload, $apiKey);
        if ($decoded === null) {
            return null;
        }

        $budget->recordHaikuCall();
        $text = $this->extractText($decoded);

        return $this->parsePlanResponse($text, $userInput);
    }

    /**
     * @return FoodWebSearchPlan|'not_food'|null
     */
    private function parsePlanResponse(string $text, string $userInput): FoodWebSearchPlan|string|null
    {
        if ($text === '') {
            return null;
        }

        foreach ($this->extractJsonCandidates($text) as $candidate) {
            $json = json_decode($candidate, true);
            if (!is_array($json)) {
                continue;
            }

            if (($json['error'] ?? null) === 'not_food') {
                return 'not_food';
            }

            if (($json['isFood'] ?? true) === false) {
                return 'not_food';
            }

            $plan = FoodWebSearchPlan::fromArray($json);
            if ($plan->normalizedProductName === '') {
                $plan = FoodWebSearchPlan::fromArray(array_merge($json, [
                    'normalizedProductName' => $this->variantAnalyzer->extractBaseProductName($userInput),
                ]));
            }

            return $this->planInputGuard->apply($userInput, $plan);
        }

        return null;
    }

    private function buildPrompt(string $userInput): string
    {
        return <<<PROMPT
以下の入力について、AI Web検索計画を返してください。

入力: {$userInput}

返却JSONスキーマ:
{
  "isFood": true,
  "normalizedProductName": "正式商品名",
  "brandName": "ブランド名またはnull",
  "productType": "restaurant_menu|packaged_food|beverage|prepared_food|homemade_food|unknown",
  "variantAnalysis": {
    "likelyHasVariants": true,
    "dimension": "named_size|serving_size|weight|volume|count|portion|container|multiple|none|unknown",
    "expectedLabels": ["候補ラベル"],
    "confidence": "high|medium|low"
  },
  "searchMode": "single_product|variant_list_page|product_list_page|no_web_search",
  "queryTerms": ["検索補助語"]
}

ルール:
- 日本国内で販売されている食品を想定
- brandName は入力中に明示されている場合のみ。推測で埋めない。不明なら null
- expectedLabels は検索仮説（空でもよい）
- queryTerms に入力にないブランド名やメーカー推測を入れない
- 自炊料理は no_web_search
- 牛丼は serving_size、並盛/大盛など
- 外食ポテト等は named_size、S/M/L
- 市販スナックは weight や product_list_page
PROMPT;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function postToAnthropic(array $payload, string $apiKey): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractText(array $decoded): string
    {
        $parts = [];
        foreach ($decoded['content'] ?? [] as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'text') {
                continue;
            }

            $parts[] = (string) ($block['text'] ?? '');
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return list<string>
     */
    private function extractJsonCandidates(string $text): array
    {
        $candidates = [];
        if (preg_match('/\{[\s\S]*\}/u', $text, $match) === 1) {
            $candidates[] = $match[0];
        }

        return $candidates;
    }
}
