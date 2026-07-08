<?php

declare(strict_types=1);

/**
 * food_search_aliases テーブルの読み書きを担当する。
 */
final class FoodSearchAliasRepository
{
    private const DOMINANT_SELECTION_THRESHOLD = 5;
    private const DOMINANT_RATIO = 3.0;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * 正規化クエリでエイリアスを検索し、紐づく user_foods を返す。
     *
     * @return list<array{
     *   aliasId: int,
     *   selectionCount: int,
     *   rejectedCount: int,
     *   confidenceScore: float,
     *   lastSelectedAt: string|null,
     *   source: string,
     *   food: array{
     *     id: int,
     *     displayName: string,
     *     name: string,
     *     amount: float,
     *     unit: string,
     *     calories: int,
     *     source: string,
     *     rawInput: string|null,
     *     sourceUrl: string|null
     *   }
     * }>
     */
    public function searchByQuery(string $query, ?int $userId = null, int $limit = 10): array
    {
        $normalized = FoodSearchNormalizer::normalize($query);
        if ($normalized === '') {
            return [];
        }

        $safeLimit = max(1, min(20, $limit));
        $params = [
            'query_exact' => $normalized,
            'limit' => $safeLimit,
        ];

        $userClause = '';
        if ($userId !== null) {
            $userClause = 'AND (a.user_id IS NULL OR a.user_id = :user_id)';
            $params['user_id'] = $userId;
        }

        $statement = $this->db->prepare(
            'SELECT
                a.id,
                a.query_normalized,
                a.selection_count,
                a.rejected_count,
                a.confidence_score,
                a.last_selected_at,
                a.source,
                f.id AS food_id,
                f.display_name,
                f.name,
                f.amount,
                f.unit,
                f.calories_kcal,
                f.source AS food_source,
                f.raw_input,
                f.source_url,
                f.brand_name,
                f.base_product_name,
                f.variant_label,
                f.package_size,
                f.serving_weight_g
             FROM food_search_aliases a
             INNER JOIN user_foods f ON f.id = a.food_id
             WHERE a.query_normalized = :query_exact
             ' . $userClause . '
             ORDER BY a.selection_count DESC, a.last_selected_at DESC
             LIMIT :limit'
        );

        foreach ($params as $key => $value) {
            $type = $key === 'limit' || $key === 'user_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $statement->bindValue(':' . $key, $value, $type);
        }
        $statement->execute();

        $rows = $statement->fetchAll();
        if ($rows === []) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = $this->mapCandidateRow($row);
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => self::compareCandidateRank($a, $b, $normalized)
        );

        return array_values($candidates);
    }

    /**
     * @return array{
     *   alias: array{
     *     id: int,
     *     queryNormalized: string,
     *     rawQuerySample: string,
     *     foodId: int,
     *     selectionCount: int,
     *     rejectedCount: int,
     *     confidenceScore: float,
     *     source: string,
     *     lastSelectedAt: string|null
     *   },
     *   created: bool
     * }
     */
    public function upsert(
        string $rawQuery,
        int $foodId,
        string $source = 'user_selected',
        ?int $userId = null,
    ): array {
        $raw = trim($rawQuery);
        if ($raw === '') {
            throw new InvalidArgumentException('rawQuery is required');
        }
        if ($foodId <= 0) {
            throw new InvalidArgumentException('foodId is required');
        }

        $normalized = FoodSearchNormalizer::normalize($raw);
        if ($normalized === '') {
            throw new InvalidArgumentException('query could not be normalized');
        }

        $foodLookup = $this->db->prepare('SELECT id, display_name, name FROM user_foods WHERE id = :id LIMIT 1');
        $foodLookup->execute(['id' => $foodId]);
        $foodRow = $foodLookup->fetch();
        if ($foodRow === false) {
            throw new InvalidArgumentException('food not found');
        }

        $foodName = (string) $foodRow['display_name'];
        if (!FoodSearchNormalizer::shouldSaveAlias($raw, $foodName, $source)) {
            throw new InvalidArgumentException('alias save is not eligible for this query');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $existing = $this->db->prepare(
            'SELECT id, selection_count, rejected_count
             FROM food_search_aliases
             WHERE query_normalized = :query_normalized AND food_id = :food_id
             LIMIT 1'
        );
        $existing->execute([
            'query_normalized' => $normalized,
            'food_id' => $foodId,
        ]);
        $existingRow = $existing->fetch();

        if ($existingRow !== false) {
            $selectionCount = (int) $existingRow['selection_count'] + 1;
            $rejectedCount = (int) $existingRow['rejected_count'];
            $confidence = FoodSearchNormalizer::computeConfidenceScore($selectionCount, $rejectedCount);

            $update = $this->db->prepare(
                'UPDATE food_search_aliases
                 SET selection_count = :selection_count,
                     confidence_score = :confidence_score,
                     raw_query_sample = :raw_query_sample,
                     source = :source,
                     last_selected_at = :last_selected_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'selection_count' => $selectionCount,
                'confidence_score' => $confidence,
                'raw_query_sample' => $raw,
                'source' => $source,
                'last_selected_at' => $now,
                'updated_at' => $now,
                'id' => (int) $existingRow['id'],
            ]);

            return [
                'alias' => $this->findById((int) $existingRow['id']),
                'created' => false,
            ];
        }

        $confidence = FoodSearchNormalizer::computeConfidenceScore(1, 0);
        $insert = $this->db->prepare(
            'INSERT INTO food_search_aliases (
                query_normalized,
                raw_query_sample,
                food_id,
                user_id,
                selection_count,
                rejected_count,
                confidence_score,
                source,
                last_selected_at,
                created_at,
                updated_at
             ) VALUES (
                :query_normalized,
                :raw_query_sample,
                :food_id,
                :user_id,
                1,
                0,
                :confidence_score,
                :source,
                :last_selected_at,
                :created_at,
                :updated_at
             )'
        );
        $insert->execute([
            'query_normalized' => $normalized,
            'raw_query_sample' => $raw,
            'food_id' => $foodId,
            'user_id' => $userId,
            'confidence_score' => $confidence,
            'source' => $source,
            'last_selected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->db->lastInsertId();

        return [
            'alias' => $this->findById($id),
            'created' => true,
        ];
    }

    /**
     * @return array{
     *   id: int,
     *   queryNormalized: string,
     *   rawQuerySample: string,
     *   foodId: int,
     *   selectionCount: int,
     *   rejectedCount: int,
     *   confidenceScore: float,
     *   source: string,
     *   lastSelectedAt: string|null
     * }
     */
    public function incrementSelection(int $aliasId): array
    {
        if ($aliasId <= 0) {
            throw new InvalidArgumentException('alias id is required');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $lookup = $this->db->prepare(
            'SELECT id, selection_count, rejected_count
             FROM food_search_aliases
             WHERE id = :id
             LIMIT 1'
        );
        $lookup->execute(['id' => $aliasId]);
        $row = $lookup->fetch();
        if ($row === false) {
            throw new InvalidArgumentException('alias not found');
        }

        $selectionCount = (int) $row['selection_count'] + 1;
        $rejectedCount = (int) $row['rejected_count'];
        $confidence = FoodSearchNormalizer::computeConfidenceScore($selectionCount, $rejectedCount);

        $update = $this->db->prepare(
            'UPDATE food_search_aliases
             SET selection_count = :selection_count,
                 confidence_score = :confidence_score,
                 last_selected_at = :last_selected_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'selection_count' => $selectionCount,
            'confidence_score' => $confidence,
            'last_selected_at' => $now,
            'updated_at' => $now,
            'id' => $aliasId,
        ]);

        return $this->findById($aliasId);
    }

    /**
     * 単一候補を自動確定してよいか判定する。
     *
     * @param list<array{selectionCount: int}> $candidates
     */
    public function shouldAutoConfirm(array $candidates, string $rawQuery): bool
    {
        if (count($candidates) !== 1) {
            return false;
        }

        $inputAnalysis = (new FoodVariantAnalyzer())->analyzeInput($rawQuery);
        if (($inputAnalysis['variant_risk'] ?? 'low') !== 'low') {
            return false;
        }

        if (FoodSearchNormalizer::isAmbiguousQuery($rawQuery)) {
            return false;
        }

        $top = $candidates[0];
        $topCount = (int) $top['selectionCount'];

        return $topCount >= self::DOMINANT_SELECTION_THRESHOLD;
    }

    /**
     * @param list<array{selectionCount: int}> $candidates
     */
    public function needsConfirmation(array $candidates, string $rawQuery): bool
    {
        if ($candidates === []) {
            return false;
        }

        if (count($candidates) > 1) {
            return true;
        }

        $inputAnalysis = (new FoodVariantAnalyzer())->analyzeInput($rawQuery);
        if (($inputAnalysis['variant_risk'] ?? 'low') !== 'low' && !($inputAnalysis['has_explicit_variant'] ?? false)) {
            return true;
        }

        if (FoodSearchNormalizer::isAmbiguousQuery($rawQuery)) {
            return true;
        }

        $topCount = (int) $candidates[0]['selectionCount'];
        if ($topCount < self::DOMINANT_SELECTION_THRESHOLD) {
            return true;
        }

        return false;
    }

    /**
     * @return array{
     *   id: int,
     *   queryNormalized: string,
     *   rawQuerySample: string,
     *   foodId: int,
     *   selectionCount: int,
     *   rejectedCount: int,
     *   confidenceScore: float,
     *   source: string,
     *   lastSelectedAt: string|null
     * }
     */
    private function findById(int $id): array
    {
        $statement = $this->db->prepare(
            'SELECT id, query_normalized, raw_query_sample, food_id, selection_count, rejected_count,
                    confidence_score, source, last_selected_at
             FROM food_search_aliases
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new InvalidArgumentException('alias not found');
        }

        return [
            'id' => (int) $row['id'],
            'queryNormalized' => (string) $row['query_normalized'],
            'rawQuerySample' => (string) $row['raw_query_sample'],
            'foodId' => (int) $row['food_id'],
            'selectionCount' => (int) $row['selection_count'],
            'rejectedCount' => (int) $row['rejected_count'],
            'confidenceScore' => (float) $row['confidence_score'],
            'source' => (string) $row['source'],
            'lastSelectedAt' => $row['last_selected_at'] === null
                ? null
                : (string) $row['last_selected_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   aliasId: int,
     *   selectionCount: int,
     *   rejectedCount: int,
     *   confidenceScore: float,
     *   lastSelectedAt: string|null,
     *   source: string,
     *   food: array{
     *     id: int,
     *     displayName: string,
     *     name: string,
     *     amount: float,
     *     unit: string,
     *     calories: int,
     *     source: string,
     *     rawInput: string|null,
     *     sourceUrl: string|null
     *   }
     * }
     */
    private function mapCandidateRow(array $row): array
    {
        return [
            'aliasId' => (int) $row['id'],
            'selectionCount' => (int) $row['selection_count'],
            'rejectedCount' => (int) $row['rejected_count'],
            'confidenceScore' => (float) $row['confidence_score'],
            'lastSelectedAt' => $row['last_selected_at'] === null
                ? null
                : (string) $row['last_selected_at'],
            'source' => (string) $row['source'],
            'food' => [
                'id' => (int) $row['food_id'],
                'displayName' => (string) $row['display_name'],
                'name' => (string) $row['name'],
                'amount' => (float) $row['amount'],
                'unit' => (string) $row['unit'],
                'calories' => (int) $row['calories_kcal'],
                'source' => (string) $row['food_source'],
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
            ],
        ];
    }

    /**
     * @param array{selectionCount: int, rejectedCount: int, lastSelectedAt: string|null, food: array{displayName: string}} $a
     * @param array{selectionCount: int, rejectedCount: int, lastSelectedAt: string|null, food: array{displayName: string}} $b
     */
    private static function compareCandidateRank(array $a, array $b, string $normalizedQuery): int
    {
        $scoreA = self::rankScore($a, $normalizedQuery);
        $scoreB = self::rankScore($b, $normalizedQuery);

        return $scoreB <=> $scoreA;
    }

    /**
     * @param array{selectionCount: int, rejectedCount: int, lastSelectedAt: string|null} $candidate
     */
    private static function rankScore(array $candidate, string $normalizedQuery): float
    {
        $score = (float) $candidate['selectionCount'] * 10.0;
        $score -= (float) $candidate['rejectedCount'] * 5.0;

        if ($candidate['lastSelectedAt'] !== null) {
            $lastSelected = new DateTimeImmutable($candidate['lastSelectedAt']);
            $days = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->diff($lastSelected)->days;
            if ($days !== false && $days <= 7) {
                $score += 5.0;
            }
        }

        return $score;
    }
}
