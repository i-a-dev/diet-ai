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

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
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
            'SELECT id, meal_type, food_name, calories_kcal
             FROM meal_entries
             WHERE recorded_on = :recorded_on
             ORDER BY id ASC'
        );
        $statement->execute(['recorded_on' => $date]);

        foreach ($statement->fetchAll() as $row) {
            $mealType = (string) $row['meal_type'];
            if (!isset($sections[$mealType])) {
                continue;
            }

            $calories = (int) $row['calories_kcal'];
            $sections[$mealType]['calories'] += $calories;
            $sections[$mealType]['items'][] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['food_name'],
                'calories' => $calories,
            ];
        }

        return array_values($sections);
    }

    /**
     * @return array{id: int, mealType: string, label: string, calories: int}
     */
    public function addEntry(string $date, string $mealType, string $foodName, int $caloriesKcal): array
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

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO meal_entries (recorded_on, meal_type, food_name, calories_kcal, created_at, updated_at)
             VALUES (:recorded_on, :meal_type, :food_name, :calories_kcal, :created_at, :updated_at)'
        );
        $statement->execute([
            'recorded_on' => $date,
            'meal_type' => $mealType,
            'food_name' => $name,
            'calories_kcal' => $caloriesKcal,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'mealType' => $mealType,
            'label' => $name,
            'calories' => $caloriesKcal,
        ];
    }

    /**
     * 食事履歴を新しい順で取得する。
     * mealType を指定すると、その区分の履歴のみ返す。
     *
     * @return array<int, array{id: int, mealType: string, label: string, calories: int, recordedOn: string}>
     */
    public function getHistory(?string $mealType = null, int $limit = 30): array
    {
        $safeLimit = max(1, min(200, $limit));

        if ($mealType !== null && !isset(self::MEAL_LABELS[$mealType])) {
            throw new InvalidArgumentException('mealType must be breakfast|lunch|dinner|snack');
        }

        if ($mealType === null) {
            $statement = $this->db->prepare(
                'SELECT id, meal_type, food_name, calories_kcal, recorded_on
                 FROM meal_entries
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $statement->execute();
        } else {
            $statement = $this->db->prepare(
                'SELECT id, meal_type, food_name, calories_kcal, recorded_on
                 FROM meal_entries
                 WHERE meal_type = :meal_type
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':meal_type', $mealType, PDO::PARAM_STR);
            $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $statement->execute();
        }

        $history = [];
        foreach ($statement->fetchAll() as $row) {
            $history[] = [
                'id' => (int) $row['id'],
                'mealType' => (string) $row['meal_type'],
                'label' => (string) $row['food_name'],
                'calories' => (int) $row['calories_kcal'],
                'recordedOn' => (string) $row['recorded_on'],
            ];
        }

        return $history;
    }
}
