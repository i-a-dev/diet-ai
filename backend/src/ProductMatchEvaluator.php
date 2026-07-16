<?php

declare(strict_types=1);

/**
 * Brave HTML 候補の商品一致をスコア付き3段階で判定する。
 *
 * 評価軸（商品カテゴリ固定辞書は使わない）:
 * - 正規化後の一致 / 包含一致 / 文字 bigram
 * - 最長共通部分の割合 / 長さ比率
 * - 共通接頭・接尾を除いた自動コア比較
 * - URL・タイトル上のブランド根拠
 * - 単品ページか一覧ページか
 * - kcal 近傍の商品名
 */
final class ProductMatchEvaluator
{
    /** accepted 判定の総合スコア下限 */
    private const SCORE_ACCEPT_MIN = 0.78;

    /** needs_confirmation 判定の総合スコア下限 */
    private const SCORE_CONFIRM_MIN = 0.48;

    /** 単品ページで accepted に必要な名前類似度 */
    private const NAME_SIM_ACCEPT_SINGLE = 0.72;

    /** 一覧ページで accepted に必要な名前類似度（取り違え防止で厳しめ） */
    private const NAME_SIM_ACCEPT_LIST = 0.88;

    /** needs_confirmation に必要な名前類似度 */
    private const NAME_SIM_CONFIRM_MIN = 0.55;

    /** 一覧ページの needs_confirmation 下限 */
    private const NAME_SIM_CONFIRM_LIST = 0.70;

    /** ブランド根拠ありとみなすスコア */
    private const BRAND_EVIDENCE_STRONG = 0.80;

    /** 確認候補に載せる最低総合スコア（これ未満は弱すぎて除外） */
    private const CONFIRMATION_MIN_SCORE = 0.48;

    /** 確認候補の最大件数 */
    public const CONFIRMATION_CANDIDATE_LIMIT = 5;

    /** 確認候補として認める最低 kcal */
    public const MIN_CONFIRMATION_KCAL = 1;

    /** 自動コア比較で「固有部分あり」とみなす最小文字数 */
    private const AUTO_CORE_MIN_LEN = 2;

    /** accepted にするとき、双方に固有コアがある場合に必要なコア類似度 */
    private const AUTO_CORE_SIM_FOR_ACCEPT = 0.75;

    /** @var list<string> */
    private const KNOWN_BRANDS = [
        'ナッシュ',
        'nosh',
        'マクドナルド',
        'マック',
        'スターバックス',
        'スタバ',
        'セブンイレブン',
        'セブン',
        'ローソン',
        'ファミリーマート',
        'ファミマ',
        'すき家',
        '吉野家',
        '松屋',
        '山芳製菓',
        'カルビー',
        '明治',
        '森永',
        '日清',
        '味の素',
        'ニチレイ',
        '無印良品',
        'サーティワン',
        'キリン',
    ];

    public function __construct(
        private readonly OfficialSiteBrandResolver $brandResolver = new OfficialSiteBrandResolver(),
        private readonly NutritionPageExtractor $pageExtractor = new NutritionPageExtractor(),
        private readonly FoodVariantAnalyzer $variantAnalyzer = new FoodVariantAnalyzer(),
    ) {
    }

    /**
     * @param array{
     *   queryProductName: string,
     *   queryBrandName?: string|null,
     *   candidateProductName: string,
     *   evidenceText?: string|null,
     *   pageTitle?: string|null,
     *   url?: string|null,
     *   sourceType?: string|null,
     *   html?: string|null,
     * } $input
     */
    public function evaluate(array $input): ProductMatchResult
    {
        $queryRaw = trim((string) ($input['queryProductName'] ?? ''));
        $planBrand = $this->normalizeBrandToken((string) ($input['queryBrandName'] ?? ''));
        $candidateRaw = trim((string) ($input['candidateProductName'] ?? ''));
        $evidenceText = trim((string) ($input['evidenceText'] ?? ''));
        $pageTitle = trim((string) ($input['pageTitle'] ?? ''));
        $url = trim((string) ($input['url'] ?? ''));
        $sourceType = trim((string) ($input['sourceType'] ?? ''));
        $html = (string) ($input['html'] ?? '');

        if ($pageTitle === '' && $html !== '') {
            $pageTitle = $this->extractPageTitle($html);
        }

        $queryBrand = $this->resolveQueryBrand($queryRaw, $planBrand, $url, $pageTitle);
        $queryProduct = $this->extractProductForComparison($queryRaw, $queryBrand);
        $candidateProduct = $this->extractProductForComparison($candidateRaw, null);

        $pageType = $this->detectPageType($sourceType, $url, $html);
        $nameAnalysis = $this->analyzeNameSimilarity($queryProduct, $candidateProduct);
        $nameSimilarity = (float) $nameAnalysis['name_similarity'];
        $brandEvidence = $this->scoreBrandEvidence($queryBrand, $url, $pageTitle, $html);
        $officialDomain = ($url !== '' && $this->pageExtractor->isOfficialUrl($url)) ? 1.0 : 0.0;
        $nutritionEvidence = $this->scoreNutritionEvidence(
            $queryProduct,
            $candidateProduct,
            $evidenceText,
            $pageType,
        );

        $totalScore = $this->combineScore(
            $nameSimilarity,
            $brandEvidence,
            $officialDomain,
            $nutritionEvidence,
            $pageType,
        );

        [$decision, $decisionRule] = $this->decide(
            $totalScore,
            $nameSimilarity,
            (float) $nameAnalysis['core_similarity'],
            (bool) $nameAnalysis['has_distinct_cores'],
            $brandEvidence,
            $nutritionEvidence,
            $pageType,
        );

        $reasons = [
            'name_similarity' => round($nameSimilarity, 4),
            'bigram_similarity' => round((float) $nameAnalysis['bigram_similarity'], 4),
            'lcs_ratio' => round((float) $nameAnalysis['lcs_ratio'], 4),
            'length_ratio' => round((float) $nameAnalysis['length_ratio'], 4),
            'core_similarity' => round((float) $nameAnalysis['core_similarity'], 4),
            'has_distinct_cores' => (bool) $nameAnalysis['has_distinct_cores'],
            'brand_evidence' => round($brandEvidence, 4),
            'official_domain_evidence' => round($officialDomain, 4),
            'nutrition_evidence' => round($nutritionEvidence, 4),
            'page_type' => $pageType,
            'matched_field' => $this->resolveMatchedField($nameSimilarity, $brandEvidence, $nutritionEvidence),
            'total_score' => round($totalScore, 4),
            'decision_rule' => $decisionRule,
            'query_product_name' => $queryProduct,
            'query_brand_name' => $queryBrand,
            'candidate_product_name' => $candidateProduct,
            'page_title' => $pageTitle !== '' ? $pageTitle : null,
            'url' => $url !== '' ? $url : null,
            'decision' => $decision,
        ];

        return new ProductMatchResult($totalScore, $decision, $reasons);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public function finalizeConfirmationCandidates(array $candidates): array
    {
        $filtered = [];
        foreach ($candidates as $candidate) {
            $kcal = (int) ($candidate['kcal'] ?? 0);
            if ($kcal <= 0) {
                continue;
            }

            $score = (float) ($candidate['match_score'] ?? 0.0);
            if ($score < self::CONFIRMATION_MIN_SCORE) {
                continue;
            }

            $filtered[] = $candidate;
        }

        usort(
            $filtered,
            static fn (array $a, array $b): int => ((float) ($b['match_score'] ?? 0)) <=> ((float) ($a['match_score'] ?? 0)),
        );

        $deduped = [];
        $seen = [];
        foreach ($filtered as $candidate) {
            $key = mb_strtolower(trim((string) ($candidate['product_name'] ?? $candidate['productName'] ?? '')))
                . '|'
                . (string) ($candidate['kcal'] ?? '')
                . '|'
                . mb_strtolower(trim((string) ($candidate['variant_label'] ?? $candidate['variantLabel'] ?? '')));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $candidate;
            if (count($deduped) >= self::CONFIRMATION_CANDIDATE_LIMIT) {
                break;
            }
        }

        return $deduped;
    }

    /**
     * 判定ログ用配列。
     *
     * @return array<string, mixed>
     */
    public function toLogPayload(ProductMatchResult $result): array
    {
        return $result->reasons;
    }

    private function resolveQueryBrand(
        string $queryRaw,
        string $planBrand,
        string $url,
        string $pageTitle,
    ): ?string {
        if ($planBrand !== '') {
            return $planBrand;
        }

        $fromPage = $this->brandResolver->resolveFromUrl($url, $pageTitle !== '' ? $pageTitle : null);
        $fromPage = $this->normalizeBrandToken((string) $fromPage);

        $knownInQuery = $this->findKnownBrandInText($queryRaw);
        if ($knownInQuery !== null) {
            // 既知ブランドが入力にあり、かつページ側も同じブランドを示す場合のみ採用
            if ($fromPage === '' || $this->brandsMatch($knownInQuery, $fromPage)) {
                return $knownInQuery;
            }

            // ページ根拠が無くても、既知ブランドが入力に明示されていれば比較用に使う
            return $knownInQuery;
        }

        if ($fromPage !== '') {
            return $fromPage;
        }

        return null;
    }

    private function extractProductForComparison(string $raw, ?string $brand): string
    {
        $value = $this->variantAnalyzer->extractBaseProductName($raw);
        $value = $this->normalizeProductName($value);

        if ($brand !== null && $brand !== '') {
            $brandNorm = $this->normalizeProductName($brand);
            if ($brandNorm !== '' && str_starts_with($value, $brandNorm)) {
                $value = trim(mb_substr($value, mb_strlen($brandNorm)));
            } elseif ($brandNorm !== '') {
                $value = trim(str_replace($brandNorm, ' ', $value));
                $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            }
        }

        return $value !== '' ? $value : $this->normalizeProductName($raw);
    }

    private function normalizeProductName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (class_exists('Normalizer')) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = mb_strtolower($value);
        $value = str_replace(['こうじ', '糀'], '麹', $value);
        $value = str_replace(['海老', 'エビ'], 'えび', $value);
        $value = (string) preg_replace('/[（(].*?[）)]/u', ' ', $value);
        $value = (string) preg_replace('/[\x{3000}\s]+/u', ' ', $value);
        $punctuation = ['【', '】', '[', ']', '「', '」', '『', '』', '"', "'", '|', '｜', '/', '／', '・', ',', '，', '.', '。', ':', '：', ';', '；', '!', '！', '?', '？', '~', '～', '-', '‐', '‑', '‒', '–', '—'];
        $value = str_replace($punctuation, ' ', $value);
        $value = (string) preg_replace('/\s*\d+(?:\.\d+)?\s*(?:g|ml|l|kcal|キロカロリー)\b/iu', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        return $value;
    }

    private function normalizeBrandToken(string $value): string
    {
        $value = $this->normalizeProductName($value);
        if ($value === 'nosh') {
            return 'ナッシュ';
        }

        return $value;
    }

    private function findKnownBrandInText(string $text): ?string
    {
        $normalized = $this->normalizeProductName($text);
        if ($normalized === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach (self::KNOWN_BRANDS as $brand) {
            $brandNorm = $this->normalizeBrandToken($brand);
            if ($brandNorm === '') {
                continue;
            }
            if (mb_strpos($normalized, $brandNorm) !== false && mb_strlen($brandNorm) > $bestLen) {
                $best = $brandNorm === 'nosh' ? 'ナッシュ' : $brandNorm;
                $bestLen = mb_strlen($brandNorm);
            }
        }

        return $best;
    }

    private function brandsMatch(string $a, string $b): bool
    {
        $left = $this->normalizeBrandToken($a);
        $right = $this->normalizeBrandToken($b);

        return $left !== '' && $right !== '' && ($left === $right || str_contains($left, $right) || str_contains($right, $left));
    }

    /**
     * 固定カテゴリ辞書なしで商品名類似度を算出する。
     *
     * @return array{
     *   name_similarity: float,
     *   bigram_similarity: float,
     *   lcs_ratio: float,
     *   length_ratio: float,
     *   core_similarity: float,
     *   has_distinct_cores: bool
     * }
     */
    private function analyzeNameSimilarity(string $query, string $candidate): array
    {
        if ($query === '' || $candidate === '') {
            return [
                'name_similarity' => 0.0,
                'bigram_similarity' => 0.0,
                'lcs_ratio' => 0.0,
                'length_ratio' => 0.0,
                'core_similarity' => 0.0,
                'has_distinct_cores' => false,
            ];
        }

        if ($query === $candidate) {
            return [
                'name_similarity' => 1.0,
                'bigram_similarity' => 1.0,
                'lcs_ratio' => 1.0,
                'length_ratio' => 1.0,
                'core_similarity' => 1.0,
                'has_distinct_cores' => false,
            ];
        }

        $left = str_replace(' ', '', $query);
        $right = str_replace(' ', '', $candidate);
        $leftLen = mb_strlen($left);
        $rightLen = mb_strlen($right);
        $lengthRatio = ($leftLen > 0 && $rightLen > 0)
            ? (min($leftLen, $rightLen) / max($leftLen, $rightLen))
            : 0.0;

        $containment = 0.0;
        if (str_contains($left, $right) || str_contains($right, $left)) {
            $containment = $lengthRatio > 0 ? max(0.82, $lengthRatio) : 0.82;
        }

        $bigram = $this->bigramDice($left, $right);
        $lcsLen = $this->longestCommonSubstringLength($left, $right);
        $lcsRatio = ($leftLen + $rightLen) > 0
            ? (2.0 * $lcsLen) / ($leftLen + $rightLen)
            : 0.0;

        [$coreLeft, $coreRight] = $this->extractAutomaticCores($left, $right);
        $hasDistinctCores = mb_strlen($coreLeft) >= self::AUTO_CORE_MIN_LEN
            && mb_strlen($coreRight) >= self::AUTO_CORE_MIN_LEN;
        if ($coreLeft === '' && $coreRight === '') {
            $coreSimilarity = 1.0;
        } elseif ($coreLeft === '' || $coreRight === '') {
            $coreSimilarity = max($bigram, $lcsRatio);
        } elseif ($coreLeft === $coreRight) {
            $coreSimilarity = 1.0;
        } else {
            $coreSimilarity = max(
                $this->bigramDice($coreLeft, $coreRight),
                $this->longestCommonSubstringLength($coreLeft, $coreRight) > 0
                    ? (2.0 * $this->longestCommonSubstringLength($coreLeft, $coreRight))
                        / (mb_strlen($coreLeft) + mb_strlen($coreRight))
                    : 0.0,
            );
        }

        $nameSimilarity = max(
            $containment,
            (0.45 * $bigram) + (0.35 * $lcsRatio) + (0.20 * $lengthRatio),
        );

        // 共通接頭・接尾を除いたコアが近い場合は底上げ
        if ($coreSimilarity >= 0.85) {
            $nameSimilarity = max($nameSimilarity, (0.65 * $nameSimilarity) + (0.35 * $coreSimilarity));
        }

        return [
            'name_similarity' => max(0.0, min(1.0, $nameSimilarity)),
            'bigram_similarity' => $bigram,
            'lcs_ratio' => $lcsRatio,
            'length_ratio' => $lengthRatio,
            'core_similarity' => max(0.0, min(1.0, $coreSimilarity)),
            'has_distinct_cores' => $hasDistinctCores,
        ];
    }

    /**
     * 共通の接頭語・接尾語を除き、文字列から自動的にコア部分を取り出す。
     *
     * @return array{0: string, 1: string}
     */
    private function extractAutomaticCores(string $left, string $right): array
    {
        $prefixLen = $this->commonPrefixLength($left, $right);
        $suffixLen = $this->commonSuffixLength($left, $right);

        $leftLen = mb_strlen($left);
        $rightLen = mb_strlen($right);

        // 接頭+接尾が全体を食い潰さないよう調整
        if ($prefixLen + $suffixLen > $leftLen) {
            $suffixLen = max(0, $leftLen - $prefixLen);
        }
        if ($prefixLen + $suffixLen > $rightLen) {
            $suffixLen = max(0, $rightLen - $prefixLen);
        }

        $coreLeft = mb_substr($left, $prefixLen, $leftLen - $prefixLen - $suffixLen);
        $coreRight = mb_substr($right, $prefixLen, $rightLen - $prefixLen - $suffixLen);

        return [trim($coreLeft), trim($coreRight)];
    }

    private function commonPrefixLength(string $left, string $right): int
    {
        $leftChars = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rightChars = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $limit = min(count($leftChars), count($rightChars));
        $n = 0;
        for ($i = 0; $i < $limit; $i++) {
            if ($leftChars[$i] !== $rightChars[$i]) {
                break;
            }
            $n++;
        }

        return $n;
    }

    private function commonSuffixLength(string $left, string $right): int
    {
        $leftChars = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rightChars = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $limit = min(count($leftChars), count($rightChars));
        $n = 0;
        for ($i = 1; $i <= $limit; $i++) {
            if ($leftChars[count($leftChars) - $i] !== $rightChars[count($rightChars) - $i]) {
                break;
            }
            $n++;
        }

        return $n;
    }

    private function longestCommonSubstringLength(string $left, string $right): int
    {
        $leftChars = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rightChars = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($leftChars);
        $m = count($rightChars);
        if ($n === 0 || $m === 0) {
            return 0;
        }

        $best = 0;
        $prev = array_fill(0, $m + 1, 0);
        for ($i = 1; $i <= $n; $i++) {
            $curr = array_fill(0, $m + 1, 0);
            for ($j = 1; $j <= $m; $j++) {
                if ($leftChars[$i - 1] === $rightChars[$j - 1]) {
                    $curr[$j] = $prev[$j - 1] + 1;
                    if ($curr[$j] > $best) {
                        $best = $curr[$j];
                    }
                }
            }
            $prev = $curr;
        }

        return $best;
    }

    private function bigramDice(string $left, string $right): float
    {
        $left = str_replace(' ', '', $left);
        $right = str_replace(' ', '', $right);
        if ($left === '' || $right === '') {
            return 0.0;
        }

        $leftGrams = $this->characterBigrams($left);
        $rightGrams = $this->characterBigrams($right);
        if ($leftGrams === [] || $rightGrams === []) {
            return $left === $right ? 1.0 : 0.0;
        }

        $intersection = 0;
        $rightCopy = $rightGrams;
        foreach ($leftGrams as $gram) {
            $idx = array_search($gram, $rightCopy, true);
            if ($idx !== false) {
                $intersection++;
                unset($rightCopy[$idx]);
                $rightCopy = array_values($rightCopy);
            }
        }

        return (2.0 * $intersection) / (count($leftGrams) + count($rightGrams));
    }

    /**
     * @return list<string>
     */
    private function characterBigrams(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($chars) < 2) {
            return $chars;
        }

        $grams = [];
        for ($i = 0; $i < count($chars) - 1; $i++) {
            $grams[] = $chars[$i] . $chars[$i + 1];
        }

        return $grams;
    }

    private function scoreBrandEvidence(?string $queryBrand, string $url, string $pageTitle, string $html): float
    {
        if ($queryBrand === null || $queryBrand === '') {
            return 0.0;
        }

        $resolved = $this->brandResolver->resolveFromUrl($url, $pageTitle !== '' ? $pageTitle : null);
        if ($resolved !== null && $this->brandsMatch($queryBrand, $resolved)) {
            return 0.95;
        }

        $titleNorm = $this->normalizeProductName($pageTitle);
        $brandNorm = $this->normalizeBrandToken($queryBrand);
        if ($brandNorm !== '' && $titleNorm !== '' && mb_strpos($titleNorm, $brandNorm) !== false) {
            return 0.90;
        }

        if ($brandNorm === 'ナッシュ' && ($titleNorm !== '' && (str_contains($titleNorm, 'nosh') || str_contains($titleNorm, 'ナッシュ')))) {
            return 0.90;
        }

        if ($html !== '') {
            $htmlBrand = $this->pageExtractor->extractBrandFromPageHtml($html);
            if ($htmlBrand !== null && $this->brandsMatch($queryBrand, $htmlBrand)) {
                return 0.88;
            }
        }

        return 0.0;
    }

    private function detectPageType(string $sourceType, string $url, string $html): string
    {
        if ($sourceType === 'html_single_product') {
            return 'single_product';
        }

        if ($sourceType === 'html_table' || $sourceType === 'html_text') {
            return 'list_page';
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (
            str_contains($path, '/menu/detail/')
            || preg_match('#/(item|product|products|goods)/[0-9a-z_-]+#i', $path) === 1
        ) {
            return 'single_product';
        }

        if ($html !== '' && preg_match_all('/\b\d{2,4}\s*kcal\b/iu', $html, $matches) >= 1) {
            $kcalCount = count($matches[0] ?? []);
            if ($kcalCount >= 4) {
                return 'list_page';
            }
            if ($kcalCount <= 2 && (str_contains($path, '/detail') || str_contains($path, '/menu/'))) {
                return 'single_product';
            }
        }

        return 'unknown';
    }

    private function scoreNutritionEvidence(
        string $queryProduct,
        string $candidateProduct,
        string $evidenceText,
        string $pageType,
    ): float {
        $haystack = $this->normalizeProductName($evidenceText . ' ' . $candidateProduct);
        if ($haystack === '') {
            return $pageType === 'single_product' ? 0.4 : 0.0;
        }

        $target = $candidateProduct !== '' ? $candidateProduct : $queryProduct;
        if ($target !== '' && mb_strpos($haystack, $target) !== false) {
            return 1.0;
        }

        if ($queryProduct !== '' && mb_strpos($haystack, $queryProduct) !== false) {
            return 0.9;
        }

        // 一覧は kcal 近傍に商品名が無いと弱い
        return $pageType === 'list_page' ? 0.1 : 0.35;
    }

    private function combineScore(
        float $nameSimilarity,
        float $brandEvidence,
        float $officialDomain,
        float $nutritionEvidence,
        string $pageType,
    ): float {
        $score = (0.55 * $nameSimilarity)
            + (0.20 * $brandEvidence)
            + (0.10 * $officialDomain)
            + (0.15 * $nutritionEvidence);

        if ($pageType === 'list_page') {
            $score *= 0.92;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decide(
        float $totalScore,
        float $nameSimilarity,
        float $coreSimilarity,
        bool $hasDistinctCores,
        float $brandEvidence,
        float $nutritionEvidence,
        string $pageType,
    ): array {
        // 一覧で kcal 近傍に対象名が弱い場合は棄却
        if ($pageType === 'list_page' && $nutritionEvidence < 0.5) {
            return [ProductMatchResult::DECISION_REJECTED, 'list_page_weak_nutrition_context'];
        }

        if ($nameSimilarity < self::NAME_SIM_CONFIRM_MIN) {
            return [ProductMatchResult::DECISION_REJECTED, 'name_too_different'];
        }

        $acceptNameMin = $pageType === 'list_page'
            ? self::NAME_SIM_ACCEPT_LIST
            : self::NAME_SIM_ACCEPT_SINGLE;
        $confirmNameMin = $pageType === 'list_page'
            ? self::NAME_SIM_CONFIRM_LIST
            : self::NAME_SIM_CONFIRM_MIN;

        $hasBrand = $brandEvidence >= self::BRAND_EVIDENCE_STRONG;
        // 共通接頭・接尾を除いたコアが大きく違う場合は自動確定しない
        $coresBlockAccept = $hasDistinctCores && $coreSimilarity < self::AUTO_CORE_SIM_FOR_ACCEPT;

        if (
            !$coresBlockAccept
            && $nameSimilarity >= $acceptNameMin
            && $totalScore >= self::SCORE_ACCEPT_MIN
            && ($pageType !== 'list_page' || $nutritionEvidence >= 0.8)
        ) {
            if ($hasBrand || $nameSimilarity >= 0.92 || ($pageType === 'single_product' && $nameSimilarity >= 0.85)) {
                return [ProductMatchResult::DECISION_ACCEPTED, 'name_and_brand_supported'];
            }
        }

        if (
            !$coresBlockAccept
            && $pageType === 'single_product'
            && $hasBrand
            && $nameSimilarity >= self::NAME_SIM_ACCEPT_SINGLE
            && $totalScore >= self::SCORE_ACCEPT_MIN
        ) {
            return [ProductMatchResult::DECISION_ACCEPTED, 'single_product_brand_backed'];
        }

        if ($nameSimilarity >= $confirmNameMin && $totalScore >= self::SCORE_CONFIRM_MIN) {
            return [
                ProductMatchResult::DECISION_NEEDS_CONFIRMATION,
                $coresBlockAccept ? 'distinct_cores_need_confirmation' : 'ambiguous_name_similarity',
            ];
        }

        return [ProductMatchResult::DECISION_REJECTED, 'below_confirmation_threshold'];
    }

    private function resolveMatchedField(
        float $nameSimilarity,
        float $brandEvidence,
        float $nutritionEvidence,
    ): string {
        if ($nameSimilarity >= 0.85) {
            return 'product_name';
        }
        if ($brandEvidence >= self::BRAND_EVIDENCE_STRONG) {
            return 'brand_meta';
        }
        if ($nutritionEvidence >= 0.8) {
            return 'nutrition_context';
        }

        return 'combined';
    }

    private function extractPageTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $match) === 1) {
            return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }
}
