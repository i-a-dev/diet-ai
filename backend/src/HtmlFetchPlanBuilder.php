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
