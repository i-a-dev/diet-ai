<?php

declare(strict_types=1);

/**
 * Claude Haiku 4.5 で食品名からカロリー（kcal）を推定するサービス。
 * mode=web のとき（変更後）:
 * 1. Claude Haiku で検索計画（1回）
 * 2. 固定テンプレートで Brave 検索（通常1〜2回、最大4回）
 * 3. 上位URL HTML から複数バリアント抽出（最大8URL）
 * 4. 候補0件のみ Claude Web Search フォールバック（最大1回）
 */
final class CalorieEstimateService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const SYSTEM_PROMPT = 'あなたは日本の食品・料理全般に詳しいカロリー推定の専門家です。口に入れて摂取するもの（料理・飲み物・お菓子・ゼリー・サプリ・機能性食品・市販品）は食品として扱ってください。明らかに食べないもの（洗剤・化粧品・金属など）だけ {"error":"not_food"} を返してください。商品名が不明確でも食べ物の可能性がある場合は not_food にせず推定してください。confidenceは「標準量を仮定できるか」ではなく「その仮定を置いても推定値がどれだけ安定するか」を表します。代表値を出せても、追加情報で数百kcal変わり得るなら low にしてください。should_offer_web_searchはconfidenceとは別軸で、Web上に特定可能な商品・栄養成分情報がありそうかを判定してください。食品の場合はJSONのみ返答し、前置きや説明は不要です。';
    private const PRODUCT_CANDIDATE_SYSTEM_PROMPT = 'あなたは日本の市販食品に詳しい専門家です。Web検索はせず、カロリー推定もしません。入力が食べ物でない場合のみ {"error":"not_food"} を返してください。食品の場合は、日本国内で販売されている可能性が高い市販食品候補を最大3件返してください。JSONのみ返答し、前置きや説明は不要です。';
    private const WEB_SEARCH_SYSTEM_PROMPT = 'あなたは日本の食品・市販品に詳しい専門家です。口に入れて摂取するものは食品として扱ってください。明らかに食べないものだけ {"error":"not_food"} を返してください。食品の場合は web_search で商品を調べ、正式な商品名とその商品の栄養成分・カロリーが載っているページ URL を返してください。まとめ記事・ブログよりメーカー公式・商品詳細ページを優先してください。最終回答はJSONのみ。前置きや説明は不要です。';
    private const WEB_SEARCH_MAX_TOKENS = 1024;
    private const PRODUCT_CANDIDATE_MAX_TOKENS = 512;
    private const MAX_WEB_SEARCH_URL_FETCHES = 5;
    private const MAX_PRODUCT_CANDIDATES = 3;

    public function __construct(
        private readonly ?BraveNutritionSearchService $braveNutritionSearch = null,
        private readonly ?NutritionPageExtractor $nutritionPageExtractor = null,
        private readonly ?FoodVariantAnalyzer $variantAnalyzer = null,
        private readonly ?AiWebSearchService $aiWebSearchService = null,
    ) {
    }

    /**
     * 食品名からカロリーを推定する（公開 API）。
     * mode:
     * - auto: 従来どおり high 以外で Web 検索を試行
     * - no_web: Web 検索を使わず 1 回のみ推定
     * - web: Brave → Claude no_web 商品名補正 → Brave 再検索 → Claude Web Search
     *
     * @return array{
     *   kcal?: int,
     *   assumed_weight_g?: int,
     *   assumption?: string,
     *   confidence?: string,
     *   should_offer_web_search?: bool,
     *   web_search_reason?: string,
     *   product_name?: string,
     *   source_url?: string,
     *   source?: string,
     *   identity_confidence?: string,
     *   needs_confirmation?: bool,
     *   candidates?: list<array{
     *     product_name: string,
     *     brand?: string,
     *     kcal: int,
     *     source_url?: string,
     *     source: string,
     *     identity_confidence: string,
     *     base_product_name?: string,
     *     variant_label?: string,
     *     variant_confidence?: string,
     *     serving_weight_g?: int|null,
     *     package_size?: string|null
     *   }>,
     *   reason?: string
     * }
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
            return $this->estimateWithWebSearchFlow($trimmed, $apiKey);
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

    private function resolveVariantAnalyzer(): FoodVariantAnalyzer
    {
        return $this->variantAnalyzer ?? new FoodVariantAnalyzer();
    }

    private function resolveAiWebSearchService(?string $provider = null): AiWebSearchService
    {
        if ($this->aiWebSearchService !== null) {
            return $this->aiWebSearchService;
        }

        $resolvedProvider = AiWebSearchProvider::resolve($provider);
        $claudeFallback = null;
        if (AiWebSearchProvider::allowsClaudeFallback($resolvedProvider)) {
            $claudeFallback = function (string $trimmed, string $apiKey): ?array {
                $inputAnalysis = $this->resolveVariantAnalyzer()->analyzeInput($trimmed);

                return $this->estimateWithClaudeWebSearchFallback($trimmed, $apiKey, $inputAnalysis);
            };
        }

        return new AiWebSearchService(
            claudeWebSearchFallback: $claudeFallback,
            searchProvider: $resolvedProvider,
        );
    }

    private function estimateWithWebSearchFlow(string $trimmed, string $apiKey): array
    {
        // 一時無効: 「鶏もも」+「焼き」等で市販メニューが自炊誤判定されるため
        // if ($this->looksLikeHomeCookedMeal($trimmed)) {
        //     throw new RuntimeException('商品検索ではなく通常のAI推定をご利用ください。');
        // }

        $provider = AiWebSearchProvider::resolve();

        if ($provider === AiWebSearchProvider::CLAUDE_ONLY) {
            return $this->estimateWithClaudeOnlyWebSearch($trimmed, $apiKey);
        }

        $result = $this->resolveAiWebSearchService($provider)->search($trimmed, $apiKey);

        if (($result['web_search_status'] ?? '') === 'no_web_search') {
            throw new RuntimeException('商品検索ではなく通常のAI推定をご利用ください。');
        }

        if (($result['web_search_status'] ?? '') === 'estimated_fallback') {
            throw new RuntimeException('正確な商品情報を確認できませんでした。');
        }

        if (($result['needs_confirmation'] ?? false) === true) {
            return $result;
        }

        return $result;
    }

    /**
     * claude_only: Haiku 計画・Brave を使わず Claude Web Search 直。
     *
     * @return array<string, mixed>
     */
    private function estimateWithClaudeOnlyWebSearch(string $trimmed, string $apiKey): array
    {
        error_log('[ai_web_search] ' . json_encode([
            'userInput' => $trimmed,
            'searchProvider' => AiWebSearchProvider::CLAUDE_ONLY,
            'stoppedReason' => 'claude_only_direct',
        ], JSON_UNESCAPED_UNICODE));

        try {
            $inputAnalysis = $this->resolveVariantAnalyzer()->analyzeInput($trimmed);
            $result = $this->estimateWithClaudeWebSearchFallback($trimmed, $apiKey, $inputAnalysis);
        } catch (RuntimeException) {
            throw new RuntimeException('正確な商品情報を確認できませんでした。');
        }

        if (($result['web_search_status'] ?? '') === 'estimated_fallback') {
            throw new RuntimeException('正確な商品情報を確認できませんでした。');
        }

        if (($result['web_search_status'] ?? '') === 'no_web_search') {
            throw new RuntimeException('商品検索ではなく通常のAI推定をご利用ください。');
        }

        return $result;
    }

    /**
     * Brave 側でサイズ違い候補が十分集まっている場合、AI 候補の再検索を省略する。
     *
     * @param list<array<string, mixed>> $candidates
     */
    private function hasStrongVariantCoverage(array $candidates): bool
    {
        if (count($candidates) < 2) {
            return false;
        }

        $variantAnalyzer = $this->resolveVariantAnalyzer();
        $mapped = array_map(
            static fn (array $candidate): array => [
                'variant_label' => $candidate['variant_label'] ?? '通常サイズ',
                'base_product_name' => $candidate['base_product_name'] ?? $candidate['product_name'] ?? '',
                'kcal' => $candidate['kcal'] ?? 0,
            ],
            $candidates,
        );

        if (!$variantAnalyzer->hasDistinctVariants($mapped)) {
            return false;
        }

        $variantKcals = [];
        foreach ($candidates as $candidate) {
            $key = $variantAnalyzer->buildCandidateDedupeKey($candidate);
            $kcal = (int) ($candidate['kcal'] ?? 0);
            if ($kcal <= 0) {
                continue;
            }

            if (!isset($variantKcals[$key])) {
                $variantKcals[$key] = $kcal;
                continue;
            }

            if ($variantKcals[$key] !== $kcal) {
                return false;
            }
        }

        return count($variantKcals) >= 2;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array{
     *   variant_risk: string,
     *   has_explicit_variant: bool,
     *   input_variant_label?: string|null,
     *   input_serving_weight_g?: int|null,
     *   input_package_size?: string|null
     * } $inputAnalysis
     * @return array<string, mixed>
     */
    private function resolveWebSearchOutcome(string $trimmed, array $candidates, array $inputAnalysis): array
    {
        $variantAnalyzer = $this->resolveVariantAnalyzer();

        if ($variantAnalyzer->canAutoConfirm($inputAnalysis, $candidates)) {
            return $this->formatSingleWebResult($candidates[0]);
        }

        $reason = $variantAnalyzer->hasDistinctVariants($candidates)
            ? 'variant_ambiguous'
            : 'identity_ambiguous';

        if (($inputAnalysis['variant_risk'] ?? 'low') !== 'low' && count($candidates) >= 1) {
            $reason = 'variant_ambiguous';
        }

        $candidates = $this->filterVariantConfirmationCandidates($candidates, $reason);

        return $this->formatConfirmationResponse($candidates, $reason);
    }

    /**
     * サイズ確認 UI では、ブログ記事などの「通常サイズ」候補を除外する。
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function filterVariantConfirmationCandidates(array $candidates, string $reason): array
    {
        if ($reason !== 'variant_ambiguous') {
            return $candidates;
        }

        $variantAnalyzer = $this->resolveVariantAnalyzer();
        $explicitVariants = array_values(array_filter(
            $candidates,
            static function (array $candidate): bool {
                $variant = trim((string) ($candidate['variant_label'] ?? '通常サイズ'));

                return $variant !== '' && $variant !== '通常サイズ';
            },
        ));

        if (count($explicitVariants) < 2) {
            return $candidates;
        }

        $distinct = [];
        foreach ($explicitVariants as $candidate) {
            $key = $variantAnalyzer->buildCandidateDedupeKey($candidate);
            if (!isset($distinct[$key])) {
                $distinct[$key] = $candidate;
                continue;
            }

            $distinct[$key] = $this->preferWebCandidate($distinct[$key], $candidate);
        }

        return array_values($distinct);
    }

    /**
     * @param array{
     *   kcal: int,
     *   confidence: string,
     *   product_name: string,
     *   brand?: string,
     *   source_url?: string,
     *   source: string,
     *   identity_confidence: string
     * } $result
     * @return array{
     *   kcal: int,
     *   confidence: string,
     *   product_name: string,
     *   source_url?: string,
     *   source: string,
     *   identity_confidence: string,
     *   needs_confirmation: bool
     * }
     */
    private function formatSingleWebResult(array $result): array
    {
        $formatted = [
            'kcal' => $result['kcal'],
            'confidence' => $result['confidence'],
            'product_name' => $result['product_name'],
            'source' => $result['source'],
            'identity_confidence' => $result['identity_confidence'],
            'needs_confirmation' => false,
        ];

        foreach ([
            'brand',
            'source_url',
            'source_title',
            'base_product_name',
            'variant_label',
            'variant_confidence',
            'serving_weight_g',
            'package_size',
        ] as $field) {
            if (array_key_exists($field, $result) && $result[$field] !== null && $result[$field] !== '') {
                $formatted[$field] = $result[$field];
            }
        }

        return $formatted;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array{needs_confirmation: bool, reason: string, candidates: list<array<string, mixed>>, variant_dimension?: string, web_search_status?: string, allow_manual_variant?: bool, allow_estimated_add?: bool}
     */
    private function formatConfirmationResponse(
        array $candidates,
        string $reason = 'identity_ambiguous',
        string $variantDimension = 'unknown',
    ): array {
        $formatted = [];

        foreach ($candidates as $candidate) {
            $item = [
                'product_name' => $candidate['product_name'],
                'kcal' => $candidate['kcal'],
                'source' => $candidate['source'],
                'identity_confidence' => $candidate['identity_confidence'],
            ];

            foreach ([
                'brand',
                'source_url',
                'source_title',
                'base_product_name',
                'variant_label',
                'variant_confidence',
                'variant_dimension',
                'serving_weight_g',
                'package_size',
                'alias_id',
                'evidence_text',
                'verification_confidence',
                'source_type',
            ] as $field) {
                if (($candidate[$field] ?? null) !== null && ($candidate[$field] ?? '') !== '') {
                    $item[$field] = $candidate[$field];
                }
            }

            $formatted[] = $item;
        }

        return [
            'needs_confirmation' => true,
            'reason' => $reason,
            'web_search_status' => 'needs_variant_confirmation',
            'variant_dimension' => $variantDimension,
            'allow_manual_variant' => true,
            'allow_estimated_add' => true,
            'candidates' => $formatted,
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function preferWebCandidate(array $current, array $incoming): array
    {
        return $this->webCandidateQualityScore($incoming) > $this->webCandidateQualityScore($current)
            ? $incoming
            : $current;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function webCandidateQualityScore(array $candidate): int
    {
        $score = 0;

        if (($candidate['is_official_url'] ?? false) === true) {
            $score += 120;
        }

        $source = (string) ($candidate['source'] ?? '');
        if ($source === 'brave_html') {
            $score += 60;
        } elseif ($source === 'claude_web_search') {
            $score += 40;
        } elseif ($source === 'alias_db') {
            $score += 10;
        }

        if (!empty($candidate['source_url'])) {
            $host = strtolower((string) parse_url((string) $candidate['source_url'], PHP_URL_HOST));
            if (str_contains($host, 'kalori.jp')) {
                $score += 30;
            }
            if (str_contains($host, 'fatsecret')) {
                $score -= 80;
            }
            $score += 5;
        }

        $score += min(mb_strlen((string) ($candidate['product_name'] ?? '')), 40);

        return $score;
    }

    /**
     * @param array{product_name: string, brand?: string} $candidate
     */
    private function buildCandidateSearchName(array $candidate): string
    {
        $brand = trim((string) ($candidate['brand'] ?? ''));
        $productName = trim((string) ($candidate['product_name'] ?? ''));

        if ($brand !== '' && $productName !== '') {
            return $brand . ' ' . $productName;
        }

        return $productName;
    }

    /**
     * @param array{product_name: string, brand?: string} $candidate
     * @return list<'calorie'|'nutrition'>
     */
    private function buildCandidateSearchQueries(array $candidate): array
    {
        $brand = trim((string) ($candidate['brand'] ?? ''));

        return $brand !== '' ? ['calorie', 'nutrition'] : ['nutrition', 'calorie'];
    }

    /**
     * @return array{
     *   kcal: int,
     *   confidence: string,
     *   product_name: string,
     *   source_url?: string,
     *   source: string,
     *   identity_confidence: string,
     *   needs_confirmation: bool
     * }|array{
     *   needs_confirmation: bool,
     *   candidates: list<array{
     *     product_name: string,
     *     brand?: string,
     *     kcal: int,
     *     source_url?: string,
     *     source: string,
     *     identity_confidence: string,
     *     base_product_name?: string,
     *     variant_label?: string,
     *     variant_confidence?: string,
     *     serving_weight_g?: int|null,
     *     package_size?: string|null
     *   }>,
     *   reason?: string
     * }
     */
    private function estimateWithClaudeWebSearchFallback(string $trimmed, string $apiKey, ?array $inputAnalysis = null): array
    {
        $inputAnalysis ??= $this->resolveVariantAnalyzer()->analyzeInput($trimmed);
        $variantAnalyzer = $this->resolveVariantAnalyzer();
        $claudeResult = $this->requestClaudeWebIdentification($trimmed, $apiKey);
        if ($claudeResult === 'not_food' || $claudeResult === null) {
            throw new RuntimeException('カロリーを推定できませんでした。');
        }

        $productName = $claudeResult['product_name'];
        $brand = $claudeResult['brand'] ?? null;
        $brandName = is_string($brand) ? $brand : null;
        $rankedSourceUrls = $this->rankClaudeSourceUrlsLikeBrave(
            $claudeResult['source_urls'],
            $productName,
            $brandName,
            [$trimmed, $productName],
        );
        $htmlResult = $this->probeClaudeSourceUrls($rankedSourceUrls, $productName);

        $extractor = $this->resolveNutritionPageExtractor();
        $identityConfidence = $extractor->assessProductIdentity($trimmed, $productName, $brand);

        $variant = $variantAnalyzer->analyzeProduct($productName);

        $fallbackSourceUrl = $rankedSourceUrls[0]['url'] ?? null;

        if ($htmlResult !== null) {
            $confidence = $identityConfidence === 'high' ? 'high' : 'medium';
            $result = [
                'kcal' => $htmlResult['kcal'],
                'confidence' => $confidence,
                'product_name' => $productName,
                'brand' => $brand,
                'source_url' => $htmlResult['url'],
                'source' => 'claude_web_search',
                'identity_confidence' => $identityConfidence,
                'base_product_name' => $variant['base_product_name'],
                'variant_label' => $variant['variant_label'],
                'variant_confidence' => $variant['variant_confidence'],
                'serving_weight_g' => $variant['serving_weight_g'],
                'package_size' => $variant['package_size'],
            ];

            return $this->resolveWebSearchOutcome($trimmed, [$result], $inputAnalysis);
        }

        $fallbackConfidence = $claudeResult['confidence'] === 'high' ? 'medium' : $claudeResult['confidence'];
        if ($identityConfidence !== 'high') {
            $fallbackConfidence = $fallbackConfidence === 'high' ? 'medium' : $fallbackConfidence;
        }

        $result = [
            'kcal' => $claudeResult['kcal'],
            'confidence' => $fallbackConfidence,
            'product_name' => $productName,
            'brand' => $brand,
            'source' => 'claude_web_search',
            'identity_confidence' => $identityConfidence,
            'base_product_name' => $variant['base_product_name'],
            'variant_label' => $variant['variant_label'],
            'variant_confidence' => $variant['variant_confidence'],
            'serving_weight_g' => $variant['serving_weight_g'],
            'package_size' => $variant['package_size'],
        ];

        // HTMLからkcalを取れなくても、Claudeが返した参照URLは残す。
        if (is_string($fallbackSourceUrl) && $fallbackSourceUrl !== '') {
            $result['source_url'] = $fallbackSourceUrl;
        }

        return $this->resolveWebSearchOutcome($trimmed, [$result], $inputAnalysis);
    }

    private function looksLikeHomeCookedMeal(string $foodName): bool
    {
        $normalized = mb_strtolower(trim($foodName));

        $markers = [
            '自炊',
            '手作り',
            '自家製',
            '炒め',
            '焼き',
            '煮付け',
            '定食',
            'ご飯',
            '大根',
            'キャベツ',
            '鶏むね',
            '鶏もも',
            '豚肉',
            '牛肉',
            'サラダ',
            '味噌汁',
            'スープ',
            'カレー',
            'シチュー',
            '鍋',
        ];

        $matchCount = 0;
        foreach ($markers as $marker) {
            if (mb_strpos($normalized, mb_strtolower($marker)) !== false) {
                $matchCount++;
            }
        }

        return $matchCount >= 2 || mb_strpos($normalized, '自炊') !== false;
    }

    /**
     * Claude Haiku no_web で市販食品候補を返す。
     *
     * @return list<array{product_name: string, brand: string, confidence: string}>|'not_food'
     */
    private function requestClaudeProductCandidates(string $foodName, string $apiKey): array|string
    {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => self::PRODUCT_CANDIDATE_MAX_TOKENS,
            'system' => self::PRODUCT_CANDIDATE_SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildProductCandidatePrompt($foodName),
                ],
            ],
        ];

        $decoded = $this->postToAnthropic($payload, $apiKey, false);
        $text = $this->extractText($decoded);

        return $this->parseProductCandidateResponse($text);
    }

    private function buildProductCandidatePrompt(string $foodName): string
    {
        return <<<PROMPT
以下の入力が食品かどうかを判定し、食品の場合は日本国内で販売されている可能性が高い市販食品候補を最大3件返してください。
カロリー推定はしないでください。Web検索はしないでください。
サイズ違い（S/M/L、BIG、内容量違い）がある商品は、サイズごとに別候補として返してください。

入力: {$foodName}

最終回答は JSON のみ。前置きや説明は不要。

食品の場合:
{"candidates":[{"product_name":"正式な商品名","brand":"ブランド名","confidence":"high"|"medium"|"low"}]}
非食品の場合: {"error":"not_food"}
PROMPT;
    }

    /**
     * @return list<array{product_name: string, brand: string, confidence: string}>|'not_food'
     */
    private function parseProductCandidateResponse(string $text): array|string
    {
        if ($text === '') {
            return [];
        }

        foreach ($this->extractJsonCandidates($text) as $candidate) {
            $json = json_decode($candidate, true);

            if (!is_array($json)) {
                continue;
            }

            if ($this->isNotFoodResponse($json)) {
                return 'not_food';
            }

            if (!isset($json['candidates']) || !is_array($json['candidates'])) {
                continue;
            }

            $parsed = [];

            foreach ($json['candidates'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $productName = trim((string) ($item['product_name'] ?? ''));
                if ($productName === '') {
                    continue;
                }

                $brand = trim((string) ($item['brand'] ?? ''));
                $confidence = (string) ($item['confidence'] ?? 'medium');
                if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                    $confidence = 'medium';
                }

                $parsed[] = [
                    'product_name' => $productName,
                    'brand' => $brand,
                    'confidence' => $confidence,
                ];

                if (count($parsed) >= self::MAX_PRODUCT_CANDIDATES) {
                    break;
                }
            }

            if ($parsed !== []) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * Claude API を1回呼び出し、推定結果を返す。
     *
     * @return array{kcal: int, assumed_weight_g?: int, assumption?: string, confidence: string, should_offer_web_search: bool, web_search_reason?: string, product_name?: string, source_url?: string}|'not_food'|null
     */
    private function requestEstimate(string $foodName, string $apiKey): array|string|null
    {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 320,
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
     * @return array{product_name: string, brand?: string, source_urls: list<string>, kcal: int, confidence: string}|'not_food'|null
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
{"product_name": "正式な商品名", "brand": "ブランド名またはnull", "source_urls": ["URL1", "URL2"], "kcal": 整数, "confidence": "high"|"medium"|"low"}
- brand はメーカー・店舗・サービス名。入力やページに明示がある場合のみ。推測で埋めない。不明なら null
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
     * Claude が返した URL を、Brave 単品検索と同じ WebSearchUrlRanker で並べ替える。
     * Brave 経路のコードは変更しない（このメソッドは Claude フォールバック専用）。
     *
     * @param list<string> $sourceUrls
     * @param list<string> $storeContextTexts
     * @return list<array{url: string, score: int}>
     */
    private function rankClaudeSourceUrlsLikeBrave(
        array $sourceUrls,
        string $productName,
        ?string $brandName = null,
        array $storeContextTexts = [],
    ): array {
        if ($sourceUrls === []) {
            return [];
        }

        // コンビニ公式ホスト寄せは従来どおり Claude 側のみで先行適用する。
        $sourceUrls = $this->prioritizeOfficialStoreUrls($sourceUrls, $storeContextTexts);

        $results = [];
        foreach ($sourceUrls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }

            $results[] = [
                'url' => $url,
                'title' => '',
                'description' => '',
            ];
        }

        if ($results === []) {
            return [];
        }

        $ranked = (new WebSearchUrlRanker($this->resolveNutritionPageExtractor()))->rank(
            $results,
            $productName,
            $brandName,
            'single_product',
        );

        return array_values(array_map(
            static fn (array $entry): array => [
                'url' => (string) $entry['url'],
                'score' => (int) $entry['score'],
            ],
            $ranked,
        ));
    }

    /**
     * @param list<array{url: string, score: int}> $rankedUrls
     * @return array{kcal: int, url: string, score: int}|null
     */
    private function probeClaudeSourceUrls(array $rankedUrls, string $productName): ?array
    {
        if ($rankedUrls === []) {
            return null;
        }

        $extractor = $this->resolveNutritionPageExtractor();
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
     * @return array{product_name: string, brand?: string|null, source_urls: list<string>, kcal: int, confidence: string}|'not_food'|null
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

            $brand = trim((string) ($json['brand'] ?? ''));
            if ($brand === '' || strtolower($brand) === 'null') {
                $brand = null;
            }

            return [
                'product_name' => $productName,
                'brand' => $brand,
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
【confidenceの意味】
- confidenceは「栄養成分を完全に正確に特定できるか」でも「標準量を仮定できるか」でもない
- confidenceが表すのは「標準的な仮定を置いたときに、推定結果がどれだけ安定するか」
- 「一般的な標準量を仮定できる」ことと「confidenceが高い」ことは同義ではない
- 標準量を仮定できても、追加情報で推定値が大きく変わるなら confidence は低くする
- サイズ違いなど小さな誤差しかない食品は high を優先する
- AI推定である以上すべての結果に推定要素がある。完全性ではなく、仮定後の推定安定性で判定する

【confidenceの判定手順】（この順で考える）
1. 入力から食品または料理を特定できるか
2. 入力から量を判断できるか、または標準量を仮定できるか
3. その仮定を置いたとき、追加情報があっても推定値は安定するか
4. 追加情報による変動幅はどの程度か（数十kcalか、数百kcalか）
5. 代表値を提示しても、多くのケースで妥当と言えるか
標準仮定後も推定値がほとんど変わらない（追加情報でもおおよそ数十kcal以内） → high
標準仮定で代表値は出せるが、追加情報でおおよそ100kcal前後の幅がある → medium
標準仮定を置いても、一般的な別形態同士でおおよそ200〜300kcal以上変わり得る → low（代表値を出せても low）

特に注意:
- 「一人前を仮定できる」だけでは high/medium にしない
- 同じ料理名の一般的なバリエーション（種類・具材・ソース・店舗・セット内容）を思い浮かべ、その幅がおおよそ200kcal以上なら low
- 量だけ仮定して特定の一形態に寄せた代表値を出せても、他の一般的な形態と大きくずれるなら low
- ソースや調理法の違いだけで一般に数百kcal差が出る料理カテゴリ名のみの入力は low

【confidenceの基準】
- high: 標準的な仮定を置いても推定値がほとんど変わらない
  - 追加情報があっても推定カロリーは大きく変動しない
  - 日常の食事記録として十分安定した推定ができる
  - サイズ違い・個体差程度の小さな誤差しかない
  - 個数や重量が明確な一般食品は、小さなサイズ差だけでは下げない
- medium: 標準的な仮定を置けば推定できるが、追加情報である程度カロリーが変わる
  - ただし代表値として提示する価値はある
  - 種類・サイズ・具材で無視できない幅があるが、一般的な別形態同士の差はおおよそ100kcal前後までに収まりやすい
  - 日常記録には使えるが、追加情報があると明確に精度が上がる
- low: 標準的な仮定を置いても、追加情報によって推定結果が大きく変わる
  - 代表値を提示できたとしても confidence は low にする
  - 次のような要因でおおよそ200〜300kcal以上の差が生じる可能性がある場合は low
    - 主菜の種類、調理方法、店舗、レシピ、トッピング、セット内容、ご飯量、副菜構成、ソース・味の系統
  - 料理カテゴリ名だけで、一般的なバリエーション同士の差がおおよそ200kcal以上ある場合は low
  - 「代表値が出せるから medium」「一人前を仮定したから medium」にはしない。変動が大きいなら low

【避ける判定】
- 標準量を仮定できるから high/medium にする
- 代表値を1つ提示できるから medium 以上にする
- 正確な商品ラベルがないため low（それだけでは不可）
- 厳密な重量がないため low（それだけでは不可）
- 個体差・小さなサイズ違いがあるため low（それだけでは不可）
- 少しでも推定を含むため medium/low
- 完全な正解を保証できないため low

【推定値とconfidenceの整合】
- カロリー値の推定自体は、妥当な標準仮定で行う
- ただし confidence は、その代表値がどれだけ安定するかを別軸で判定する
- 推定値に不安があるからとりあえず low にするのではなく、変動要因の大きさで判定する
- 標準仮定を使ったこと自体は confidence を下げる理由にしない（安定していれば high 可）

【出力前の自己確認】
- 追加情報によって推定値は何kcal程度変わる可能性があるか
- 標準的な仮定を置いても結果は安定しているか
- 主菜・レシピ・ソース・店舗が変わるだけで数百kcal変わらないか
- 同じ入力に対する一般的な別形態（例: 別の具材・味・セット内容）も、今の代表値で妥当と言えるか
- 追加情報による変動が非常に大きい場合は、代表値を提示できても low にする
- 「一人前を仮定できた」だけで medium 以上にしていないか
- サイズ差程度の小さな誤差を過大評価して medium/low にしていないか

【should_offer_web_search の意味】
- confidence とは別軸。混同しない
- confidence は AI推定値の安定性
- should_offer_web_search は「Web上に特定可能な商品情報・栄養成分情報が存在しそうか」
- medium/low であることだけを理由に true にしない
- confidence=high であることだけを理由に false にしない
- ブランド・メーカー・店舗・具体的商品名があり、公式や商品ページで裏取りできそうなら、confidence が high でも必ず true
- AIがカロリーを知っている・確信があることと、Web検索不要は同義ではない
- 逆に confidence=low でも、一般料理名だけで商品特定できなければ false

【should_offer_web_search = true】
Web検索で商品や栄養成分を特定できる可能性が高い場合:
- 具体的な商品名が含まれている
- ブランド名・メーカー名が含まれている
- コンビニ・スーパー・飲食チェーン・店舗名・宅配弁当ブランドが含まれている
- パッケージ商品や市販品である可能性が高い
- 商品ページや公式の栄養成分ページが存在しそう
- Web検索によって、推定値より正確な情報を取得できる可能性が高い
- 知名度の高い市販品・チェーン商品・ブランド既製品は、AIがカロリーを知っていても、confidence が high でも true（公式情報の確認に有用）

【should_offer_web_search = false】
Web検索しても特定商品へ到達しにくく、精度向上が期待できない場合:
- 一般的な料理名だけ
- 家庭料理や手作り料理
- 内容が不明な弁当や定食
- 店舗名・ブランド名・商品名がない
- 材料や量の追加情報がなければ特定できない
- Web検索しても一般レシピや幅の広い情報しか得られない
- Web検索より、ユーザーが内容を編集した方が精度向上につながる
- ゆで卵・白米など一般食品で、Web検索しても商品ページ特定の意味が薄い場合

【組み合わせの許容】
- high / true（市販・ブランド・チェーン商品で公式ページがありそうな場合。こちらを優先）
- high / false（ゆで卵・白米など一般食品のみ）
- medium / true または false
- low / true または false
confidence から機械的に決めない。特に「high だから false」にはしない

【判定の目安（校准用・個別例外処理ではない）】
- high になりやすい: ゆで卵2個、白米150g、牛乳200ml、バナナ1本（量が明確で、追加情報でも推定値が大きく変わらない）
- medium になりやすい: おにぎり1個、食パン1枚、味噌汁1杯（多少の種類差はあるが代表値として使える）
- low になりやすい: 日替わり定食、定食、弁当、ラーメン、カレー、サラダ、パスタ
  （主菜・レシピ・店舗・ソース・セット内容などでおおよそ200kcal以上変わり得る。一人前を仮定できても low）
- should_offer_web_search=true の目安（confidence が high でも true）: カップヌードル シーフード、セブンイレブン 鮭おにぎり、ファミチキ、ザバス ミルクプロテイン、ほっともっと のり弁当、マクドナルド ビッグマック、ローソン のり弁当、ナッシュ たらと旨辛チリソース
- should_offer_web_search=false の目安: お弁当、日替わり定食、手作り弁当、カレー、ラーメン、サラダ、ハンバーグ、パスタ、朝食セット、ゆで卵 2個、白米 150g
TEXT;

        return <<<PROMPT
以下の入力が食品（食べ物・飲み物）かどうかをまず判定し、食品の場合のみカロリーを推定してください。
量の表記がなければ一般的な1食分を仮定してください。

【食品の判定】
- 口に入れて摂取するものは食品として扱う（料理・飲み物・お菓子・ゼリー・サプリ・機能性食品・市販品を含む）
- ダイエットゼリーやサプリメントも、食べる・飲むものであれば食品としてカロリーを推定する
- 商品名が不明確・存在が確認できない場合でも、食べ物の可能性があるなら not_food にせず推定する（食品・量が極端に曖昧なときだけ confidence: low）
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
- ただし定食・弁当・セットなど、主菜や構成が不明で数百kcal変動し得る入力は、代表値を出しても confidence は low
- 料理カテゴリ名だけで、店舗・レシピ・具材・ソース・量により数百kcal変わりやすい入力も、代表値や一人前仮定ができても confidence は low
- 種類やサイズで多少変わるが数百kcalまでは振れにくいものは medium
- コンビニ・外食チェーン・市販品など商品名が含まれる場合はその商品の公式カロリーを優先する
- 商品名が特定できない場合は同カテゴリの平均値で推定する
- 公式ラベルが無くても、推定値が安定していれば high、ある程度の幅なら medium、大きく振れるなら low（ラベル不足だけでは判定しない）
- 標準量を仮定した場合は必要に応じて assumed_weight_g や assumption（短い説明）を返す。標準仮定を使ったこと自体は confidence を下げる理由にしない

最終回答はJSONのみ。前置きや説明は不要。

食品の場合の形式:
- 通常: {"kcal": 整数, "confidence": "high"|"medium"|"low", "should_offer_web_search": trueまたはfalse, "web_search_reason": "短い理由"}
- 市販・ブランド・チェーン商品の例: {"kcal": 整数, "confidence": "high"|"medium"|"low", "should_offer_web_search": true, "web_search_reason": "ブランド商品で公式ページ確認が可能"}
- 一般食品でWeb検索不要の例: {"kcal": 整数, "confidence": "high", "should_offer_web_search": false, "web_search_reason": "一般食品で商品ページ特定の意味が薄い"}
- 重量(g)が分かる場合は "assumed_weight_g": 整数 を追加してよい。カロリー値を明示できる場合は任意で "labeled_kcal": 整数 を追加してよい（kcal と同じ値）。labeled_kcal があっても、市販・ブランド品なら should_offer_web_search は true のまま
- 標準量を仮定した場合は任意で "assumption": "短い説明" を追加してよい
- 商品名が特定できた場合: 上記に "product_name": "正式な商品名" を追加
- labeled_kcal がある場合は kcal も必ず同じ値にする
- 公式ページに重量(g)が無い high の場合でも、標準量を仮定したなら assumed_weight_g を含めてよい
- should_offer_web_search と web_search_reason は必ず含める
- web_search_reason は短く（80文字以内）。ユーザー向け文言ではなく判定根拠
- 「カロリーを知っている」「confidence が high」だけを理由に should_offer_web_search を false にしない
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
     * @return array{kcal: int, assumed_weight_g?: int, assumption?: string, confidence: string, should_offer_web_search: bool, web_search_reason?: string, product_name?: string, source_url?: string}|'not_food'|null
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

        if (preg_match_all('/\{[^{}]*(?:"error"\s*:\s*"not_food"|"kcal"\s*:\s*\d+|"product_name"\s*:|"source_urls"\s*:|"candidates"\s*:)[^{}]*\}/s', $text, $matches) === 1) {
            foreach ($matches[0] as $match) {
                $candidates[] = $match;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * パース済み JSON を画面用の推定結果に正規化する。
     * kcal・assumed_weight_g・assumption・confidence・should_offer_web_search のバリデーションと型変換を行う。
     *
     * @param array<string, mixed> $json
     * @return array{kcal: int, assumed_weight_g?: int, assumption?: string, confidence: string, should_offer_web_search: bool, web_search_reason?: string, product_name?: string, source_url?: string}|null
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

        $shouldOfferWebSearch = $this->normalizeShouldOfferWebSearch(
            $json['should_offer_web_search'] ?? null,
        );

        $normalized = [
            'kcal' => $kcal,
            'confidence' => $confidence,
            'should_offer_web_search' => $shouldOfferWebSearch,
        ];

        if (isset($json['assumed_weight_g']) && is_numeric($json['assumed_weight_g'])) {
            $assumedWeightG = (int) round((float) $json['assumed_weight_g']);
            if ($assumedWeightG > 0) {
                $normalized['assumed_weight_g'] = $assumedWeightG;
            }
        }

        $assumption = trim((string) ($json['assumption'] ?? ''));
        if ($assumption !== '') {
            $normalized['assumption'] = mb_substr($assumption, 0, 120);
        }

        $webSearchReason = trim((string) ($json['web_search_reason'] ?? ''));
        if ($webSearchReason !== '') {
            $normalized['web_search_reason'] = mb_substr($webSearchReason, 0, 120);
        }

        $productName = trim((string) ($json['product_name'] ?? ''));
        if ($productName !== '') {
            $normalized['product_name'] = $productName;
        }

        return $normalized;
    }

    /**
     * should_offer_web_search を bool に正規化する。
     * 欠落時は false（confidence からは推断しない）。
     */
    private function normalizeShouldOfferWebSearch(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return ((int) $raw) === 1;
        }

        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        return false;
    }
}
