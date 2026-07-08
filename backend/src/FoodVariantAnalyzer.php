<?php

declare(strict_types=1);

/**
 * サイズ・容量・SKU など商品バリアント（規格違い）の判定を行う。
 */
final class FoodVariantAnalyzer
{
    /** @var list<string> */
    private const SIZE_VARIANT_PATTERNS = [
        'lサイズ' => 'Lサイズ',
        'ｌサイズ' => 'Lサイズ',
        'mサイズ' => 'Mサイズ',
        'ｍサイズ' => 'Mサイズ',
        'sサイズ' => 'Sサイズ',
        'ｓサイズ' => 'Sサイズ',
        'big' => 'BIG',
        'ビッグ' => 'BIG',
        'big袋' => 'BIG',
        '大盛' => '大盛',
        'ミニ' => 'ミニ',
        '小袋' => '小袋',
        '大袋' => '大袋',
        '特大' => '特大',
        'レギュラー' => '通常サイズ',
    ];

    /** @var list<string> */
    private const HIGH_RISK_CATEGORY_MARKERS = [
        'じゃがりこ',
        'ポテト',
        'ポテトチップス',
        'チップス',
        'スナック',
        'カップ',
        'ラーメン',
        '麺',
        'アイス',
        'コーラ',
        'ペプシ',
        'お茶',
        'ジュース',
        'コーヒー',
        'ドリンク',
        'フライドポテト',
        'マック',
        'マクドナルド',
        'mcdonald',
        'バーガー',
        'からあげ',
        '唐揚げ',
        'うどん',
        'そば',
        'パスタ',
        'ピザ',
        'ポッキー',
        'プリッツ',
        'チョコ',
        'グミ',
        'キャンディ',
    ];

    /** @var list<string> */
    private const LOW_RISK_MARKERS = [
        '自炊',
        '手作り',
        '定食',
        '炒め',
        '焼き',
        '煮付け',
        '味噌汁',
        'スープ',
    ];

    /**
     * @return array{
     *   variant_risk: 'low'|'medium'|'high',
     *   input_variant_label: string|null,
     *   input_serving_weight_g: int|null,
     *   input_package_size: string|null,
     *   base_product_name: string,
     *   has_explicit_variant: bool
     * }
     */
    public function analyzeInput(string $input): array
    {
        $trimmed = trim($input);
        $normalized = $this->normalizeText($trimmed);
        $variantLabel = $this->extractVariantLabel($trimmed);
        $packageSize = $this->extractPackageSize($trimmed);
        $servingWeightG = $this->extractServingWeightG($trimmed);
        $baseProductName = $this->extractBaseProductName($trimmed);
        $hasExplicitVariant = $variantLabel !== null || $packageSize !== null || $servingWeightG !== null;

        $variantRisk = $this->assessVariantRisk($normalized, $baseProductName, $hasExplicitVariant);

        return [
            'variant_risk' => $variantRisk,
            'input_variant_label' => $variantLabel,
            'input_serving_weight_g' => $servingWeightG,
            'input_package_size' => $packageSize,
            'base_product_name' => $baseProductName,
            'has_explicit_variant' => $hasExplicitVariant,
        ];
    }

    /**
     * @return array{
     *   base_product_name: string,
     *   variant_label: string,
     *   variant_confidence: 'high'|'medium'|'low',
     *   serving_weight_g: int|null,
     *   package_size: string|null
     * }
     */
    public function analyzeProduct(string $productName, ?float $servingWeightG = null): array
    {
        $variantLabel = $this->extractVariantLabel($productName) ?? '通常サイズ';
        $packageSize = $this->extractPackageSize($productName);
        $weightG = $servingWeightG !== null && $servingWeightG > 0
            ? (int) round($servingWeightG)
            : $this->extractServingWeightG($productName);

        if ($variantLabel === '通常サイズ' && $packageSize !== null) {
            $variantLabel = $packageSize;
        }

        $baseProductName = $this->extractBaseProductName($productName);
        $variantConfidence = $variantLabel === '通常サイズ' && $packageSize === null && $weightG === null
            ? 'medium'
            : 'high';

        return [
            'base_product_name' => $baseProductName,
            'variant_label' => $variantLabel,
            'variant_confidence' => $variantConfidence,
            'serving_weight_g' => $weightG,
            'package_size' => $packageSize,
        ];
    }

    /**
     * @param array{
     *   variant_risk: string,
     *   has_explicit_variant: bool
     * } $inputAnalysis
     */
    public function shouldExploreVariants(array $inputAnalysis): bool
    {
        if (($inputAnalysis['has_explicit_variant'] ?? false) === true) {
            return false;
        }

        return in_array($inputAnalysis['variant_risk'] ?? 'low', ['medium', 'high'], true);
    }

    /**
     * @param array{
     *   input_variant_label?: string|null,
     *   input_serving_weight_g?: int|null,
     *   input_package_size?: string|null
     * } $inputAnalysis
     * @param array{
     *   variant_label?: string,
     *   serving_weight_g?: int|null,
     *   package_size?: string|null
     * } $candidate
     */
    public function variantMatchesInput(array $inputAnalysis, array $candidate): bool
    {
        $inputVariant = $inputAnalysis['input_variant_label'] ?? null;
        $candidateVariant = (string) ($candidate['variant_label'] ?? '通常サイズ');

        if ($inputVariant !== null) {
            return $this->normalizeVariantLabel($inputVariant) === $this->normalizeVariantLabel($candidateVariant)
                || str_contains($this->normalizeText($candidateVariant), $this->normalizeText($inputVariant));
        }

        $inputWeight = $inputAnalysis['input_serving_weight_g'] ?? null;
        $candidateWeight = $candidate['serving_weight_g'] ?? null;
        if ($inputWeight !== null && $candidateWeight !== null) {
            return abs($inputWeight - $candidateWeight) <= 3;
        }

        $inputPackage = $inputAnalysis['input_package_size'] ?? null;
        $candidatePackage = $candidate['package_size'] ?? null;
        if ($inputPackage !== null && $candidatePackage !== null) {
            return $this->normalizeText($inputPackage) === $this->normalizeText($candidatePackage);
        }

        return false;
    }

    /**
     * @param array{
     *   variant_risk: string,
     *   has_explicit_variant: bool,
     *   input_variant_label?: string|null,
     *   input_serving_weight_g?: int|null,
     *   input_package_size?: string|null
     * } $inputAnalysis
     * @param list<array{
     *   identity_confidence?: string,
     *   variant_label?: string,
     *   serving_weight_g?: int|null,
     *   base_product_name?: string,
     *   kcal?: int
     * }> $candidates
     */
    public function canAutoConfirm(array $inputAnalysis, array $candidates): bool
    {
        if ($candidates === []) {
            return false;
        }

        if (($inputAnalysis['has_explicit_variant'] ?? false) === true) {
            $matching = array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => $this->variantMatchesInput($inputAnalysis, $candidate),
            ));

            if (count($matching) === 1) {
                return ($matching[0]['identity_confidence'] ?? '') === 'high';
            }

            return false;
        }

        if ($this->hasDistinctVariants($candidates)) {
            return false;
        }

        if (($inputAnalysis['variant_risk'] ?? 'low') !== 'low') {
            return false;
        }

        if (count($candidates) !== 1) {
            return false;
        }

        return ($candidates[0]['identity_confidence'] ?? '') === 'high';
    }

    /**
     * @param list<array{variant_label?: string, base_product_name?: string, kcal?: int}> $candidates
     */
    public function hasDistinctVariants(array $candidates): bool
    {
        if (count($candidates) <= 1) {
            return false;
        }

        $variantKeys = [];
        foreach ($candidates as $candidate) {
            $base = mb_strtolower(trim((string) ($candidate['base_product_name'] ?? '')));
            $variant = $this->normalizeVariantLabel((string) ($candidate['variant_label'] ?? '通常サイズ'));
            $kcal = (int) ($candidate['kcal'] ?? 0);
            $variantKeys[] = $base . '|' . $variant . '|' . $kcal;
        }

        return count(array_unique($variantKeys)) > 1;
    }

    /**
     * @return list<string>
     */
    public function buildVariantSearchQueries(string $baseProductName): array
    {
        $base = trim($baseProductName);
        if ($base === '') {
            return [];
        }

        return [
            $base . ' Lサイズ カロリー',
            $base . ' Mサイズ カロリー',
            $base . ' Sサイズ カロリー',
            $base . ' 内容量 カロリー',
            $base . ' 栄養成分 エネルギー kcal',
        ];
    }

    public function extractBaseProductName(string $text): string
    {
        $value = trim($text);
        $value = (string) preg_replace('/\s*[|｜].*$/u', '', $value);
        $value = (string) preg_replace('/\s*(栄養成分|カロリー|エネルギー).*$/u', '', $value);

        foreach (array_keys(self::SIZE_VARIANT_PATTERNS) as $pattern) {
            $value = (string) preg_replace('/\s*' . preg_quote($pattern, '/') . '\s*/iu', ' ', $value);
        }

        $value = (string) preg_replace('/\s*\d+(?:\.\d+)?\s*(g|ml|l|リットル|個|杯|切れ|袋|本)\s*/iu', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        return $value !== '' ? $value : trim($text);
    }

    private function assessVariantRisk(string $normalized, string $baseProductName, bool $hasExplicitVariant): string
    {
        if ($hasExplicitVariant) {
            return 'low';
        }

        foreach (self::LOW_RISK_MARKERS as $marker) {
            if (mb_strpos($normalized, mb_strtolower($marker)) !== false) {
                return 'low';
            }
        }

        $highMatches = 0;
        foreach (self::HIGH_RISK_CATEGORY_MARKERS as $marker) {
            if (mb_strpos($normalized, mb_strtolower($marker)) !== false) {
                $highMatches++;
            }
        }

        if ($highMatches >= 1) {
            return 'high';
        }

        if (mb_strlen(trim($baseProductName)) >= 4) {
            return 'medium';
        }

        return 'low';
    }

    private function extractVariantLabel(string $text): ?string
    {
        $normalized = $this->normalizeText($text);

        foreach (self::SIZE_VARIANT_PATTERNS as $pattern => $label) {
            if (mb_strpos($normalized, $pattern) !== false) {
                return $label;
            }
        }

        if (preg_match('/\b(l|m|s)\b/u', $normalized, $match) === 1) {
            return strtoupper($match[1]) . 'サイズ';
        }

        if (mb_strpos($normalized, 'ポテト l') !== false || mb_strpos($normalized, 'ポテト　l') !== false) {
            return 'Lサイズ';
        }

        return null;
    }

    private function extractPackageSize(string $text): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(ml|m l|リットル|l)\b/iu', $text, $match) === 1) {
            $unit = mb_strtolower(str_replace(' ', '', $match[2]));
            if ($unit === 'l' || $unit === 'リットル') {
                return $match[1] . 'L';
            }

            return $match[1] . 'ml';
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(g|グラム)\b/iu', $text, $match) === 1) {
            return $match[1] . 'g';
        }

        return null;
    }

    private function extractServingWeightG(string $text): ?int
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(g|グラム)\b/iu', $text, $match) === 1) {
            $weight = (int) round((float) $match[1]);

            return $weight > 0 ? $weight : null;
        }

        return null;
    }

    /**
     * Web検索候補の重複判定キー（ブランド差・表記ゆれを吸収）。
     *
     * @param array{
     *   product_name?: string,
     *   base_product_name?: string,
     *   variant_label?: string,
     *   brand?: string
     * } $candidate
     */
    public function buildCandidateDedupeKey(array $candidate): string
    {
        $productName = trim((string) ($candidate['product_name'] ?? ''));
        $brand = mb_strtolower(trim((string) ($candidate['brand'] ?? '')));
        $variantLabel = (string) ($candidate['variant_label'] ?? '通常サイズ');

        if ($brand === '' && preg_match('/^(マクドナルド|マック)\s+/u', $productName, $match) === 1) {
            $brand = mb_strtolower($match[1]);
        }

        $base = trim((string) ($candidate['base_product_name'] ?? ''));
        if ($base === '') {
            $base = $this->extractBaseProductName($productName);
        }

        $core = $this->normalizeCoreProductName($base, $brand);
        $variantKey = $this->normalizeVariantLabel($variantLabel);

        return $core . '|' . $variantKey;
    }

    private function normalizeCoreProductName(string $text, string $brand = ''): string
    {
        $value = trim($text);
        if ($brand !== '') {
            $value = (string) preg_replace('/^' . preg_quote($brand, '/') . '\s*/iu', '', $value);
        }

        foreach (['マクドナルド', 'マック', 'mcdonald', 'mcdonalds'] as $prefix) {
            $value = (string) preg_replace('/^' . preg_quote($prefix, '/') . '\s*/iu', '', $value);
        }

        $value = $this->extractBaseProductName($value);
        $value = mb_strtolower($value);
        $value = str_replace(
            ['マックフライポテト®', 'マックフライポテト', 'フライドポテト', 'ﾌﾗｲﾄﾞﾎﾟﾃﾄ'],
            'ポテト',
            $value,
        );

        foreach (['マック', 'マクドナルド', 'mcdonald', 'mcdonalds'] as $token) {
            $value = (string) preg_replace('/\b' . preg_quote($token, '/') . '\b/u', '', $value);
        }

        $value = (string) preg_replace('/[®©™()（）]/u', '', $value);
        $value = (string) preg_replace('/\b[lms]\b/iu', '', $value);
        $value = (string) preg_replace('/の$/u', '', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        return $value;
    }

    private function normalizeVariantLabel(string $label): string
    {
        $normalized = $this->normalizeText($label);

        foreach (self::SIZE_VARIANT_PATTERNS as $pattern => $canonical) {
            if ($normalized === $pattern || $normalized === mb_strtolower($canonical)) {
                return mb_strtolower($canonical);
            }
        }

        return $normalized;
    }

    private function normalizeText(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = str_replace(
            ['Ｌ', 'Ｍ', 'Ｓ', 'ｌ', 'ｍ', 'ｓ'],
            ['l', 'm', 's', 'l', 'm', 's'],
            $normalized,
        );
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return $normalized;
    }
}
