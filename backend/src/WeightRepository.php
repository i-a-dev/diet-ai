<?php

declare(strict_types=1);

/**
 * 体重データ（weight_entries テーブル）の読み書きを担当するクラス。
 * API から呼ばれ、SQL の実行と画面用データの整形を行う。
 */
final class WeightRepository
{
    public const WEIGHT_TIMELINE_SCROLL_FLOOR = '2026-01-01';

    private PDO $db;
    private int $userId;

    /**
     * DB 接続を用意する。
     * $db を渡さない場合は Database::connection() で SQLite に接続する。
     */
    public function __construct(int $userId, ?PDO $db = null)
    {
        $this->userId = $userId;
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
            'INSERT INTO weight_entries (user_id, recorded_on, weight_kg, created_at, updated_at)
             VALUES (:user_id, :recorded_on, :weight_kg, :created_at, :updated_at)
             ON CONFLICT(user_id, recorded_on) DO UPDATE SET
               weight_kg = excluded.weight_kg,
               updated_at = excluded.updated_at'
        )->execute([
            'user_id' => $this->userId,
            'recorded_on' => $date,
            'weight_kg' => $roundedWeight,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = $this->getSummaryForDate($date);

        return $summary;
    }

    /**
     * 指定期間（開始日〜終了日）の体重点列を返す。
     * 記録がない日は value を null にする。
     *
     * @return array<int, array{label: string, value: float|null, date: string}>
     */
    public function getPointsBetween(string $startDate, string $endDate): array
    {
        $timezone = new DateTimeZone('Asia/Tokyo');
        $start = new DateTimeImmutable($startDate, $timezone);
        $end = new DateTimeImmutable($endDate, $timezone);

        if ($start > $end) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT recorded_on, weight_kg
             FROM weight_entries
             WHERE user_id = :user_id AND recorded_on BETWEEN :start AND :end
             ORDER BY recorded_on ASC'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        /** @var array<string, float> $byDate */
        $byDate = [];
        foreach ($statement->fetchAll() as $row) {
            $byDate[(string) $row['recorded_on']] = (float) $row['weight_kg'];
        }

        $points = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $points[] = [
                'label' => self::formatShortLabel($date),
                'value' => $byDate[$date] ?? null,
                'date' => $date,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $points;
    }

    /**
     * 最も古い体重記録日を返す。
     */
    public function getEarliestRecordedDate(): ?string
    {
        $statement = $this->db->prepare('SELECT MIN(recorded_on) FROM weight_entries WHERE user_id = :user_id');
        $statement->execute(['user_id' => $this->userId]);
        $value = $statement->fetchColumn();

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * 体重グラフの取得開始日とスクロール下限日を決める。
     * 表示幅は今日から visibleDays 分（2026-01-01 は考慮しない）。
     * スクロール下限は基本 2026-01-01 だが、それより前の記録があればその日付まで遡れる。
     *
     * @return array{fetchStart: string, scrollFloor: string}
     */
    public function resolveTimelineRange(
        string $endDate,
        int $visibleDays,
        string $scrollFloor = self::WEIGHT_TIMELINE_SCROLL_FLOOR,
    ): array {
        $timezone = new DateTimeZone('Asia/Tokyo');
        $end = new DateTimeImmutable($endDate, $timezone);
        $displayStart = $end->modify(sprintf('-%d days', max(0, $visibleDays - 1)))->format('Y-m-d');

        $earliestRecord = $this->getEarliestRecordedDate();
        $effectiveScrollFloor = $scrollFloor;
        if ($earliestRecord !== null && $earliestRecord < $scrollFloor) {
            $effectiveScrollFloor = $earliestRecord;
        }

        $fetchStart = $displayStart < $effectiveScrollFloor ? $displayStart : $effectiveScrollFloor;

        return [
            'fetchStart' => $fetchStart,
            'scrollFloor' => $effectiveScrollFloor,
        ];
    }

    /**
     * これまで記録した体重の最大値を返す（グラフ上限の計算用）。
     */
    public function getMaxRecordedWeight(): ?float
    {
        $statement = $this->db->prepare('SELECT MAX(weight_kg) FROM weight_entries WHERE user_id = :user_id');
        $statement->execute(['user_id' => $this->userId]);
        $value = $statement->fetchColumn();

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 1);
    }

    /**
     * これまで記録した体重の最小値を返す（グラフ下限の計算用）。
     */
    public function getMinRecordedWeight(): ?float
    {
        $statement = $this->db->prepare('SELECT MIN(weight_kg) FROM weight_entries WHERE user_id = :user_id');
        $statement->execute(['user_id' => $this->userId]);
        $value = $statement->fetchColumn();

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 1);
    }

    /**
     * 体重グラフのY軸範囲を計算する。
     * 上限: 最高記録とグラフ上端の間が全体の約1/10。
     * 下限: 1/10 の式で求めた値を floor した整数（最低記録が目標より下なら最低記録を基準）。
     *
     * @return array{min: int, max: int}
     */
    public function computeChartBounds(?float $targetWeightKg, ?float $fallbackMin = null): array
    {
        $maxRecorded = $this->getMaxRecordedWeight() ?? $targetWeightKg ?? 60.0;
        $minRecorded = $this->getMinRecordedWeight() ?? $fallbackMin ?? $maxRecorded;

        if ($targetWeightKg !== null && $minRecorded < $targetWeightKg) {
            $bottomReference = $minRecorded;
        } elseif ($targetWeightKg !== null) {
            $bottomReference = $targetWeightKg;
        } else {
            $bottomReference = $minRecorded;
        }

        // (max - maxRecorded) / (max - min) = 1/10 かつ (bottomRef - min) / (max - min) = 1/10
        // => max = (9 * maxRecorded - bottomRef) / 8, min = (10 * bottomRef - max) / 9
        $chartMax = (9 * $maxRecorded - $bottomReference) / 8;
        $chartMin = (10 * $bottomReference - $chartMax) / 9;
        $chartMax = (int) round($chartMax);
        $chartMin = (int) floor($chartMin);

        if ($chartMax <= (int) floor($maxRecorded)) {
            $chartMax = (int) ceil($maxRecorded);
        }

        if ($chartMin >= $bottomReference) {
            $chartMin = (int) floor($bottomReference) - 1;
        }

        if ($chartMin >= $chartMax) {
            $chartMin = $chartMax - 10;
        }

        if ($chartMin < 0) {
            $chartMin = 0;
        }

        return [
            'min' => $chartMin,
            'max' => $chartMax,
        ];
    }

    /**
     * 指定日の体重を1件だけ DB から取得する（内部用）。
     *
     * @return array<string, mixed>|null
     */
    private function findByDate(string $date): ?array
    {
        $statement = $this->db->prepare(
            'SELECT recorded_on, weight_kg FROM weight_entries WHERE user_id = :user_id AND recorded_on = :recorded_on LIMIT 1'
        );
        $statement->execute(['user_id' => $this->userId, 'recorded_on' => $date]);
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
             WHERE user_id = :user_id AND recorded_on < :recorded_on
             ORDER BY recorded_on DESC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $this->userId, 'recorded_on' => $date]);
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
             WHERE user_id = :user_id AND recorded_on <= :recorded_on
             ORDER BY recorded_on DESC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $this->userId, 'recorded_on' => $date]);
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
