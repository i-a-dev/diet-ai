<?php

declare(strict_types=1);

/**
 * 体重データ（weight_entries テーブル）の読み書きを担当するクラス。
 * API から呼ばれ、SQL の実行と画面用データの整形を行う。
 */
final class WeightRepository
{
    private PDO $db;

    /**
     * DB 接続を用意する。
     * $db を渡さない場合は Database::connection() で SQLite に接続する。
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * 指定日の体重サマリーを取得する（記録画面の表示用）。
     * 体重・前日比・日付ラベルをまとめて返す。
     * 当日の記録がない場合は current を null とし、直近の参考体重を referenceWeight に入れる。
     *
     * @return array{
     *   current: float|null,
     *   diffFromPreviousDay: float|null,
     *   recordedOn: string,
     *   dateLabel: string,
     *   referenceWeight: float|null,
     *   referenceRecordedOn: string|null
     * }
     */
    public function getSummaryForDate(string $date): array
    {
        $entry = $this->findByDate($date);

        if ($entry === null) {
            $latest = $this->findLatestEntryOnOrBefore($date);

            return [
                'current' => null,
                'diffFromPreviousDay' => null,
                'recordedOn' => $date,
                'dateLabel' => self::formatDateLabel($date),
                'referenceWeight' => $latest === null ? null : (float) $latest['weight_kg'],
                'referenceRecordedOn' => $latest === null ? null : (string) $latest['recorded_on'],
            ];
        }

        $previous = $this->findPreviousEntry($date);
        $current = (float) $entry['weight_kg'];
        // 前日のデータがあれば差分を計算（なければ null）
        $diff = $previous === null
            ? null
            : round($current - (float) $previous['weight_kg'], 1);

        return [
            'current' => $current,
            'diffFromPreviousDay' => $diff,
            'recordedOn' => $date,
            'dateLabel' => self::formatDateLabel($date),
            'referenceWeight' => $current,
            'referenceRecordedOn' => $date,
        ];
    }

    /**
     * 体重を DB に保存する（新規登録 or 同日の上書き更新）。
     * 保存後、getSummaryForDate と同じ形式で最新データを返す。
     *
     * @return array{current: float, diffFromPreviousDay: float|null, recordedOn: string, dateLabel: string}
     */
    public function upsert(string $date, float $weightKg): array
    {
        if ($weightKg <= 0 || $weightKg > 300) {
            throw new InvalidArgumentException('weight must be between 0 and 300 kg.');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $roundedWeight = round($weightKg, 1);

        // 同じ recorded_on があれば UPDATE、なければ INSERT（upsert）
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

        return $summary;
    }

    /**
     * グラフ用に、終了日から遡った N 日分の体重データを配列で返す。
     * 例: [{ label: "6/12", value: 62.7, date: "2026-06-12" }, ...]
     *
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

        /** @var array<string, float> $byDate 日付をキーにした体重の連想配列 */
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
     * 指定日の体重を1件だけ DB から取得する（内部用）。
     *
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
     * 指定日より前の、最も新しい体重を1件取得する（前日比計算用・内部用）。
     *
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

    /**
     * 指定日以前で最も新しい体重を1件取得する（当日未記録時の初期値表示用）。
     *
     * @return array<string, mixed>|null
     */
    private function findLatestEntryOnOrBefore(string $date): ?array
    {
        $statement = $this->db->prepare(
            'SELECT recorded_on, weight_kg
             FROM weight_entries
             WHERE recorded_on <= :recorded_on
             ORDER BY recorded_on DESC
             LIMIT 1'
        );
        $statement->execute(['recorded_on' => $date]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * 今日の日付を YYYY-MM-DD 形式で返す（日本時間）。
     */
    public static function todayDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    }

    /**
     * 日付を画面表示用に整形する。
     * 今日なら「今日 6/18（木）」、それ以外は「6/17（水）」の形式。
     */
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

    /**
     * グラフの横軸用に短い日付ラベルを返す（例: "6/18"）。
     */
    public static function formatShortLabel(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('Asia/Tokyo')))->format('n/j');
    }
}
