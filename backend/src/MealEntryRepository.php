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
        'alias_db',
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

    private const MEAL_ENTRY_SELECT = 'id, meal_type, food_name, calories_kcal, calories_edited, calorie_source,
        source_url, confidence, food_id, raw_input, amount, unit, serving_label, serving_weight_g,
        protein_g, fat_g, carbs_g, fiber_g, sodium_mg';

    private PDO $db;
    private int $userId;

    public function __construct(int $userId, ?PDO $db = null)
    {
        $this->userId = $userId;
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<int, array{id: string, name: string, calories: int, items: array<int, array<string, mixed>}>}
     */
    public function getSectionsForDate(string $date): array
    {
        /** @var array<string, array{id: string, name: string, calories: int, items: array<int, array<string, mixed>}>} $sections */
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
            'SELECT ' . self::MEAL_ENTRY_SELECT . '
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
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function addEntry(
        string $date,
        string $mealType,
        string $foodName,
        int $caloriesKcal,
        array $options = [],
    ): array {
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

        $caloriesEdited = filter_var($options['caloriesEdited'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $normalizedCalorieSource = $this->normalizeCalorieSource(
            isset($options['calorieSource']) ? (string) $options['calorieSource'] : null
        );
        $normalizedSourceUrl = $this->normalizeSourceUrl(
            isset($options['sourceUrl']) ? (string) $options['sourceUrl'] : null
        );
        $normalizedConfidence = $this->normalizeConfidence(
            isset($options['confidence']) ? (string) $options['confidence'] : null
        );
        $foodId = $this->normalizeOptionalInt($options['foodId'] ?? null);
        $rawInput = $this->normalizeOptionalString($options['rawInput'] ?? null, 255);
        $amount = $this->normalizeOptionalDecimal($options['amount'] ?? null, 0, 10000);
        $unit = $this->normalizeOptionalString($options['unit'] ?? null, 20);
        $servingLabel = $this->normalizeOptionalString($options['servingLabel'] ?? null, 100);
        $servingWeightG = $this->normalizeOptionalDecimal($options['servingWeightG'] ?? null, 0, 10000);
        $proteinG = $this->normalizeNutrient($options['proteinG'] ?? null, 500);
        $fatG = $this->normalizeNutrient($options['fatG'] ?? null, 500);
        $carbsG = $this->normalizeNutrient($options['carbsG'] ?? null, 500);
        $fiberG = $this->normalizeNutrient($options['fiberG'] ?? null, 500);
        $sodiumMg = $this->normalizeNutrient($options['sodiumMg'] ?? null, 100000);

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO meal_entries (
                user_id, recorded_on, meal_type, food_name, calories_kcal, calories_edited,
                calorie_source, source_url, confidence, food_id, raw_input, amount, unit,
                serving_label, serving_weight_g, protein_g, fat_g, carbs_g, fiber_g, sodium_mg,
                created_at, updated_at
             ) VALUES (
                :user_id, :recorded_on, :meal_type, :food_name, :calories_kcal, :calories_edited,
                :calorie_source, :source_url, :confidence, :food_id, :raw_input, :amount, :unit,
                :serving_label, :serving_weight_g, :protein_g, :fat_g, :carbs_g, :fiber_g, :sodium_mg,
                :created_at, :updated_at
             )'
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
            'food_id' => $foodId,
            'raw_input' => $rawInput,
            'amount' => $amount,
            'unit' => $unit,
            'serving_label' => $servingLabel,
            'serving_weight_g' => $servingWeightG,
            'protein_g' => $proteinG,
            'fat_g' => $fatG,
            'carbs_g' => $carbsG,
            'fiber_g' => $fiberG,
            'sodium_mg' => $sodiumMg,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $entryId = (int) $this->db->lastInsertId();

        return $this->mapMealItemRow([
            'id' => $entryId,
            'meal_type' => $mealType,
            'food_name' => $name,
            'calories_kcal' => $caloriesKcal,
            'calories_edited' => $caloriesEdited ? 1 : 0,
            'calorie_source' => $normalizedCalorieSource,
            'source_url' => $normalizedSourceUrl,
            'confidence' => $normalizedConfidence,
            'food_id' => $foodId,
            'raw_input' => $rawInput,
            'amount' => $amount,
            'unit' => $unit,
            'serving_label' => $servingLabel,
            'serving_weight_g' => $servingWeightG,
            'protein_g' => $proteinG,
            'fat_g' => $fatG,
            'carbs_g' => $carbsG,
            'fiber_g' => $fiberG,
            'sodium_mg' => $sodiumMg,
        ]) + ['mealType' => $mealType];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
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
            'foodId' => isset($row['food_id']) && $row['food_id'] !== null
                ? (int) $row['food_id']
                : null,
            'rawInput' => isset($row['raw_input']) && $row['raw_input'] !== null
                ? (string) $row['raw_input']
                : null,
            'amount' => isset($row['amount']) && $row['amount'] !== null
                ? (float) $row['amount']
                : null,
            'unit' => isset($row['unit']) && $row['unit'] !== null
                ? (string) $row['unit']
                : null,
            'servingLabel' => isset($row['serving_label']) && $row['serving_label'] !== null
                ? (string) $row['serving_label']
                : null,
            'servingWeightG' => isset($row['serving_weight_g']) && $row['serving_weight_g'] !== null
                ? (float) $row['serving_weight_g']
                : null,
            'proteinG' => isset($row['protein_g']) && $row['protein_g'] !== null
                ? (float) $row['protein_g']
                : null,
            'fatG' => isset($row['fat_g']) && $row['fat_g'] !== null
                ? (float) $row['fat_g']
                : null,
            'carbsG' => isset($row['carbs_g']) && $row['carbs_g'] !== null
                ? (float) $row['carbs_g']
                : null,
            'fiberG' => isset($row['fiber_g']) && $row['fiber_g'] !== null
                ? (float) $row['fiber_g']
                : null,
            'sodiumMg' => isset($row['sodium_mg']) && $row['sodium_mg'] !== null
                ? (float) $row['sodium_mg']
                : null,
        ];
    }

    private function normalizeCalorieSource(?string $calorieSource): ?string
    {
        $trimmed = trim((string) $calorieSource);
        if ($trimmed === '') {
            return null;
        }

        if (in_array($trimmed, ['brave_html', 'claude_web_search'], true)) {
            $trimmed = 'ai_web_search';
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

    private function normalizeOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException('foodId must be numeric');
        }

        $intValue = (int) $value;
        if ($intValue <= 0) {
            return null;
        }

        return $intValue;
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    private function normalizeOptionalDecimal(mixed $value, float $min, float $max): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException('numeric field is invalid');
        }

        $floatValue = (float) $value;
        if ($floatValue < $min || $floatValue > $max) {
            throw new InvalidArgumentException('numeric field is out of range');
        }

        return round($floatValue, 2);
    }

    private function normalizeNutrient(mixed $value, float $max): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException('nutrient value must be numeric');
        }

        $floatValue = (float) $value;
        if ($floatValue < 0 || $floatValue > $max) {
            throw new InvalidArgumentException('nutrient value is out of range');
        }

        return round($floatValue, 2);
    }

    /**
     * @return array{recordedOn: string, meals: array<int, array{id: string, name: string, calories: int, items: array<int, array<string, mixed>>}>}
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
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(?string $mealType = null, int $limit = 30): array
    {
        $safeLimit = max(1, min(200, $limit));

        if ($mealType !== null && !isset(self::MEAL_LABELS[$mealType])) {
            throw new InvalidArgumentException('mealType must be breakfast|lunch|dinner|snack');
        }

        $select = self::MEAL_ENTRY_SELECT . ', recorded_on';

        if ($mealType === null) {
            $statement = $this->db->prepare(
                'SELECT ' . $select . '
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
                'SELECT ' . $select . '
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
            $history[] = $item + [
                'mealType' => (string) $row['meal_type'],
                'recordedOn' => (string) $row['recorded_on'],
            ];
        }

        return $history;
    }

    /**
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
