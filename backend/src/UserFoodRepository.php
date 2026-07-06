<?php

declare(strict_types=1);

/**
 * 共有食品マスタ（user_foods テーブル）の読み書きを担当する。
 * 全ユーザーが参照・更新できる。
 */
final class UserFoodRepository
{
    private const MIN_RELEVANCE_SCORE = 50;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * 食品名で自前DBを検索し、最も関連度の高い1件を返す。
     *
     * @return array{
     *   id: int,
     *   displayName: string,
     *   name: string,
     *   amount: float,
     *   unit: string,
     *   calories: int,
     *   source: string,
     *   rawInput: string|null
     * }|null
     */
    public function searchBestMatch(string $query, int $limit = 50): ?array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return null;
        }

        $tokens = $this->extractSearchTokens($trimmed);
        if ($tokens === []) {
            return null;
        }

        $safeLimit = max(1, min(100, $limit));
        $conditions = [];
        $params = [];
        foreach ($tokens as $index => $token) {
            $displayKey = ':token' . $index . '_display';
            $nameKey = ':token' . $index . '_name';
            $rawInputKey = ':token' . $index . '_raw';
            $pattern = '%' . $token . '%';
            $conditions[] = "(display_name LIKE {$displayKey} OR name LIKE {$nameKey} OR raw_input LIKE {$rawInputKey})";
            $params[$displayKey] = $pattern;
            $params[$nameKey] = $pattern;
            $params[$rawInputKey] = $pattern;
        }

        $statement = $this->db->prepare(
            'SELECT id, display_name, name, amount, unit, calories_kcal, source, raw_input
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

        $best = null;
        $bestScore = -1;

        foreach ($statement->fetchAll() as $row) {
            $displayName = (string) $row['display_name'];
            $foodName = (string) $row['name'];
            $rawInput = $row['raw_input'] === null ? '' : (string) $row['raw_input'];
            $score = max(
                $this->scoreFoodRelevance($trimmed, $displayName),
                $this->scoreFoodRelevance($trimmed, $foodName),
                $this->scoreFoodRelevance($trimmed, $rawInput),
            );
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if ($best === null || $bestScore < self::MIN_RELEVANCE_SCORE) {
            return null;
        }

        return $this->mapRow($best);
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
     *   rawInput: string|null
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

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO user_foods (
                display_name, name, amount, unit, calories_kcal, source, raw_input, created_at, updated_at
             )
             VALUES (
                :display_name, :name, :amount, :unit, :calories_kcal, :source, :raw_input, :created_at, :updated_at
             )
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                amount = VALUES(amount),
                unit = VALUES(unit),
                calories_kcal = VALUES(calories_kcal),
                source = VALUES(source),
                raw_input = VALUES(raw_input),
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
        ];
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
     *   rawInput: string|null
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
