<?php

declare(strict_types=1);

/**
 * HTML 取得枠を目的別に割り当てる。
 *
 * 枠:
 * - exact_name: 最大3（完全一致 / 非常に高い一致、コア衝突なし）
 * - official_domain: 最大3（公式かつコア衝突なし）
 * - overall_rank: 最大2（残り総合上位）
 */
final class HtmlFetchPlanBuilder
{
    private const EXACT_SLOTS = 3;
    private const OFFICIAL_SLOTS = 3;
    private const OVERALL_SLOTS = 2;

    public function __construct(
        private readonly ProductMatchEvaluator $productMatchEvaluator = new ProductMatchEvaluator(),
        private readonly OfficialSiteBrandResolver $officialSiteBrandResolver = new OfficialSiteBrandResolver(),
    ) {
    }

    /**
     * @param list<array{
     *   url: string,
     *   score: int,
     *   title: string,
     *   description: string,
     *   title_match?: array<string, mixed>,
     *   extra_snippets?: list<string>,
     *   fetch_source?: string
     * }> $ranked
     * @return list<array{
     *   url: string,
     *   score: int,
     *   title: string,
     *   description: string,
     *   reason: string,
     *   fetch_priority: string,
     *   title_match: array<string, mixed>,
     *   extra_snippets?: list<string>
     * }>
     */
    public function build(
        array $ranked,
        string $productName,
        ?string $brandName,
        string $userInput = '',
        int $maxFetches = WebSearchBudget::MAX_HTML_FETCHES,
    ): array {
        $prepared = [];
        foreach ($ranked as $entry) {
            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $title = trim((string) ($entry['title'] ?? ''));
            $titleMatch = $entry['title_match'] ?? null;
            if (!is_array($titleMatch)) {
                $titleMatch = $this->productMatchEvaluator->analyzeTitleMatch(
                    $productName,
                    $this->titleProductHint($title),
                    $brandName,
                );
            }

            $hasDistinctCores = (bool) ($titleMatch['has_distinct_cores'] ?? false);
            $hasExactPhrase = (bool) ($titleMatch['has_exact_phrase'] ?? false);
            // 完全一致フレーズがあれば装飾語による distinct core より優先する
            $isExact = $hasExactPhrase
                || ($this->isExactOrVeryHighMatch($titleMatch) && !$hasDistinctCores);
            $isOfficial = $this->officialSiteBrandResolver->isOfficialUrl($url, $userInput, $brandName);
            $catalogSource = (($entry['fetch_source'] ?? '') === 'official_catalog');

            $priority = 'normal';
            if ($isExact || $hasExactPhrase) {
                $priority = 'high';
            } elseif ($hasDistinctCores) {
                $priority = 'low';
            }

            $prepared[] = [
                'url' => $url,
                'score' => (int) ($entry['score'] ?? 0),
                'title' => $title,
                'description' => trim((string) ($entry['description'] ?? '')),
                'title_match' => $titleMatch,
                'extra_snippets' => is_array($entry['extra_snippets'] ?? null) ? $entry['extra_snippets'] : [],
                'is_exact' => $isExact,
                'is_official' => $isOfficial && !($hasDistinctCores && !$hasExactPhrase),
                'is_catalog' => $catalogSource,
                'fetch_priority' => $priority,
                'has_distinct_cores' => $hasDistinctCores && !$hasExactPhrase,
            ];
        }

        $selected = [];
        $seen = [];

        $take = function (callable $predicate, string $reason, int $limit) use (&$prepared, &$selected, &$seen): void {
            $count = 0;
            foreach ($prepared as $entry) {
                if ($count >= $limit) {
                    break;
                }
                $url = $entry['url'];
                if (isset($seen[$url])) {
                    continue;
                }
                if (!$predicate($entry)) {
                    continue;
                }
                $seen[$url] = true;
                $selected[] = $this->toPlanEntry($entry, $reason);
                $count++;
            }
        };

        // カタログ由来の公式詳細を exact / official より前に確保
        $take(
            static fn (array $e): bool => ($e['is_catalog'] ?? false) === true && ($e['has_distinct_cores'] ?? false) === false,
            'official_catalog',
            min(2, $maxFetches),
        );

        $take(
            static fn (array $e): bool => ($e['is_exact'] ?? false) === true,
            'exact_name',
            self::EXACT_SLOTS,
        );

        $take(
            static fn (array $e): bool => ($e['is_official'] ?? false) === true,
            'official_domain',
            self::OFFICIAL_SLOTS,
        );

        $overallBefore = count($selected);
        $take(
            static fn (array $e): bool => ($e['fetch_priority'] ?? '') !== 'low',
            'overall_rank',
            self::OVERALL_SLOTS,
        );
        $overallTaken = count($selected) - $overallBefore;
        if ($overallTaken < self::OVERALL_SLOTS) {
            $take(
                static fn (array $e): bool => true,
                'overall_rank',
                self::OVERALL_SLOTS - $overallTaken,
            );
        }

        return array_slice($selected, 0, $maxFetches);
    }

    /**
     * Wave 1: 完全一致または公式上位2件
     * Wave 2: 次の候補最大3件
     * Wave 3: 残り最大3件
     *
     * @param list<array<string, mixed>> $fetchPlan
     * @return list<list<array<string, mixed>>>
     */
    public function splitIntoWaves(array $fetchPlan): array
    {
        if ($fetchPlan === []) {
            return [];
        }

        $wave1 = [];
        $rest = [];
        foreach ($fetchPlan as $entry) {
            $reason = (string) ($entry['reason'] ?? '');
            $isWave1 = in_array($reason, ['exact_name', 'official_domain', 'official_catalog'], true)
                || (($entry['fetch_priority'] ?? '') === 'high');
            if ($isWave1 && count($wave1) < 2) {
                $wave1[] = $entry;
            } else {
                $rest[] = $entry;
            }
        }

        // Wave1 が空なら先頭2件を Fast に使う
        if ($wave1 === []) {
            $wave1 = array_slice($fetchPlan, 0, 2);
            $rest = array_slice($fetchPlan, 2);
        }

        $wave2 = array_slice($rest, 0, 3);
        $wave3 = array_slice($rest, 3, 3);
        $waves = [];
        if ($wave1 !== []) {
            $waves[] = $wave1;
        }
        if ($wave2 !== []) {
            $waves[] = $wave2;
        }
        if ($wave3 !== []) {
            $waves[] = $wave3;
        }

        return $waves;
    }

    /**
     * @param array<string, mixed> $titleMatch
     */
    public function isExactOrVeryHighMatch(array $titleMatch): bool
    {
        if (($titleMatch['has_exact_phrase'] ?? false) === true) {
            return true;
        }

        if (($titleMatch['has_distinct_cores'] ?? false) === true) {
            return false;
        }

        $tokenCoverage = (float) ($titleMatch['token_coverage'] ?? 0.0);
        $nameSimilarity = (float) ($titleMatch['name_similarity'] ?? 0.0);

        return $tokenCoverage >= 0.9 || $nameSimilarity >= 0.9;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{
     *   url: string,
     *   score: int,
     *   title: string,
     *   description: string,
     *   reason: string,
     *   fetch_priority: string,
     *   title_match: array<string, mixed>,
     *   extra_snippets: list<string>
     * }
     */
    private function toPlanEntry(array $entry, string $reason): array
    {
        return [
            'url' => (string) $entry['url'],
            'score' => (int) $entry['score'],
            'title' => (string) $entry['title'],
            'description' => (string) $entry['description'],
            'reason' => $reason,
            'fetch_priority' => (string) ($entry['fetch_priority'] ?? 'normal'),
            'title_match' => is_array($entry['title_match'] ?? null) ? $entry['title_match'] : [],
            'extra_snippets' => is_array($entry['extra_snippets'] ?? null) ? $entry['extra_snippets'] : [],
        ];
    }

    private function titleProductHint(string $title): string
    {
        $hint = trim((string) preg_replace('/\s*[|｜].*$/u', '', $title));
        $hint = trim((string) preg_replace('/\s*(栄養成分|カロリー|エネルギー).*$/u', '', $hint));

        return $hint !== '' ? $hint : $title;
    }
}
