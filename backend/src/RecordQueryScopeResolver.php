<?php

declare(strict_types=1);

/**
 * ユーザー質問から記録参照の対象期間を startDate/endDate へ解決する。
 */
final class RecordQueryScopeResolver
{
    private const TIMEZONE = 'Asia/Tokyo';

    /**
     * 「今日の食事」系のヒント。明確な期間語がなくても TODAY にする。
     *
     * @var list<string>
     */
    private const TODAY_HINTS = [
        'このご飯',
        'この食事',
        '今食べた',
        'あと何kcal',
        'あと何カロリー',
        'あと何キロカロリー',
        '食べられる',
        'バランスは',
        '今日のご飯',
        '本日のご飯',
        '今日の食事',
        '本日の食事',
        '残りのカロリー',
        '残りカロリー',
    ];

    /**
     * 「最近の傾向」系。明確な期間語がなくても直近7日にする。
     *
     * @var list<string>
     */
    private const RECENT_TREND_HINTS = [
        '最近の食生活',
        '食事の傾向',
        '普段の食生活',
        '最近バランス',
        '食生活について',
        '傾向を教えて',
        '傾向について',
        '最近どう',
        '最近の食事',
        'このところの食事',
    ];

    /**
     * @param DateTimeImmutable|null $activeRecordDate 直前登録や会話の対象日（取れる場合）
     */
    public function resolve(
        string $userMessage,
        DateTimeImmutable $today,
        ?DateTimeImmutable $activeRecordDate = null,
    ): RecordQueryScope {
        $today = $today->setTimezone(new DateTimeZone(self::TIMEZONE))->setTime(0, 0);
        $normalized = $this->normalizeMessage($userMessage);

        if (($explicit = $this->resolveExplicitScope($normalized, $today)) !== null) {
            return $explicit;
        }

        if ($this->matchesAny($normalized, self::RECENT_TREND_HINTS)) {
            return $this->recentDays($today, 7, '最近の傾向（デフォルト直近7日）');
        }

        if ($activeRecordDate !== null) {
            $active = $activeRecordDate->setTimezone(new DateTimeZone(self::TIMEZONE))->setTime(0, 0);

            return new RecordQueryScope(
                RecordScopeType::DATE_RANGE,
                $active,
                $active,
                '会話対象日（デフォルト）',
            );
        }

        if ($this->matchesAny($normalized, self::TODAY_HINTS)) {
            return new RecordQueryScope(
                RecordScopeType::TODAY,
                $today,
                $today,
                '今日（デフォルト・食事系質問）',
            );
        }

        // 判定できない場合は既存仕様に合わせ今日を対象にする
        return new RecordQueryScope(
            RecordScopeType::UNSPECIFIED,
            $today,
            $today,
            '期間未指定（デフォルト今日）',
        );
    }

    private function normalizeMessage(string $message): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return '';
        }

        // 全角数字・全角英数字を半角へ（直近７日 など）
        if (function_exists('mb_convert_kana')) {
            $trimmed = mb_convert_kana($trimmed, 'n', 'UTF-8');
        }

        $trimmed = str_replace(["\r\n", "\r"], "\n", $trimmed);

        return $trimmed;
    }

    private function resolveExplicitScope(string $normalized, DateTimeImmutable $today): ?RecordQueryScope
    {
        // 直近N日 / 過去N日（今日を含む N 日間）
        if (preg_match('/(?:直近|過去)\s*(\d+)\s*日/u', $normalized, $matches) === 1) {
            $days = max(1, (int) $matches[1]);

            return $this->recentDays($today, $days, $matches[0]);
        }

        // 漢数字の一週間・二週間など
        if (preg_match('/(?:直近|過去)\s*([一二三四五六七八九十]+)\s*日/u', $normalized, $matches) === 1) {
            $days = $this->kanjiToInt($matches[1]);
            if ($days !== null) {
                return $this->recentDays($today, $days, $matches[0]);
            }
        }

        // 直近N週間 / 過去N週間
        if (preg_match('/(?:直近|過去)\s*(\d+)\s*週間/u', $normalized, $matches) === 1) {
            $weeks = max(1, (int) $matches[1]);
            $days = $weeks * 7;

            return $this->recentDays($today, $days, $matches[0], RecordScopeType::RECENT_DAYS);
        }

        if (preg_match('/(?:直近|過去)\s*([一二三四五六七八九十]+)\s*週間/u', $normalized, $matches) === 1) {
            $weeks = $this->kanjiToInt($matches[1]);
            if ($weeks !== null) {
                return $this->recentDays($today, $weeks * 7, $matches[0]);
            }
        }

        if (preg_match('/(?:直近|過去)?\s*一週間|この一週間/u', $normalized) === 1) {
            return $this->recentDays($today, 7, '直近一週間');
        }

        if (preg_match('/今日|本日/u', $normalized) === 1) {
            return new RecordQueryScope(
                RecordScopeType::TODAY,
                $today,
                $today,
                '今日',
            );
        }

        if (preg_match('/昨日/u', $normalized) === 1) {
            $yesterday = $today->modify('-1 day');

            return new RecordQueryScope(
                RecordScopeType::YESTERDAY,
                $yesterday,
                $yesterday,
                '昨日',
            );
        }

        if (preg_match('/今週/u', $normalized) === 1) {
            $monday = $this->startOfWeekMonday($today);

            return new RecordQueryScope(
                RecordScopeType::CURRENT_WEEK,
                $monday,
                $today,
                '今週',
            );
        }

        if (preg_match('/先週/u', $normalized) === 1) {
            $thisMonday = $this->startOfWeekMonday($today);
            $prevMonday = $thisMonday->modify('-7 days');
            $prevSunday = $thisMonday->modify('-1 day');

            return new RecordQueryScope(
                RecordScopeType::PREVIOUS_WEEK,
                $prevMonday,
                $prevSunday,
                '先週',
            );
        }

        if (preg_match('/今月/u', $normalized) === 1) {
            $start = $today->modify('first day of this month')->setTime(0, 0);

            return new RecordQueryScope(
                RecordScopeType::CURRENT_MONTH,
                $start,
                $today,
                '今月',
            );
        }

        if (preg_match('/先月/u', $normalized) === 1) {
            $start = $today->modify('first day of previous month')->setTime(0, 0);
            $end = $today->modify('last day of previous month')->setTime(0, 0);

            return new RecordQueryScope(
                RecordScopeType::PREVIOUS_MONTH,
                $start,
                $end,
                '先月',
            );
        }

        return null;
    }

    private function recentDays(
        DateTimeImmutable $today,
        int $days,
        string $expression,
        RecordScopeType $type = RecordScopeType::RECENT_DAYS,
    ): RecordQueryScope {
        $days = max(1, $days);
        // 今日を含む N 日間 → start = today - (N-1)
        $start = $today->modify(sprintf('-%d days', $days - 1));

        return new RecordQueryScope($type, $start, $today, $expression);
    }

    /**
     * ISO 8601 の月曜始まり（1=Mon ... 7=Sun）。
     */
    private function startOfWeekMonday(DateTimeImmutable $date): DateTimeImmutable
    {
        $dayOfWeek = (int) $date->format('N');

        return $date->modify(sprintf('-%d days', $dayOfWeek - 1))->setTime(0, 0);
    }

    /**
     * @param list<string> $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function kanjiToInt(string $kanji): ?int
    {
        $map = [
            '一' => 1,
            '二' => 2,
            '三' => 3,
            '四' => 4,
            '五' => 5,
            '六' => 6,
            '七' => 7,
            '八' => 8,
            '九' => 9,
            '十' => 10,
        ];

        if (isset($map[$kanji])) {
            return $map[$kanji];
        }

        // 十二 などの簡易対応
        if ($kanji === '十一') {
            return 11;
        }
        if ($kanji === '十二') {
            return 12;
        }
        if ($kanji === '十四') {
            return 14;
        }

        return null;
    }
}
