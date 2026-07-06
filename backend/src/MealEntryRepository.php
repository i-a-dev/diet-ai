<?php

declare(strict_types=1);

/**
 * 食事データ（meal_entries テーブル）の読み書きを担当するクラス。
 */
final class MealEntryRepository
{
    /** @var array<string, string> meal_type と表示名の対応 */
    private const MEAL_LABELS = [
        'breakfast' => '朝ごはん',
        'lunch' => '昼ごはん',
        'dinner' => '夜ごはん',
        'snack' => '間食・おやつ',
    ];

    /** @var list<string> */
    private const ALLOWED_CALORIE_SOURCES = [
        'regex',
        'fatsecret',
        'open_food_facts',
        'local_db',
        'claude_estimate',
        'ai_web_search',
        'user_registered',
    ];

    /** @var list<string> */
    private const ALLOWED_CONFIDENCE_LEVELS = [
        'high',
        'medium',
        'low',
    ];

    private PDO $db;
    private int $userId;

    public function __construct(int $userId, ?PDO $db = null)
    {
        $this->userId = $userId;
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<int, array{id: string, name: string, calories: int, items: array<int, array{id: int, label: string, calories: int}>}>
     */
    public function getSectionsForDate(string $date): array
    {
        /** @var array<string, array{id: string, name: string, calories: int, items: array<int, array{id: int, label: string, calories: int}>}> $sections */
        $sections = [];
        foreach (self::MEAL_LABELS as $mealType => $label) {
            $sections[$mealType] = [
                'id' => $mealType,
                'name' => $label,
                'calories' => 0,
                'items' => [],
            ];
        }

        $statement = $this->db->prepare(
            'SELECT id, meal_type, food_name, calories_kcal, calories_edited, calorie_source, source_url, confidence
             FROM meal_entries
             WHERE user_id = :user_id AND recorded_on = :recorded_on
             ORDER BY id ASC'
        );
        $statement->execute(['user_id' => $this->userId, 'recorded_on' => $date]);

        foreach ($statement->fetchAll() as $row) {
            $mealType = (string) $row['meal_type'];
            if (!isset($sections[$mealType])) {
                continue;
            }

            $calories = (int) $row['calories_kcal'];
            $sections[$mealType]['calories'] += $calories;
            $sections[$mealType]['items'][] = $this->mapMealItemRow($row);
        }

        return array_values($sections);
    }

    /**
     * @return array{id: int, mealType: string, label: string, calories: int, caloriesEdited: bool, calorieSource: string|null, sourceUrl: string|null, confidence: string|null}
     */
    public function addEntry(
        string $date,
        string $mealType,
        string $foodName,
        int $caloriesKcal,
        bool $caloriesEdited = false,
        ?string $calorieSource = null,
        ?string $sourceUrl = null,
        ?string $confidence = null,
    ): array
    {
        if (!isset(self::MEAL_LABELS[$mealType])) {
            throw new InvalidArgumentException('mealType must be breakfast|lunch|dinner|snack');
        }

        $name = trim($foodName);
        if ($name === '') {
            throw new InvalidArgumentException('foodName is required');
        }

        if ($caloriesKcal <= 0 || $caloriesKcal > 5000) {
            throw new InvalidArgumentException('calories must be between 1 and 5000');
        }

        $normalizedCalorieSource = $this->normalizeCalorieSource($calorieSource);
        $normalizedSourceUrl = $this->normalizeSourceUrl($sourceUrl);
        $normalizedConfidence = $this->normalizeConfidence($confidence);

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO meal_entries (user_id, recorded_on, meal_type, food_name, calories_kcal, calories_edited, calorie_source, source_url, confidence, created_at, updated_at)
             VALUES (:user_id, :recorded_on, :meal_type, :food_name, :calories_kcal, :calories_edited, :calorie_source, :source_url, :confidence, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'recorded_on' => $date,
            'meal_type' => $mealType,
            'food_name' => $name,
            'calories_kcal' => $caloriesKcal,
            'calories_edited' => $caloriesEdited ? 1 : 0,
            'calorie_source' => $normalizedCalorieSource,
            'source_url' => $normalizedSourceUrl,
            'confidence' => $normalizedConfidence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'mealType' => $mealType,
            'label' => $name,
            'calories' => $caloriesKcal,
            'caloriesEdited' => $caloriesEdited,
            'calorieSource' => $normalizedCalorieSource,
            'sourceUrl' => $normalizedSourceUrl,
            'confidence' => $normalizedConfidence,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, label: string, calories: int, caloriesEdited: bool, calorieSource: string|null, sourceUrl: string|null, confidence: string|null}
     */
    private function mapMealItemRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['food_name'],
            'calories' => (int) $row['calories_kcal'],
            'caloriesEdited' => (int) ($row['calories_edited'] ?? 0) === 1,
            'calorieSource' => isset($row['calorie_source']) && $row['calorie_source'] !== null
                ? (string) $row['calorie_source']
                : null,
            'sourceUrl' => isset($row['source_url']) && $row['source_url'] !== null
                ? (string) $row['source_url']
                : null,
            'confidence' => isset($row['confidence']) && $row['confidence'] !== null
                ? (string) $row['confidence']
                : null,
        ];
    }

    private function normalizeCalorieSource(?string $calorieSource): ?string
    {
        $trimmed = trim((string) $calorieSource);
        if ($trimmed === '') {
            return null;
        }

        if (!in_array($trimmed, self::ALLOWED_CALORIE_SOURCES, true)) {
            throw new InvalidArgumentException('calorieSource is invalid');
        }

        return $trimmed;
    }

    private function normalizeSourceUrl(?string $sourceUrl): ?string
    {
        $trimmed = trim((string) $sourceUrl);
        if ($trimmed === '') {
            return null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('sourceUrl must be a valid URL');
        }

        return $trimmed;
    }

    private function normalizeConfidence(?string $confidence): ?string
    {
        $trimmed = trim((string) $confidence);
        if ($trimmed === '') {
            return null;
        }

        if (!in_array($trimmed, self::ALLOWED_CONFIDENCE_LEVELS, true)) {
            throw new InvalidArgumentException('confidence must be high|medium|low');
        }

        return $trimmed;
    }

    /**
     * 食事記録を1件削除する。対象が存在しない、または他ユーザーの記録の場合は例外を投げる。
     *
     * @return array{recordedOn: string, meals: array<int, array{id: string, name: string, calories: int, items: array<int, array{id: int, label: string, calories: int, caloriesEdited: bool}>}>}
     */
    public function deleteEntry(int $entryId): array
    {
        if ($entryId <= 0) {
            throw new InvalidArgumentException('entry id is required');
        }

        $statement = $this->db->prepare(
            'SELECT recorded_on
             FROM meal_entries
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $entryId,
            'user_id' => $this->userId,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new InvalidArgumentException('meal entry not found');
        }

        $recordedOn = (string) $row['recorded_on'];
        $deleteStatement = $this->db->prepare(
            'DELETE FROM meal_entries WHERE id = :id AND user_id = :user_id'
        );
        $deleteStatement->execute([
            'id' => $entryId,
            'user_id' => $this->userId,
        ]);

        return [
            'recordedOn' => $recordedOn,
            'meals' => $this->getSectionsForDate($recordedOn),
        ];
    }

    /**
     * 食事履歴を新しい順で取得する。
     * mealType を指定すると、その区分の履歴のみ返す。
     *
     * @return array<int, array{id: int, mealType: string, label: string, calories: int, caloriesEdited: bool, calorieSource: string|null, sourceUrl: string|null, confidence: string|null, recordedOn: string}>
     */
    public function getHistory(?string $mealType = null, int $limit = 30): array
    {
        $safeLimit = max(1, min(200, $limit));

        if ($mealType !== null && !isset(self::MEAL_LABELS[$mealType])) {
            throw new InvalidArgumentException('mealType must be breakfast|lunch|dinner|snack');
        }

        if ($mealType === null) {
            $statement = $this->db->prepare(
                'SELECT id, meal_type, food_name, calories_kcal, calories_edited, calorie_source, source_url, confidence, recorded_on
                 FROM meal_entries
                 WHERE user_id = :user_id
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
            $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $statement->execute();
        } else {
            $statement = $this->db->prepare(
                'SELECT id, meal_type, food_name, calories_kcal, calories_edited, calorie_source, source_url, confidence, recorded_on
                 FROM meal_entries
                 WHERE user_id = :user_id AND meal_type = :meal_type
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
            $statement->bindValue(':meal_type', $mealType, PDO::PARAM_STR);
            $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $statement->execute();
        }

        $history = [];
        foreach ($statement->fetchAll() as $row) {
            $item = $this->mapMealItemRow($row);
            $history[] = [
                'id' => $item['id'],
                'mealType' => (string) $row['meal_type'],
                'label' => $item['label'],
                'calories' => $item['calories'],
                'caloriesEdited' => $item['caloriesEdited'],
                'calorieSource' => $item['calorieSource'],
                'sourceUrl' => $item['sourceUrl'],
                'confidence' => $item['confidence'],
                'recordedOn' => (string) $row['recorded_on'],
            ];
        }

        return $history;
    }

    /**
     * 指定期間の日別摂取カロリー合計を返す（記録なしの日は 0）。
     *
     * @return array<int, array{label: string, value: int, date: string}>
     */
    public function getDailyTotalsBetween(string $startDate, string $endDate): array
    {
        return $this->buildDailyPointsBetween(
            $startDate,
            $endDate,
            'SELECT recorded_on, COALESCE(SUM(calories_kcal), 0) AS total_value
             FROM meal_entries
             WHERE user_id = :user_id AND recorded_on BETWEEN :start AND :end
             GROUP BY recorded_on
             ORDER BY recorded_on ASC',
        );
    }

    /**
     * @return array<int, array{label: string, value: int, date: string}>
     */
    private function buildDailyPointsBetween(string $startDate, string $endDate, string $sql): array
    {
        $timezone = new DateTimeZone('Asia/Tokyo');
        $start = new DateTimeImmutable($startDate, $timezone);
        $end = new DateTimeImmutable($endDate, $timezone);

        if ($start > $end) {
            return [];
        }

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $this->userId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        /** @var array<string, int> $byDate */
        $byDate = [];
        foreach ($statement->fetchAll() as $row) {
            $byDate[(string) $row['recorded_on']] = (int) $row['total_value'];
        }

        $points = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $points[] = [
                'label' => WeightRepository::formatShortLabel($date),
                'value' => $byDate[$date] ?? 0,
                'date' => $date,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $points;
    }
}
