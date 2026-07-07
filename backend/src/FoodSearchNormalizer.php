<?php

declare(strict_types=1);

/**
 * 食品検索クエリの正規化を担当する。
 * food_search_aliases の query_normalized と検索時の照合に使う。
 */
final class FoodSearchNormalizer
{
    /** @var list<string> */
    private const STOP_WORDS = [
        'カロリー',
        'カロ',
        'kcal',
        'kcal.',
        '栄養成分',
        '栄養',
        '成分表',
        'エネルギー',
        '熱量',
        'カロリー表',
    ];

    /** @var list<string> */
    private const GENERIC_TERMS = [
        'パン',
        'チョコ',
        'チョコレート',
        'ラーメン',
        'うどん',
        'そば',
        'ご飯',
        'おにぎり',
        'サラダ',
        'スープ',
        'ジュース',
        'お茶',
        'コーヒー',
        '牛乳',
        'ヨーグルト',
    ];

    /** @var list<string> */
    private const HOMEMADE_PATTERNS = [
        '炒め',
        '煮',
        '焼き',
        '揚げ',
        '蒸し',
        '和え',
        'サラダ',
        'カレー',
        'シチュー',
        'スープ',
        '鍋',
        '丼',
        '定食',
        '弁当',
        '昨日',
        '残り',
        '作った',
        '手作り',
        '自炊',
        '母の',
        '父の',
        'とキャベツ',
        'と玉ねぎ',
        'と人参',
    ];

    public static function normalize(string $query): string
    {
        $text = trim($query);
        if ($text === '') {
            return '';
        }

        $text = mb_strtolower($text);
        $text = str_replace("\u{3000}", ' ', $text);
        $text = mb_convert_kana($text, 'as', 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        foreach (self::STOP_WORDS as $word) {
            $pattern = '/' . preg_quote(mb_strtolower($word), '/') . '/iu';
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return $text;
    }

    public static function isTooShort(string $rawQuery): bool
    {
        $trimmed = trim($rawQuery);

        return mb_strlen($trimmed) < 3;
    }

    public static function isGenericTerm(string $rawQuery): bool
    {
        $normalized = self::normalize($rawQuery);
        if ($normalized === '') {
            return true;
        }

        foreach (self::GENERIC_TERMS as $term) {
            if ($normalized === mb_strtolower($term)) {
                return true;
            }
        }

        return false;
    }

    public static function looksHomemade(string $rawQuery): bool
    {
        $normalized = self::normalize($rawQuery);
        if ($normalized === '') {
            return false;
        }

        foreach (self::HOMEMADE_PATTERNS as $pattern) {
            if (mb_strpos($normalized, mb_strtolower($pattern)) !== false) {
                return true;
            }
        }

        if (preg_match('/[と、]\s*\S/u', $normalized) === 1 && mb_strlen($normalized) >= 6) {
            return true;
        }

        return false;
    }

    public static function isNearExactMatch(string $rawQuery, string $foodName): bool
    {
        $query = self::normalize($rawQuery);
        $name = self::normalize($foodName);
        if ($query === '' || $name === '') {
            return false;
        }

        if ($query === $name) {
            return true;
        }

        if (str_starts_with($name, $query) && mb_strlen($name) - mb_strlen($query) <= 3) {
            return true;
        }

        if (str_starts_with($query, $name) && mb_strlen($query) - mb_strlen($name) <= 3) {
            return true;
        }

        return false;
    }

    public static function shouldSaveAlias(
        string $rawQuery,
        string $foodName,
        string $source,
    ): bool {
        if (self::isTooShort($rawQuery)) {
            return false;
        }

        if (self::looksHomemade($rawQuery)) {
            return false;
        }

        if (self::isNearExactMatch($rawQuery, $foodName)) {
            return false;
        }

        $eligibleSources = [
            'user_selected',
            'ai_web_search_selected',
            'ai_web_search',
            'brave_html',
            'claude_web_search',
            'local_db',
            'alias_db',
            'user_registered',
            'manual_merge',
        ];

        return in_array($source, $eligibleSources, true);
    }

    public static function isAmbiguousQuery(string $rawQuery): bool
    {
        if (self::isGenericTerm($rawQuery)) {
            return true;
        }

        $normalized = self::normalize($rawQuery);
        if (mb_strlen($normalized) <= 4) {
            return true;
        }

        return false;
    }

    public static function computeConfidenceScore(int $selectionCount, int $rejectedCount): float
    {
        $total = $selectionCount + $rejectedCount;
        if ($total === 0) {
            return 0.5;
        }

        $score = $selectionCount / $total;

        return round(min(1.0, max(0.0, $score)), 4);
    }
}
