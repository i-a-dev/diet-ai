<?php

declare(strict_types=1);

/**
 * 歩数・運動データ（step_entries / exercise_entries）の読み書きを担当するクラス。
 */
final class ActivityRepository
{
    private PDO $db;
    private int $userId;

    public function __construct(int $userId, ?PDO $db = null)
    {
        $this->userId = $userId;
        $this->db = $db ?? Database::connection();
    }

    /**
     * 最も古い歩数記録日を返す。
     */
    public function getEarliestStepsRecordedDate(): ?string
    {
        $statement = $this->db->prepare(
            'SELECT MIN(recorded_on) FROM step_entries WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $this->userId]);
        $value = $statement->fetchColumn();

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * 最も古い運動記録日を返す。
     */
    public function getEarliestExerciseRecordedDate(): ?string
    {
        $statement = $this->db->prepare(
            'SELECT MIN(recorded_on) FROM exercise_entries WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $this->userId]);
        $value = $statement->fetchColumn();

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return array{count: int, burnedCalories: int}
     */
    public function getStepsForDate(string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT step_count, burned_calories_kcal
             FROM step_entries
             WHERE user_id = :user_id AND recorded_on = :recorded_on
             LIMIT 1'
        );
        $statement->execute(['user_id' => $this->userId, 'recorded_on' => $date]);
        $row = $statement->fetch();

        if ($row === false) {
            return [
                'count' => 0,
                'burnedCalories' => 0,
            ];
        }

        return [
            'count' => (int) $row['step_count'],
            'burnedCalories' => (int) $row['burned_calories_kcal'],
        ];
    }

    /**
     * @return array{count: int, burnedCalories: int}
     */
    public function upsertSteps(string $date, int $count): array
    {
        if ($count < 0 || $count > 100000) {
            throw new InvalidArgumentException('count must be between 0 and 100000');
        }

        $burnedCalories = $this->estimateStepCalories($count);
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'INSERT INTO step_entries (user_id, recorded_on, step_count, burned_calories_kcal, created_at, updated_at)
             VALUES (:user_id, :recorded_on, :step_count, :burned_calories_kcal, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
               step_count = VALUES(step_count),
               burned_calories_kcal = VALUES(burned_calories_kcal),
               updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'recorded_on' => $date,
            'step_count' => $count,
            'burned_calories_kcal' => $burnedCalories,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'count' => $count,
            'burnedCalories' => $burnedCalories,
        ];
    }

    /**
     * @return array{entries: array<int, array{
     *   id: int,
     *   name: string,
     *   amount: int,
     *   unit: string,
     *   minutes: int,
     *   mets: float,
     *   source: string,
     *   confidence: string,
     *   isEstimated: bool,
     *   note: string|null,
     *   weightKg: float,
     *   weightSource: string,
     *   burnedCalories: int
     * }>, burnedCalories: int}
     */
    public function getExercisesForDate(string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT id, exercise_name, amount, unit, minutes, mets, source, confidence, is_estimated, estimate_note, weight_kg, weight_source, burned_calories_kcal
             FROM exercise_entries
             WHERE user_id = :user_id AND recorded_on = :recorded_on
             ORDER BY id ASC'
        );
        $statement->execute(['user_id' => $this->userId, 'recorded_on' => $date]);

        $entries = [];
        $totalCalories = 0;
        foreach ($statement->fetchAll() as $row) {
            $burnedCalories = (int) $row['burned_calories_kcal'];
            $entries[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['exercise_name'],
                'amount' => (int) $row['amount'],
                'unit' => (string) $row['unit'],
                'minutes' => (int) $row['minutes'],
                'mets' => round((float) $row['mets'], 1),
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
                'isEstimated' => ((int) $row['is_estimated']) === 1,
                'note' => $row['estimate_note'] === null ? null : (string) $row['estimate_note'],
                'weightKg' => round((float) $row['weight_kg'], 1),
                'weightSource' => (string) $row['weight_source'],
                'burnedCalories' => $burnedCalories,
            ];
            $totalCalories += $burnedCalories;
        }

        return [
            'entries' => $entries,
            'burnedCalories' => $totalCalories,
        ];
    }

    /**
     * @return array{
     *   id: int,
     *   name: string,
     *   amount: int,
     *   unit: string,
     *   minutes: int,
     *   mets: float,
     *   source: string,
     *   confidence: string,
     *   isEstimated: bool,
     *   note: string|null,
     *   weightKg: float,
     *   weightSource: string,
     *   burnedCalories: int
     * }
     */
    public function addExercise(
        string $date,
        string $name,
        int $amount,
        string $unit,
        int $minutes,
        float $mets,
        int $burnedCalories,
        string $source,
        string $confidence,
        bool $isEstimated,
        float $weightKg,
        string $weightSource,
        ?string $note = null
    ): array
    {
        $exerciseName = trim($name);
        if ($exerciseName === '') {
            throw new InvalidArgumentException('exerciseName is required');
        }

        if ($amount <= 0 || $amount > 10000) {
            throw new InvalidArgumentException('amount must be between 1 and 10000');
        }

        if ($unit !== 'min' && $unit !== 'rep') {
            throw new InvalidArgumentException('unit must be min|rep');
        }

        if ($minutes <= 0 || $minutes > 600) {
            throw new InvalidArgumentException('minutes must be between 1 and 600');
        }
        if ($mets <= 0 || $mets > 25) {
            throw new InvalidArgumentException('mets must be between 0 and 25');
        }
        if ($burnedCalories <= 0 || $burnedCalories > 5000) {
            throw new InvalidArgumentException('burnedCalories must be between 1 and 5000');
        }
        if ($source !== 'local_db' && $source !== 'llm_estimate') {
            throw new InvalidArgumentException('source must be local_db|llm_estimate');
        }
        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            throw new InvalidArgumentException('confidence must be high|medium|low');
        }
        if ($weightKg <= 0 || $weightKg > 300) {
            throw new InvalidArgumentException('weightKg must be between 0 and 300');
        }
        if (!in_array($weightSource, ['current', 'reference', 'default'], true)) {
            throw new InvalidArgumentException('weightSource must be current|reference|default');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'INSERT INTO exercise_entries (
                user_id, recorded_on, exercise_name, amount, unit, minutes, mets, source, confidence, is_estimated, estimate_note,
                weight_kg, weight_source, burned_calories_kcal, created_at, updated_at
             )
             VALUES (
                :user_id, :recorded_on, :exercise_name, :amount, :unit, :minutes, :mets, :source, :confidence, :is_estimated, :estimate_note,
                :weight_kg, :weight_source, :burned_calories_kcal, :created_at, :updated_at
             )'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'recorded_on' => $date,
            'exercise_name' => $exerciseName,
            'amount' => $amount,
            'unit' => $unit,
            'minutes' => $minutes,
            'mets' => round($mets, 1),
            'source' => $source,
            'confidence' => $confidence,
            'is_estimated' => $isEstimated ? 1 : 0,
            'estimate_note' => $note,
            'weight_kg' => round($weightKg, 1),
            'weight_source' => $weightSource,
            'burned_calories_kcal' => $burnedCalories,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'name' => $exerciseName,
            'amount' => $amount,
            'unit' => $unit,
            'minutes' => $minutes,
            'mets' => round($mets, 1),
            'source' => $source,
            'confidence' => $confidence,
            'isEstimated' => $isEstimated,
            'note' => $note,
            'weightKg' => round($weightKg, 1),
            'weightSource' => $weightSource,
            'burnedCalories' => $burnedCalories,
        ];
    }

    /**
     * @return array<int, array{
     *   id: int,
     *   name: string,
     *   amount: int,
     *   unit: string,
     *   minutes: int,
     *   mets: float,
     *   source: string,
     *   confidence: string,
     *   isEstimated: bool,
     *   note: string|null,
     *   weightKg: float,
     *   weightSource: string,
     *   burnedCalories: int,
     *   recordedOn: string
     * }>
     */
    public function getExerciseHistory(int $limit = 30): array
    {
        $safeLimit = max(1, min(200, $limit));

        $statement = $this->db->prepare(
            'SELECT id, exercise_name, amount, unit, minutes, mets, source, confidence, is_estimated, estimate_note, weight_kg, weight_source, burned_calories_kcal, recorded_on
             FROM exercise_entries
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $statement->execute();

        $history = [];
        foreach ($statement->fetchAll() as $row) {
            $history[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['exercise_name'],
                'amount' => (int) $row['amount'],
                'unit' => (string) $row['unit'],
                'minutes' => (int) $row['minutes'],
                'mets' => round((float) $row['mets'], 1),
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
                'isEstimated' => ((int) $row['is_estimated']) === 1,
                'note' => $row['estimate_note'] === null ? null : (string) $row['estimate_note'],
                'weightKg' => round((float) $row['weight_kg'], 1),
                'weightSource' => (string) $row['weight_source'],
                'burnedCalories' => (int) $row['burned_calories_kcal'],
                'recordedOn' => (string) $row['recorded_on'],
            ];
        }

        return $history;
    }

    /**
     * 指定期間の日別運動消費カロリー合計を返す（記録なしの日は 0）。
     *
     * @return array<int, array{label: string, value: int, date: string}>
     */
    public function getDailyExerciseCaloriesBetween(string $startDate, string $endDate): array
    {
        return $this->buildDailyPointsBetween(
            $startDate,
            $endDate,
            'SELECT recorded_on, COALESCE(SUM(burned_calories_kcal), 0) AS total_value
             FROM exercise_entries
             WHERE user_id = :user_id AND recorded_on BETWEEN :start AND :end
             GROUP BY recorded_on
             ORDER BY recorded_on ASC',
        );
    }

    /**
     * 指定期間の日別歩数を返す（記録なしの日は 0）。
     *
     * @return array<int, array{label: string, value: int, date: string}>
     */
    public function getDailyStepsBetween(string $startDate, string $endDate): array
    {
        return $this->buildDailyPointsBetween(
            $startDate,
            $endDate,
            'SELECT recorded_on, step_count AS total_value
             FROM step_entries
             WHERE user_id = :user_id AND recorded_on BETWEEN :start AND :end
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

    private function estimateStepCalories(int $count): int
    {
        return (int) round($count * 0.04);
    }

}
