<?php

declare(strict_types=1);

/**
 * Claude Haiku が返す AI Web 検索計画（検索仮説。UI表示用の確定データではない）。
 */
final class FoodWebSearchPlan
{
    /** @param list<string> $queryTerms */
    /** @param list<string> $expectedLabels */
    public function __construct(
        public readonly bool $isFood,
        public readonly string $normalizedProductName,
        public readonly ?string $brandName,
        public readonly string $productType,
        public readonly bool $likelyHasVariants,
        public readonly string $variantDimension,
        public readonly array $expectedLabels,
        public readonly string $variantConfidence,
        public readonly string $searchMode,
        public readonly array $queryTerms,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $expectedLabels = [];
        foreach ($data['variantAnalysis']['expectedLabels'] ?? [] as $label) {
            if (is_string($label) && trim($label) !== '') {
                $expectedLabels[] = trim($label);
            }
        }

        $queryTerms = [];
        foreach ($data['queryTerms'] ?? [] as $term) {
            if (is_string($term) && trim($term) !== '') {
                $queryTerms[] = trim($term);
            }
        }

        return new self(
            isFood: (bool) ($data['isFood'] ?? true),
            normalizedProductName: trim((string) ($data['normalizedProductName'] ?? '')),
            brandName: self::nullableString($data['brandName'] ?? null),
            productType: self::normalizeEnum(
                (string) ($data['productType'] ?? 'unknown'),
                ['restaurant_menu', 'packaged_food', 'beverage', 'prepared_food', 'homemade_food', 'unknown'],
                'unknown',
            ),
            likelyHasVariants: (bool) ($data['variantAnalysis']['likelyHasVariants'] ?? false),
            variantDimension: self::normalizeEnum(
                (string) ($data['variantAnalysis']['dimension'] ?? 'unknown'),
                ['named_size', 'serving_size', 'weight', 'volume', 'count', 'portion', 'container', 'multiple', 'none', 'unknown'],
                'unknown',
            ),
            expectedLabels: $expectedLabels,
            variantConfidence: self::normalizeEnum(
                (string) ($data['variantAnalysis']['confidence'] ?? 'low'),
                ['high', 'medium', 'low'],
                'low',
            ),
            searchMode: self::normalizeEnum(
                (string) ($data['searchMode'] ?? 'single_product'),
                ['single_product', 'variant_list_page', 'product_list_page', 'no_web_search'],
                'single_product',
            ),
            queryTerms: $queryTerms,
        );
    }

    /**
     * Claude 失敗時の安全なフォールバック計画（固定 L/M/S 検索は行わない）。
     */
    public static function fallbackFromInput(string $userInput, FoodVariantAnalyzer $analyzer): self
    {
        $subject = (new FoodSearchSubjectNormalizer())->normalize($userInput);

        return self::fallbackFromSubject($subject, $analyzer);
    }

    /**
     * 正規化済み検索対象からフォールバック計画を生成する。
     */
    public static function fallbackFromSubject(FoodSearchSubject $subject, FoodVariantAnalyzer $analyzer): self
    {
        $productName = $subject->productName !== '' ? $subject->productName : $subject->rawInput;
        $analysis = $analyzer->analyzeInput($productName);

        return new self(
            isFood: true,
            normalizedProductName: $productName,
            brandName: $subject->brandName,
            productType: 'unknown',
            likelyHasVariants: ($analysis['has_explicit_variant'] ?? false) === false
                && in_array($analysis['variant_risk'] ?? 'low', ['medium', 'high'], true),
            variantDimension: 'unknown',
            expectedLabels: [],
            variantConfidence: 'low',
            searchMode: 'single_product',
            queryTerms: ['カロリー', '栄養成分'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'isFood' => $this->isFood,
            'normalizedProductName' => $this->normalizedProductName,
            'brandName' => $this->brandName,
            'productType' => $this->productType,
            'variantAnalysis' => [
                'likelyHasVariants' => $this->likelyHasVariants,
                'dimension' => $this->variantDimension,
                'expectedLabels' => $this->expectedLabels,
                'confidence' => $this->variantConfidence,
            ],
            'searchMode' => $this->searchMode,
            'queryTerms' => $this->queryTerms,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param list<string> $allowed
     */
    private static function normalizeEnum(string $value, array $allowed, string $default): string
    {
        $normalized = trim($value);

        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }
}
