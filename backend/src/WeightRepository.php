<?php

declare(strict_types=1);

final class WeightRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{current: float, diffFromPreviousDay: float|null, recordedOn: string, dateLabel: string}
     */
    public function getSummaryForDate(string $date): ?array
    {
        $entry = $this->findByDate($date);

        if ($entry === null) {
            return null;
        }

        $previous = $this->findPreviousEntry($date);
        $current = (float) $entry['weight_kg'];
        $diff = $previous === null
            ? null
            : round($current - (float) $previous['weight_kg'], 1);

        return [
            'current' => $current,
            'diffFromPreviousDay' => $diff,
            'recordedOn' => $date,
            'dateLabel' => self::formatDateLabel($date),
        ];
    }

    /**
     * @return array{current: float, diffFromPreviousDay: float|null, recordedOn: string, dateLabel: string}
     */
    public function upsert(string $date, float $weightKg): array
    {
        if ($weightKg <= 0 || $weightKg > 300) {
            throw new InvalidArgumentException('weight must be between 0 and 300 kg.');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $roundedWeight = round($weightKg, 1);

        $this->db->prepare(
            'INSERT INTO weight_entries (recorded_on, weight_kg, created_at, updated_at)
             VALUES (:recorded_on, :weight_kg, :created_at, :updated_at)
             ON CONFLICT(recorded_on) DO UPDATE SET
               weight_kg = excluded.weight_kg,
               updated_at = excluded.updated_at'
        )->execute([
            'recorded_on' => $date,
            'weight_kg' => $roundedWeight,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = $this->getSummaryForDate($date);

        if ($summary === null) {
            throw new RuntimeException('Failed to load saved weight entry.');
        }

        return $summary;
    }

    /**
     * @return array<int, array{label: string, value: float, date: string}>
     */
    public function getPointsEndingOn(string $endDate, int $days = 7): array
    {
        $timezone = new DateTimeZone('Asia/Tokyo');
        $end = new DateTimeImmutable($endDate, $timezone);
        $start = $end->modify(sprintf('-%d days', $days - 1))->format('Y-m-d');

        $statement = $this->db->prepare(
            'SELECT recorded_on, weight_kg
             FROM weight_entries
             WHERE recorded_on BETWEEN :start AND :end
             ORDER BY recorded_on ASC'
        );
        $statement->execute([
            'start' => $start,
            'end' => $endDate,
        ]);

        /** @var array<string, float> $byDate */
        $byDate = [];

        foreach ($statement->fetchAll() as $row) {
            $byDate[(string) $row['recorded_on']] = (float) $row['weight_kg'];
        }

        $points = [];

        for ($index = $days - 1; $index >= 0; $index--) {
            $date = $end->modify(sprintf('-%d days', $index))->format('Y-m-d');

            if (!isset($byDate[$date])) {
                continue;
            }

            $points[] = [
                'label' => self::formatShortLabel($date),
                'value' => $byDate[$date],
                'date' => $date,
            ];
        }

        return $points;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByDate(string $date): ?array
    {
        $statement = $this->db->prepare(
            'SELECT recorded_on, weight_kg FROM weight_entries WHERE recorded_on = :recorded_on LIMIT 1'
        );
        $statement->execute(['recorded_on' => $date]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPreviousEntry(string $date): ?array
    {
        $statement = $this->db->prepare(
            'SELECT recorded_on, weight_kg
             FROM weight_entries
             WHERE recorded_on < :recorded_on
             ORDER BY recorded_on DESC
             LIMIT 1'
        );
        $statement->execute(['recorded_on' => $date]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public static function todayDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    }

    public static function formatDateLabel(string $date): string
    {
        $timezone = new DateTimeZone('Asia/Tokyo');
        $target = new DateTimeImmutable($date, $timezone);
        $today = new DateTimeImmutable('today', $timezone);
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
        $weekday = $weekdays[(int) $target->format('w')];
        $formatted = $target->format('n/j') . '（' . $weekday . '）';

        if ($target->format('Y-m-d') === $today->format('Y-m-d')) {
            return '今日 ' . $formatted;
        }

        return $formatted;
    }

    public static function formatShortLabel(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('Asia/Tokyo')))->format('n/j');
    }
}
