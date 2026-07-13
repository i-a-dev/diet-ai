<?php

declare(strict_types=1);

/**
 * 1ページの HTML から複数バリアントとカロリーを抽出する。
 */
final class NutritionPageVariantExtractor
{
    /** @var list<string> */
    private const SERVING_SIZE_LABELS = [
        'ミニ', '小盛', '並盛', '中盛', '大盛', '特盛', 'メガ',
    ];

    /** @var list<string> */
    private const NAMED_SIZE_LABELS = ['S', 'M', 'L', 'LL', 'SS'];

    /** @var list<string> */
    private const COFFEE_SIZE_LABELS = [
        'Short', 'Tall', 'Grande', 'Venti', 'ショート', 'トール', 'グランデ', 'ベンティ',
    ];

    /** @var list<string> 栄養成分ラベル（直後の g/ml は内容量として扱わない） */
    private const NUTRITION_COMPONENT_MARKERS = [
        '脂質',
        'たんぱく質',
        'タンパク質',
        '炭水化物',
        '食物繊維',
        '糖質',
        'ナトリウム',
        '塩分',
        'コレステロール',
        '反転脂肪',
        '飽和脂肪酸',
    ];

    /** @var list<string> 内容量として認める g/ml の前置パターン（正規表現） */
    private const CONTENT_AMOUNT_PATTERNS = [
        '/(?:内容量|規格)[:：]?\s*(\d+(?:\.\d+)?)\s*(g|ml|m l|l|リットル)\b/iu',
        '/[（(]\s*(\d+(?:\.\d+)?)\s*(g|ml)\s*[）)]/iu',
        '/(\d+(?:\.\d+)?)\s*(g|ml)\s*(?:×|x|×|\*|入|袋|個|本|パック|あたり|当たり|入り)/iu',
        '/(?:袋|個|本|パック|瓶|缶)\s*[（(]?\s*(\d+(?:\.\d+)?)\s*(g|ml)\s*[）)]?/iu',
        '/(\d+(?:\.\d+)?)\s*(g|ml)\s*(?:袋|ボトル|缶)/iu',
        '#(\d+(?:\.\d+)?)\s*(g|ml)\s*[／/]\s*\d*\s*(?:袋|個|本|パック)#iu',
    ];

    public function __construct(
        private readonly FoodVariantAnalyzer $variantAnalyzer = new FoodVariantAnalyzer(),
        private readonly NutritionPageExtractor $pageExtractor = new NutritionPageExtractor(),
    ) {
    }

    /**
     * @param list<string> $expectedLabels Claude の検索仮説ラベル（ヒント）
     * @return list<array{
     *   productName: string,
     *   baseProductName: string,
     *   variantLabel: string|null,
     *   variantDimension: string,
     *   kcal: int,
     *   sourceType: string,
     *   evidenceText: string|null,
     *   verificationConfidence: string,
     *   packageSize: string|null,
     *   servingWeightG: int|null
     * }>
     */
    public function extractFromHtml(
        string $html,
        string $productName,
        ?string $brandName,
        string $variantDimension,
        array $expectedLabels = [],
        string $url = '',
    ): array {
        if (trim($html) === '') {
            return [];
        }

        $baseProductName = $this->variantAnalyzer->extractBaseProductName($productName);
        $found = [];

        $found = array_merge($found, $this->extractFromJsonLd($html, $baseProductName, $variantDimension));
        $found = array_merge($found, $this->extractFromTables($html, $baseProductName, $variantDimension, $expectedLabels));
        $found = array_merge($found, $this->extractFromDefinitionLists($html, $baseProductName, $variantDimension, $expectedLabels));
        $found = array_merge($found, $this->extractFromText($html, $baseProductName, $variantDimension, $expectedLabels));

        if ($found === []) {
            $found = array_merge(
                $found,
                $this->extractFromSingleProductPage($html, $productName, $baseProductName, $variantDimension, $url),
            );
        }

        return $this->dedupeCandidates($found, $baseProductName, $brandName);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractFromSingleProductPage(
        string $html,
        string $productName,
        string $baseProductName,
        string $variantDimension,
        string $url,
    ): array {
        $candidate = $this->pageExtractor->extractSingleProductCandidate($html, $productName, $url);
        if ($candidate === null) {
            return [];
        }

        $context = trim(
            ($candidate['productName'] ?? '')
            . ' '
            . ($candidate['packageSize'] ?? '')
            . ' '
            . ($candidate['evidenceText'] ?? ''),
        );
        if (!$this->rowMatchesProduct($context, $baseProductName)) {
            return [];
        }

        $packageSize = $candidate['packageSize'] ?? null;
        $variantLabel = $packageSize ?? '通常サイズ';

        return [
            $this->makeCandidate(
                (string) $candidate['productName'],
                (string) $candidate['productName'],
                $variantLabel,
                $variantDimension,
                (int) $candidate['kcal'],
                'html_single_product',
                $candidate['evidenceText'] ?? null,
                'high',
                $packageSize,
                isset($candidate['brandName']) && is_string($candidate['brandName'])
                    ? $candidate['brandName']
                    : null,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractFromJsonLd(string $html, string $baseProductName, string $variantDimension): array
    {
        $found = [];

        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/isu', $html, $matches) < 1) {
            return [];
        }

        foreach ($matches[1] as $jsonText) {
            $decoded = json_decode(html_entity_decode(strip_tags((string) $jsonText), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($decoded)) {
                continue;
            }

            $nodes = isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded];
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $kcal = $this->extractKcalFromStructuredNode($node);
                if ($kcal === null) {
                    continue;
                }

                $label = $this->extractLabelFromStructuredNode($node);
                $found[] = $this->makeCandidate(
                    $baseProductName,
                    $baseProductName,
                    $label,
                    $variantDimension,
                    $kcal,
                    'html_table',
                    (string) ($node['name'] ?? ''),
                    'medium',
                );
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function extractKcalFromStructuredNode(array $node): ?int
    {
        foreach (['calories', 'energy', 'energy_kcal', 'kcal'] as $key) {
            if (!isset($node[$key])) {
                continue;
            }

            $kcal = $this->normalizeKcalValue($node[$key]);
            if ($kcal !== null) {
                return $kcal;
            }
        }

        if (isset($node['nutrition']) && is_array($node['nutrition'])) {
            return $this->extractKcalFromStructuredNode($node['nutrition']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function extractLabelFromStructuredNode(array $node): ?string
    {
        $name = trim((string) ($node['name'] ?? $node['sku'] ?? $node['size'] ?? ''));

        return $this->normalizeVariantLabelFromText($name);
    }

    /**
     * @param list<string> $expectedLabels
     * @return list<array<string, mixed>>
     */
    private function extractFromTables(
        string $html,
        string $baseProductName,
        string $variantDimension,
        array $expectedLabels,
    ): array {
        $found = [];

        if (preg_match_all('/<table[^>]*>(.*?)<\/table>/isu', $html, $tables) < 1) {
            return [];
        }

        foreach ($tables[1] as $tableHtml) {
            if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/isu', (string) $tableHtml, $rows) < 1) {
                continue;
            }

            foreach ($rows[1] as $rowHtml) {
                $text = $this->tableRowToText((string) $rowHtml);
                if ($text === '' || !preg_match('/(\d{2,4})\s*kcal/iu', $text, $kcalMatch)) {
                    continue;
                }

                $kcal = (int) $kcalMatch[1];
                if (!$this->isPlausibleKcal($kcal)) {
                    continue;
                }

                $label = $this->detectVariantLabel($text, $variantDimension, $expectedLabels);
                $packageSize = $this->detectPackageSize($text);

                if ($label === null && $packageSize !== null) {
                    $label = $packageSize;
                }

                if ($label === null) {
                    continue;
                }

                if (!$this->rowMatchesProduct($text, $baseProductName)) {
                    continue;
                }

                $rowProductName = $this->extractProductNameFromRow($text, $baseProductName);

                $found[] = $this->makeCandidate(
                    $rowProductName,
                    $this->variantAnalyzer->extractBaseProductName($rowProductName),
                    $label ?? $packageSize,
                    $variantDimension,
                    $kcal,
                    'html_table',
                    mb_substr($text, 0, 120),
                    $label !== null ? 'high' : 'medium',
                    $packageSize,
                );
            }
        }

        return $found;
    }

    /**
     * @param list<string> $expectedLabels
     * @return list<array<string, mixed>>
     */
    private function extractFromDefinitionLists(
        string $html,
        string $baseProductName,
        string $variantDimension,
        array $expectedLabels,
    ): array {
        $found = [];

        if (preg_match_all('/<(dt|dd)[^>]*>(.*?)<\/\1>/isu', $html, $parts, PREG_SET_ORDER) < 1) {
            return [];
        }

        $buffer = '';
        foreach ($parts as $part) {
            $buffer .= ' ' . $this->normalizeWhitespace(strip_tags($part[2] ?? ''));
            if (!preg_match('/(\d{2,4})\s*kcal/iu', $buffer, $kcalMatch)) {
                continue;
            }

            $kcal = (int) $kcalMatch[1];
            if (!$this->isPlausibleKcal($kcal)) {
                continue;
            }

            $label = $this->detectVariantLabel($buffer, $variantDimension, $expectedLabels);
            if ($label === null || !$this->rowMatchesProduct($buffer, $baseProductName)) {
                $buffer = '';
                continue;
            }

            $found[] = $this->makeCandidate(
                $baseProductName,
                $baseProductName,
                $label,
                $variantDimension,
                $kcal,
                'html_table',
                mb_substr(trim($buffer), 0, 120),
                'medium',
            );
            $buffer = '';
        }

        return $found;
    }

    /**
     * @param list<string> $expectedLabels
     * @return list<array<string, mixed>>
     */
    private function extractFromText(
        string $html,
        string $baseProductName,
        string $variantDimension,
        array $expectedLabels,
    ): array {
        $text = $this->normalizeWhitespace(strip_tags($html));
        $found = [];

        $patterns = [
            '/(?:^|[^a-zA-Z])([SML]|LL|SS)\s*サイズ(?:なら|は|が|（|\()[^0-9]{0,20}(\d{2,4})\s*kcal/iu',
            '/(?:^|[^a-zA-Z])([SML]|LL|SS)\s*サイズ[^0-9]{0,40}(\d{2,4})\s*kcal/iu',
            '/(ミニ|小盛|並盛|中盛|大盛|特盛|メガ)(?:サイズ|盛)?(?:なら|は|が|（|\()[^0-9]{0,20}(\d{2,4})\s*kcal/iu',
            '/(ミニ|小盛|並盛|中盛|大盛|特盛|メガ)(?:サイズ|盛)?[^0-9]{0,30}(\d{2,4})\s*kcal/iu',
            '/(Short|Tall|Grande|Venti|ショート|トール|グランデ|ベンティ)[^0-9]{0,30}(\d{2,4})\s*kcal/iu',
            '/(BIG|ビッグ|通常|ミニ|レギュラー)[^0-9]{0,30}(\d{2,4})\s*kcal/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($matches as $match) {
                $labelRaw = (string) ($match[1][0] ?? '');
                $kcalRaw = (string) ($match[2][0] ?? ($match[3][0] ?? ''));
                $offset = (int) ($match[0][1] ?? 0);
                $kcal = (int) $kcalRaw;

                if (!$this->isPlausibleKcal($kcal)) {
                    continue;
                }

                $context = substr($text, max(0, $offset - 120), 240);
                if (!$this->rowMatchesProduct($context, $baseProductName)) {
                    continue;
                }

                $label = $this->normalizeVariantLabelFromText($labelRaw);
                $packageSize = $this->detectContentAmount($context);
                if ($label === null && $packageSize !== null) {
                    $label = $packageSize;
                }

                if ($label === null) {
                    $label = $this->detectVariantLabel($context, $variantDimension, $expectedLabels);
                }

                if ($label === null) {
                    continue;
                }

                $rowProductName = $this->extractProductNameFromRow($context, $baseProductName);

                $found[] = $this->makeCandidate(
                    $rowProductName,
                    $this->variantAnalyzer->extractBaseProductName($rowProductName),
                    $label,
                    $variantDimension,
                    $kcal,
                    'html_text',
                    mb_substr($context, 0, 120),
                    'medium',
                    $packageSize,
                );
            }
        }

        return $found;
    }

    /**
     * @param list<string> $expectedLabels
     */
    private function detectVariantLabel(string $text, string $variantDimension, array $expectedLabels): ?string
    {
        foreach ($expectedLabels as $label) {
            if ($label !== '' && mb_strpos($text, $label) !== false) {
                return $this->normalizeVariantLabelFromText($label) ?? $label;
            }
        }

        foreach (self::SERVING_SIZE_LABELS as $label) {
            if (mb_strpos($text, $label) !== false) {
                return $label;
            }
        }

        foreach (self::COFFEE_SIZE_LABELS as $label) {
            if (mb_stripos($text, $label) !== false) {
                return $label;
            }
        }

        if (preg_match('/\b(S|M|L|LL|SS)\b/u', $text, $match) === 1) {
            return strtoupper($match[1]) . 'サイズ';
        }

        $contentAmount = $this->detectContentAmount($text);
        if ($contentAmount !== null) {
            return $contentAmount;
        }

        foreach (['BIG', 'ビッグ', '通常', 'ミニ', 'レギュラー'] as $label) {
            if (mb_stripos($text, $label) !== false) {
                return $label === 'ビッグ' ? 'BIG' : $label;
            }
        }

        return null;
    }

    private function detectPackageSize(string $text): ?string
    {
        return $this->detectContentAmount($text);
    }

    private function detectContentAmount(string $text): ?string
    {
        foreach (self::CONTENT_AMOUNT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $amount = (string) ($match[1][0] ?? '');
            $unit = (string) ($match[2][0] ?? '');
            $offset = (int) ($match[0][1] ?? 0);

            if ($amount === '' || $unit === '') {
                continue;
            }

            if ($this->isNutritionComponentContext($text, $offset)) {
                continue;
            }

            $normalized = $this->normalizePackageSize($amount, $unit);
            if ($this->isPlausibleContentAmount($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private function isNutritionComponentContext(string $text, int $byteOffset): bool
    {
        $contextBefore = substr($text, max(0, $byteOffset - 40), min(40, $byteOffset));

        foreach (self::NUTRITION_COMPONENT_MARKERS as $marker) {
            if (mb_strpos($contextBefore, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isPlausibleContentAmount(string $amount): bool
    {
        if (preg_match('/^(\d+(?:\.\d+)?)g$/iu', $amount, $match) === 1) {
            return (float) $match[1] >= 10;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)ml$/iu', $amount, $match) === 1) {
            return (float) $match[1] >= 30;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)L$/iu', $amount, $match) === 1) {
            return (float) $match[1] >= 0.1;
        }

        return false;
    }

    private function extractProductNameFromRow(string $text, string $fallback): string
    {
        $value = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        if (preg_match('/^(.+?)(?:\s+\d+(?:袋|個|本|パック|杯)|内容量)/u', $value, $match) === 1) {
            $value = trim($match[1]);
        }

        $value = (string) preg_replace('/\s*[-–—|｜]\s*カロリー.*$/iu', '', $value);
        $value = (string) preg_replace('/\s*\d{2,4}\s*kcal.*$/iu', '', $value);
        $value = (string) preg_replace('/\s*[|｜]\s*脂質.*$/iu', '', $value);
        $value = (string) preg_replace('/\s*\([^)]*(?:製菓|食品|株式会社)[^)]*\)/u', '', $value);
        $value = (string) preg_replace('/\s*\([^)]*\)\s*1(?:袋|個|本)/u', '', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '' || mb_strlen($value) < 2) {
            return $fallback;
        }

        return $value;
    }

    private function normalizePackageSize(string $amount, string $unit): string
    {
        $unit = mb_strtolower(str_replace(' ', '', $unit));
        if ($unit === 'l' || $unit === 'リットル') {
            return $amount . 'L';
        }

        return $amount . ($unit === 'ml' || $unit === 'm l' ? 'ml' : 'g');
    }

    private function normalizeVariantLabelFromText(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(S|M|L|LL|SS)$/iu', $trimmed, $match) === 1) {
            return strtoupper($match[1]) . 'サイズ';
        }

        foreach (self::SERVING_SIZE_LABELS as $label) {
            if ($trimmed === $label || str_contains($trimmed, $label)) {
                return $label;
            }
        }

        foreach (self::COFFEE_SIZE_LABELS as $label) {
            if (strcasecmp($trimmed, $label) === 0) {
                return $label;
            }
        }

        if (in_array(mb_strtoupper($trimmed), ['BIG'], true)) {
            return 'BIG';
        }

        return $trimmed;
    }

    private function rowMatchesProduct(string $text, string $baseProductName): bool
    {
        $normalizedText = mb_strtolower($text);
        $core = mb_strtolower($this->variantAnalyzer->extractBaseProductName($baseProductName));

        if ($core === '') {
            return true;
        }

        $tokens = preg_split('/\s+/u', $core) ?: [];
        $matched = 0;
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 2 && mb_strpos($normalizedText, $token) !== false) {
                $matched++;
            }
        }

        if ($matched >= min(2, count($tokens))) {
            return true;
        }

        $partialTokens = $this->extractProductPartialTokens($core);
        if ($partialTokens !== []) {
            foreach ($partialTokens as $token) {
                if (mb_strpos($normalizedText, $token) === false) {
                    return false;
                }
            }

            return true;
        }

        $productMarkers = ['ポテト', '牛丼', 'カレー', 'ハンバーグ', 'ラーメン', 'うどん', 'コーラ', 'お茶', 'ビーフ', 'わさ'];
        foreach ($productMarkers as $marker) {
            if (mb_strpos($core, $marker) !== false && mb_strpos($normalizedText, $marker) !== false) {
                return true;
            }
        }

        return count($tokens) <= 1;
    }

    /**
     * @return list<string>
     */
    private function extractProductPartialTokens(string $productName): array
    {
        $tokens = [];
        foreach ([
            'ハンバーグ',
            'チーズ',
            'トマト',
            'きのこ',
            'デミ',
            'チリ',
            'おろし',
            '和風',
            'カレー',
            'チキン',
            'ビーフ',
            'ポーク',
            'ソース',
            'ステーキ',
            '唐揚げ',
            'からあげ',
            'ポテト',
            'ラーメン',
            'うどん',
            'パスタ',
            'バーガー',
        ] as $token) {
            $token = mb_strtolower($token);
            if (mb_strpos($productName, $token) !== false && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return count($tokens) >= 2 ? $tokens : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeCandidate(
        string $productName,
        string $baseProductName,
        ?string $variantLabel,
        string $variantDimension,
        int $kcal,
        string $sourceType,
        ?string $evidenceText,
        string $verificationConfidence,
        ?string $packageSize = null,
        ?string $brandName = null,
    ): array {
        $variant = $this->variantAnalyzer->analyzeProduct(
            trim($productName . ' ' . ($variantLabel ?? '')),
        );

        return [
            'productName' => $productName,
            'baseProductName' => $baseProductName !== '' ? $baseProductName : $variant['base_product_name'],
            'brandName' => $brandName,
            'variantLabel' => $variantLabel ?? $variant['variant_label'],
            'variantDimension' => $variantDimension,
            'kcal' => $kcal,
            'sourceType' => $sourceType,
            'evidenceText' => $evidenceText,
            'verificationConfidence' => $verificationConfidence,
            'packageSize' => $packageSize ?? $variant['package_size'],
            'servingWeightG' => $variant['serving_weight_g'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupeCandidates(array $candidates, string $baseProductName, ?string $brandName): array
    {
        $byKey = [];

        foreach ($candidates as $candidate) {
            $productKey = mb_strtolower(trim((string) ($candidate['productName'] ?? '')));
            $mapped = [
                'product_name' => $candidate['productName'],
                'base_product_name' => $candidate['baseProductName'],
                'variant_label' => $candidate['variantLabel'],
                'brand' => $candidate['brandName'] ?? $brandName,
                'kcal' => $candidate['kcal'],
            ];
            $key = $productKey . '|' . $this->variantAnalyzer->buildCandidateDedupeKey($mapped) . '|' . $candidate['kcal'];

            if (!isset($byKey[$key])) {
                $byKey[$key] = $candidate;
                continue;
            }

            $currentScore = $this->verificationScore($byKey[$key]);
            $incomingScore = $this->verificationScore($candidate);
            if ($incomingScore > $currentScore) {
                $byKey[$key] = $candidate;
            }
        }

        return array_values($byKey);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function verificationScore(array $candidate): int
    {
        return match ($candidate['verificationConfidence'] ?? 'low') {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }

    private function normalizeKcalValue(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $kcal = (int) round((float) $value);

            return $this->isPlausibleKcal($kcal) ? $kcal : null;
        }

        if (is_string($value) && preg_match('/(\d{2,4})/', $value, $match) === 1) {
            $kcal = (int) $match[1];

            return $this->isPlausibleKcal($kcal) ? $kcal : null;
        }

        return null;
    }

    private function isPlausibleKcal(int $kcal): bool
    {
        return $kcal >= 10 && $kcal <= 5000;
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function tableRowToText(string $rowHtml): string
    {
        if (preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/isu', $rowHtml, $cells) >= 1) {
            $parts = array_map(
                fn (string $cell): string => $this->normalizeWhitespace(strip_tags($cell)),
                $cells[1],
            );

            return $this->normalizeWhitespace(implode(' ', array_filter($parts)));
        }

        return $this->normalizeWhitespace(strip_tags($rowHtml));
    }
}
