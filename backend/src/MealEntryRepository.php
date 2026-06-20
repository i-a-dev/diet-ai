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
}
