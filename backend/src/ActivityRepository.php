<?php

declare(strict_types=1);

/**
 * 歩数・運動データ（step_entries / exercise_entries）の読み書きを担当するクラス。
 */
final class ActivityRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{count: int, burnedCalories: int}
     */
    public function getStepsForDate(string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT step_count, burned_calories_kcal
             FROM step_entries
             WHERE recorded_on = :recorded_on
             LIMIT 1'
        );
        $statement->execute(['recorded_on' => $date]);
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
            'INSERT INTO step_entries (recorded_on, step_count, burned_calories_kcal, created_at, updated_at)
             VALUES (:recorded_on, :step_count, :burned_calories_kcal, :created_at, :updated_at)
             ON CONFLICT(recorded_on) DO UPDATE SET
               step_count = excluded.step_count,
               burned_calories_kcal = excluded.burned_calories_kcal,
               updated_at = excluded.updated_at'
        );
        $statement->execute([
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
     * @return array{entries: array<int, array{id: int, name: string, amount: int, unit: string, burnedCalories: int}>, burnedCalories: int}
     */
    public function getExercisesForDate(string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT id, exercise_name, amount, unit, burned_calories_kcal
             FROM exercise_entries
             WHERE recorded_on = :recorded_on
             ORDER BY id ASC'
        );
        $statement->execute(['recorded_on' => $date]);

        $entries = [];
        $totalCalories = 0;
        foreach ($statement->fetchAll() as $row) {
            $burnedCalories = (int) $row['burned_calories_kcal'];
            $entries[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['exercise_name'],
                'amount' => (int) $row['amount'],
                'unit' => (string) $row['unit'],
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
     * @return array{id: int, name: string, amount: int, unit: string, burnedCalories: int}
     */
    public function addExercise(string $date, string $name, int $amount, string $unit): array
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

        $burnedCalories = $this->estimateExerciseCalories($exerciseName, $amount, $unit);
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'INSERT INTO exercise_entries (recorded_on, exercise_name, amount, unit, burned_calories_kcal, created_at, updated_at)
             VALUES (:recorded_on, :exercise_name, :amount, :unit, :burned_calories_kcal, :created_at, :updated_at)'
        );
        $statement->execute([
            'recorded_on' => $date,
            'exercise_name' => $exerciseName,
            'amount' => $amount,
            'unit' => $unit,
            'burned_calories_kcal' => $burnedCalories,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'name' => $exerciseName,
            'amount' => $amount,
            'unit' => $unit,
            'burnedCalories' => $burnedCalories,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, amount: int, unit: string, burnedCalories: int, recordedOn: string}>
     */
    public function getExerciseHistory(int $limit = 30): array
    {
        $safeLimit = max(1, min(200, $limit));

        $statement = $this->db->prepare(
            'SELECT id, exercise_name, amount, unit, burned_calories_kcal, recorded_on
             FROM exercise_entries
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $statement->execute();

        $history = [];
        foreach ($statement->fetchAll() as $row) {
            $history[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['exercise_name'],
                'amount' => (int) $row['amount'],
                'unit' => (string) $row['unit'],
                'burnedCalories' => (int) $row['burned_calories_kcal'],
                'recordedOn' => (string) $row['recorded_on'],
            ];
        }

        return $history;
    }

    private function estimateStepCalories(int $count): int
    {
        return (int) round($count * 0.04);
    }

    private function estimateExerciseCalories(string $name, int $amount, string $unit): int
    {
        if ($unit === 'min') {
            $coefficient = 4.0;
            if (str_contains($name, 'ウォーキング')) {
                $coefficient = 3.0;
            } elseif (str_contains($name, 'ランニング')) {
                $coefficient = 10.0;
            } elseif (str_contains($name, 'ストレッチ')) {
                $coefficient = 2.0;
            }

            return max(1, (int) round($amount * $coefficient));
        }

        $coefficient = 1.0;
        if (str_contains($name, 'スクワット')) {
            $coefficient = 2.0;
        } elseif (str_contains($name, '腹筋')) {
            $coefficient = 1.5;
        }

        return max(1, (int) round($amount * $coefficient));
    }
}
