<?php

declare(strict_types=1);

/**
 * 共有食品マスタ（user_foods テーブル）の読み書きを担当する。
 * 全ユーザーが参照・更新できる。
 */
final class UserFoodRepository
{
    private const MIN_RELEVANCE_SCORE = 50;
    private const MAX_CANDIDATES = 12;

    private PDO $db;
    private FoodVariantAnalyzer $variantAnalyzer;

    public function __construct(?PDO $db = null, ?FoodVariantAnalyzer $variantAnalyzer = null)
    {
        $this->db = $db ?? Database::connection();
        $this->variantAnalyzer = $variantAnalyzer ?? new FoodVariantAnalyzer();
    }

    /**
     * 後方互換: 即確定可能な場合のみ1件返す。
     *
     * @return array<string, mixed>|null
     */
    public function searchBestMatch(string $query, int $limit = 50): ?array
    {
        $result = $this->searchCandidates($query, $limit);
        if (($result['needsConfirmation'] ?? false) === true) {
            return null;
        }

        return $result['food'];
    }

    /**
     * 複数候補を返す local_db 検索。
     *
     * @return array{
     *   food: array<string, mixed>|null,
     *   candidates: list<array{
     *     foodId: int,
     *     name: string,
     *     calories: int,
     *     source: string,
     *     baseProductName: string,
     *     variantLabel: string,
     *     confidence: string,
     *     amount: float,
     *     unit: string,
     *     rawInput: string|null,
     *     sourceUrl: string|null,
     *     servingWeightG: float|null
     *   }>,
     *   needsConfirmation: bool,
     *   reason: string|null
     * }
     */
    public function searchCandidates(string $query, int $limit = 50): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return $this->emptySearchResult();
        }

        $inputAnalysis = $this->variantAnalyzer->analyzeInput($trimmed);
        $scoredRows = $this->fetchScoredMatches($trimmed, $limit);
        if ($scoredRows === []) {
            return $this->emptySearchResult();
        }

        $candidates = $this->buildCandidatesFromRows($scoredRows);
        $candidates = $this->expandVariantSiblings($candidates, $trimmed);
        $candidates = $this->dedupeCandidates($candidates);
        $candidates = $this->sortCandidatesForInput($candidates, $inputAnalysis);

        return $this->resolveSearchOutcome($trimmed, $inputAnalysis, $candidates);
    }

    /**
     * @return array{food: null, candidates: list<empty>, needsConfirmation: false, reason: null}
     */
    private function emptySearchResult(): array
    {
        return [
            'food' => null,
            'candidates' => [],
            'needsConfirmation' => false,
            'reason' => null,
        ];
    }

    /**
     * @return list<array{row: array<string, mixed>, score: int}>
     */
    private function fetchScoredMatches(string $query, int $limit): array
    {
        $tokens = $this->extractSearchTokens($query);
        if ($tokens === []) {
            return [];
        }

        $safeLimit = max(1, min(100, $limit));
        $conditions = [];
        $params = [];
        foreach ($tokens as $index => $token) {
            $displayKey = ':token' . $index . '_display';
            $nameKey = ':token' . $index . '_name';
            $rawInputKey = ':token' . $index . '_raw';
            $baseKey = ':token' . $index . '_base';
            $pattern = '%' . $token . '%';
            $conditions[] = "(display_name LIKE {$displayKey} OR name LIKE {$nameKey} OR raw_input LIKE {$rawInputKey} OR base_product_name LIKE {$baseKey})";
            $params[$displayKey] = $pattern;
            $params[$nameKey] = $pattern;
            $params[$rawInputKey] = $pattern;
            $params[$baseKey] = $pattern;
        }

        $statement = $this->db->prepare(
            'SELECT id, display_name, name, amount, unit, calories_kcal, source, raw_input, source_url,
                    brand_name, base_product_name, variant_label, package_size, serving_weight_g
             FROM user_foods
             WHERE ' . implode(' OR ', $conditions) . '
             ORDER BY updated_at DESC
             LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $statement->execute();

        $scored = [];
        foreach ($statement->fetchAll() as $row) {
            $score = $this->scoreRowRelevance($query, $row);
            if ($score < self::MIN_RELEVANCE_SCORE) {
                continue;
            }

            $scored[] = ['row' => $row, 'score' => $score];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, self::MAX_CANDIDATES);
    }

    /**
     * @param list<array{row: array<string, mixed>, score: int}> $scoredRows
     * @return list<array<string, mixed>>
     */
    private function buildCandidatesFromRows(array $scoredRows): array
    {
        $candidates = [];
        foreach ($scoredRows as $entry) {
            $candidates[] = $this->formatCandidate($this->mapRow($entry['row']), $entry['score']);
        }

        return $candidates;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function expandVariantSiblings(array $candidates, string $query): array
    {
        if ($candidates === []) {
            return [];
        }

        $baseNames = [];
        $excludeIds = [];
        foreach ($candidates as $candidate) {
            $excludeIds[] = (int) $candidate['foodId'];
            $base = trim((string) ($candidate['baseProductName'] ?? ''));
            if ($base !== '') {
                $baseNames[] = $base;
            }
        }

        $baseNames = array_values(array_unique($baseNames));
        if ($baseNames === []) {
            return $candidates;
        }

        $expanded = $candidates;
        foreach ($baseNames as $baseName) {
            $siblings = $this->fetchFoodsByBaseProductName($baseName, $excludeIds);
            foreach ($siblings as $row) {
                $mapped = $this->mapRow($row);
                $score = $this->scoreRowRelevance($query, $row);
                if ($score < self::MIN_RELEVANCE_SCORE) {
                    continue;
                }

                $excludeIds[] = (int) $mapped['id'];
                $expanded[] = $this->formatCandidate($mapped, $score);
            }
        }

        return $expanded;
    }

    /**
     * @param list<int> $excludeIds
     * @return list<array<string, mixed>>
     */
    private function fetchFoodsByBaseProductName(string $baseProductName, array $excludeIds): array
    {
        $excludeIds = array_values(array_unique(array_filter($excludeIds, static fn (int $id): bool => $id > 0)));
        $params = [
            'base_exact' => $baseProductName,
            'base_like' => '%' . $baseProductName . '%',
        ];

        $excludeClause = '';
        if ($excludeIds !== []) {
            $placeholders = [];
            foreach ($excludeIds as $index => $id) {
                $key = ':exclude' . $index;
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $excludeClause = ' AND id NOT IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $this->db->prepare(
            'SELECT id, display_name, name, amount, unit, calories_kcal, source, raw_input, source_url,
                    brand_name, base_product_name, variant_label, package_size, serving_weight_g
             FROM user_foods
             WHERE (
                base_product_name = :base_exact
                OR display_name LIKE :base_like
                OR name LIKE :base_like
             )' . $excludeClause . '
             ORDER BY updated_at DESC
             LIMIT :limit'
        );

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', self::MAX_CANDIDATES, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @param array<string, mixed> $food
     * @return array<string, mixed>
     */
    private function formatCandidate(array $food, int $score): array
    {
        $displayName = (string) ($food['displayName'] ?? '');
        $servingWeightG = $food['servingWeightG'] ?? null;
        $amount = (float) ($food['amount'] ?? 1);
        $weightForVariant = is_numeric($servingWeightG) && (float) $servingWeightG > 0
            ? (float) $servingWeightG
            : ($food['unit'] === 'g' ? $amount : null);

        $variant = $this->variantAnalyzer->analyzeProduct(
            $displayName,
            is_numeric($weightForVariant) ? (float) $weightForVariant : null,
        );

        $baseProductName = trim((string) ($food['baseProductName'] ?? ''));
        if ($baseProductName === '') {
            $baseProductName = $variant['base_product_name'];
        }

        $variantLabel = trim((string) ($food['variantLabel'] ?? ''));
        if ($variantLabel === '') {
            $variantLabel = $variant['variant_label'];
        }

        return [
            'foodId' => (int) $food['id'],
            'name' => $displayName,
            'calories' => (int) $food['calories'],
            'source' => 'local_db',
            'baseProductName' => $baseProductName,
            'variantLabel' => $variantLabel,
            'confidence' => 'high',
            'amount' => $amount,
            'unit' => (string) ($food['unit'] ?? '食'),
            'rawInput' => $food['rawInput'] ?? null,
            'sourceUrl' => $food['sourceUrl'] ?? null,
            'servingWeightG' => is_numeric($servingWeightG) ? (float) $servingWeightG : null,
            'packageSize' => $food['packageSize'] ?? $variant['package_size'],
            'score' => $score,
            'food' => $food,
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupeCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = mb_strtolower((string) $candidate['baseProductName'])
                . '|' . mb_strtolower((string) $candidate['variantLabel'])
                . '|' . (int) $candidate['calories']
                . '|' . (int) $candidate['foodId'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array{
     *   has_explicit_variant: bool,
     *   input_variant_label?: string|null,
     *   input_serving_weight_g?: int|null,
     *   input_package_size?: string|null
     * } $inputAnalysis
     * @return list<array<string, mixed>>
     */
    private function sortCandidatesForInput(array $candidates, array $inputAnalysis): array
    {
        usort(
            $candidates,
            function (array $a, array $b) use ($inputAnalysis): int {
                if (($inputAnalysis['has_explicit_variant'] ?? false) === true) {
                    $aMatch = $this->variantAnalyzer->variantMatchesInput($inputAnalysis, [
                        'variant_label' => $a['variantLabel'],
                        'serving_weight_g' => $a['servingWeightG'],
                        'package_size' => $a['packageSize'],
                    ]) ? 1 : 0;
                    $bMatch = $this->variantAnalyzer->variantMatchesInput($inputAnalysis, [
                        'variant_label' => $b['variantLabel'],
                        'serving_weight_g' => $b['servingWeightG'],
                        'package_size' => $b['packageSize'],
                    ]) ? 1 : 0;

                    if ($aMatch !== $bMatch) {
                        return $bMatch <=> $aMatch;
                    }
                }

                $variantOrder = ['通常サイズ' => 0, 'Mサイズ' => 1, 'Lサイズ' => 2, 'Sサイズ' => 3, 'BIG' => 4];
                $aVariant = $variantOrder[$a['variantLabel'] ?? ''] ?? 99;
                $bVariant = $variantOrder[$b['variantLabel'] ?? ''] ?? 99;
                if ($aVariant !== $bVariant) {
                    return $aVariant <=> $bVariant;
                }

                return ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
            },
        );

        return array_values(array_map(function (array $candidate): array {
            unset($candidate['score'], $candidate['food']);

            return $candidate;
        }, $candidates));
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $inputAnalysis
     * @return array{
     *   food: array<string, mixed>|null,
     *   candidates: list<array<string, mixed>>,
     *   needsConfirmation: bool,
     *   reason: string|null
     * }
     */
    private function resolveSearchOutcome(string $query, array $inputAnalysis, array $candidates): array
    {
        if ($candidates === []) {
            return $this->emptySearchResult();
        }

        $confirmCandidates = array_map(
            static fn (array $candidate): array => [
                'identity_confidence' => 'high',
                'variant_label' => $candidate['variantLabel'] ?? '通常サイズ',
                'base_product_name' => $candidate['baseProductName'] ?? '',
                'kcal' => $candidate['calories'] ?? 0,
                'serving_weight_g' => isset($candidate['servingWeightG']) && is_numeric($candidate['servingWeightG'])
                    ? (int) round((float) $candidate['servingWeightG'])
                    : null,
                'package_size' => $candidate['packageSize'] ?? null,
            ],
            $candidates,
        );

        if ($this->variantAnalyzer->canAutoConfirm($inputAnalysis, $confirmCandidates)) {
            $top = $candidates[0];

            return [
                'food' => $this->candidateToFoodSummary($top),
                'candidates' => $candidates,
                'needsConfirmation' => false,
                'reason' => null,
            ];
        }

        $reason = $this->variantAnalyzer->hasDistinctVariants($confirmCandidates)
            ? 'variant_ambiguous'
            : 'identity_ambiguous';

        if (($inputAnalysis['variant_risk'] ?? 'low') !== 'low') {
            $reason = 'variant_ambiguous';
        }

        if (
            !($inputAnalysis['has_explicit_variant'] ?? false)
            && $this->candidateHasExplicitVariant($candidates[0])
        ) {
            $reason = 'variant_ambiguous';
        }

        return [
            'food' => null,
            'candidates' => $candidates,
            'needsConfirmation' => true,
            'reason' => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function candidateToFoodSummary(array $candidate): array
    {
        return [
            'id' => (int) $candidate['foodId'],
            'displayName' => (string) $candidate['name'],
            'name' => (string) $candidate['name'],
            'amount' => (float) $candidate['amount'],
            'unit' => (string) $candidate['unit'],
            'calories' => (int) $candidate['calories'],
            'source' => (string) ($candidate['source'] ?? 'local_db'),
            'rawInput' => $candidate['rawInput'] ?? null,
            'sourceUrl' => $candidate['sourceUrl'] ?? null,
            'baseProductName' => $candidate['baseProductName'] ?? null,
            'variantLabel' => $candidate['variantLabel'] ?? null,
            'packageSize' => $candidate['packageSize'] ?? null,
            'servingWeightG' => $candidate['servingWeightG'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateHasExplicitVariant(array $candidate): bool
    {
        $variantLabel = trim((string) ($candidate['variantLabel'] ?? '通常サイズ'));

        return $variantLabel !== '' && $variantLabel !== '通常サイズ';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function scoreRowRelevance(string $query, array $row): int
    {
        $displayName = (string) ($row['display_name'] ?? '');
        $foodName = (string) ($row['name'] ?? '');
        $rawInput = $row['raw_input'] === null ? '' : (string) $row['raw_input'];
        $baseProductName = $row['base_product_name'] === null ? '' : (string) $row['base_product_name'];

        return max(
            $this->scoreFoodRelevance($query, $displayName),
            $this->scoreFoodRelevance($query, $foodName),
            $this->scoreFoodRelevance($query, $rawInput),
            $baseProductName !== '' ? $this->scoreFoodRelevance($query, $baseProductName) : 0,
        );
    }

    /**
     * @return list<string>
     */
    private function extractSearchTokens(string $query): array
    {
        $tokens = [];
        $full = trim($query);
        if ($full !== '') {
            $tokens[] = $full;
        }

        foreach (preg_split('/\s+/u', $full) ?: [] as $part) {
            $token = trim($part);
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * 食品を登録または更新する（display_name が同一なら上書き）。
     *
     * @return array{
     *   id: int,
     *   displayName: string,
     *   name: string,
     *   amount: float,
     *   unit: string,
     *   calories: int,
     *   source: string,
     *   rawInput: string|null,
     *   sourceUrl: string|null
     * }
     */
    public function upsert(
        string $displayName,
        string $name,
        float $amount,
        string $unit,
        int $caloriesKcal,
        string $source = 'ai_web_search',
        ?string $rawInput = null,
        ?string $sourceUrl = null,
        ?string $brandName = null,
        ?string $baseProductName = null,
        ?string $variantLabel = null,
        ?string $packageSize = null,
        ?float $servingWeightG = null,
    ): array {
        $display = trim($displayName);
        $foodName = trim($name);

        if ($display === '') {
            throw new InvalidArgumentException('displayName is required');
        }
        if ($foodName === '') {
            throw new InvalidArgumentException('name is required');
        }
        if ($amount <= 0 || $amount > 10000) {
            throw new InvalidArgumentException('amount must be between 0 and 10000');
        }
        if ($unit === '') {
            throw new InvalidArgumentException('unit is required');
        }
        if ($caloriesKcal <= 0 || $caloriesKcal > 5000) {
            throw new InvalidArgumentException('calories must be between 1 and 5000');
        }

        $sourceUrlValue = $this->normalizeSourceUrl($sourceUrl);

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO user_foods (
                display_name, name, amount, unit, calories_kcal, source, raw_input, source_url,
                brand_name, base_product_name, variant_label, package_size, serving_weight_g,
                created_at, updated_at
             )
             VALUES (
                :display_name, :name, :amount, :unit, :calories_kcal, :source, :raw_input, :source_url,
                :brand_name, :base_product_name, :variant_label, :package_size, :serving_weight_g,
                :created_at, :updated_at
             )
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                amount = VALUES(amount),
                unit = VALUES(unit),
                calories_kcal = VALUES(calories_kcal),
                source = VALUES(source),
                raw_input = VALUES(raw_input),
                source_url = VALUES(source_url),
                brand_name = VALUES(brand_name),
                base_product_name = VALUES(base_product_name),
                variant_label = VALUES(variant_label),
                package_size = VALUES(package_size),
                serving_weight_g = VALUES(serving_weight_g),
                updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            'display_name' => $display,
            'name' => $foodName,
            'amount' => round($amount, 2),
            'unit' => $unit,
            'calories_kcal' => $caloriesKcal,
            'source' => $source,
            'raw_input' => $rawInput,
            'source_url' => $sourceUrlValue,
            'brand_name' => $this->nullableTrim($brandName),
            'base_product_name' => $this->nullableTrim($baseProductName),
            'variant_label' => $this->nullableTrim($variantLabel),
            'package_size' => $this->nullableTrim($packageSize),
            'serving_weight_g' => $servingWeightG !== null && $servingWeightG > 0
                ? round($servingWeightG, 2)
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->db->lastInsertId();
        if ($id === 0) {
            $lookup = $this->db->prepare(
                'SELECT id FROM user_foods WHERE display_name = :display_name LIMIT 1'
            );
            $lookup->execute(['display_name' => $display]);
            $row = $lookup->fetch();
            $id = $row === false ? 0 : (int) $row['id'];
        }

        return [
            'id' => $id,
            'displayName' => $display,
            'name' => $foodName,
            'amount' => round($amount, 2),
            'unit' => $unit,
            'calories' => $caloriesKcal,
            'source' => $source,
            'rawInput' => $rawInput,
            'sourceUrl' => $sourceUrlValue,
            'brandName' => $this->nullableTrim($brandName),
            'baseProductName' => $this->nullableTrim($baseProductName),
            'variantLabel' => $this->nullableTrim($variantLabel),
            'packageSize' => $this->nullableTrim($packageSize),
            'servingWeightG' => $servingWeightG !== null && $servingWeightG > 0
                ? round($servingWeightG, 2)
                : null,
        ];
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeSourceUrl(?string $sourceUrl): ?string
    {
        $trimmed = trim((string) $sourceUrl);
        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (mb_strlen($trimmed) > 2048) {
            return mb_substr($trimmed, 0, 2048);
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id: int,
     *   displayName: string,
     *   name: string,
     *   amount: float,
     *   unit: string,
     *   calories: int,
     *   source: string,
     *   rawInput: string|null,
     *   sourceUrl: string|null
     * }
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'displayName' => (string) $row['display_name'],
            'name' => (string) $row['name'],
            'amount' => (float) $row['amount'],
            'unit' => (string) $row['unit'],
            'calories' => (int) $row['calories_kcal'],
            'source' => (string) $row['source'],
            'rawInput' => $row['raw_input'] === null ? null : (string) $row['raw_input'],
            'sourceUrl' => isset($row['source_url']) && $row['source_url'] !== null
                ? (string) $row['source_url']
                : null,
            'brandName' => isset($row['brand_name']) && $row['brand_name'] !== null
                ? (string) $row['brand_name']
                : null,
            'baseProductName' => isset($row['base_product_name']) && $row['base_product_name'] !== null
                ? (string) $row['base_product_name']
                : null,
            'variantLabel' => isset($row['variant_label']) && $row['variant_label'] !== null
                ? (string) $row['variant_label']
                : null,
            'packageSize' => isset($row['package_size']) && $row['package_size'] !== null
                ? (string) $row['package_size']
                : null,
            'servingWeightG' => isset($row['serving_weight_g']) && $row['serving_weight_g'] !== null
                ? (float) $row['serving_weight_g']
                : null,
        ];
    }

    private function scoreFoodRelevance(string $query, string $candidateName): int
    {
        $q = $this->normalizeFoodText($query);
        $name = $this->normalizeFoodText($candidateName);
        if ($q === '' || $name === '') {
            return 0;
        }
        if ($name === $q) {
            return 100;
        }

        if (str_starts_with($name, $q)) {
            $suffix = substr($name, strlen($q));
            if ($suffix === '') {
                return 100;
            }
            if (preg_match('/^[\d.]+(g|ml|個|杯|切れ|袋|本)?$/iu', $suffix) === 1) {
                return 90;
            }
            if (mb_strlen($suffix) <= 2) {
                return 75;
            }

            return 45;
        }

        if (str_starts_with($q, $name)) {
            return 85;
        }

        $qTokens = preg_split('/\s+/u', $q) ?: [];
        $nameTokens = preg_split('/\s+/u', $name) ?: [];
        if (count($qTokens) === 1) {
            $token = $qTokens[0];
            if (in_array($token, $nameTokens, true)) {
                $tokenIndex = array_search($token, $nameTokens, true);
                if (count($nameTokens) === 1) {
                    return 95;
                }
                if ($tokenIndex === 0) {
                    return 80;
                }

                return max(20, 45 - (count($nameTokens) - 2) * 5);
            }
            if (str_contains($name, $token)) {
                if (str_starts_with($name, $token)) {
                    return 45;
                }

                return 15;
            }
        }

        foreach ($qTokens as $token) {
            if ($token === '' || !str_contains($name, $token)) {
                return 0;
            }
        }

        return 50;
    }

    private function normalizeFoodText(string $text): string
    {
        $trimmed = trim(mb_strtolower($text));
        $trimmed = str_replace("\u{3000}", ' ', $trimmed);

        return preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
    }
}
