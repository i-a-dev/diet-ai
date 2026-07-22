<?php

declare(strict_types=1);

/**
 * Strategy 共通の候補スコアリング（ProductMatchEvaluator を再利用）。
 */
final class OfficialDiscoveryCandidateFactory
{
    public function __construct(
        private readonly ProductMatchEvaluator $matchEvaluator = new ProductMatchEvaluator(),
        private readonly OfficialPathPatternMatcher $pathMatcher = new OfficialPathPatternMatcher(),
    ) {
    }

    /**
     * @param list<string> $evidence
     */
    public function create(
        string $url,
        ?string $candidateName,
        string $discoverySource,
        FoodSearchSubject $subject,
        OfficialSiteProfile $profile,
        array $evidence = [],
        ?int $kcalHint = null,
    ): ?DiscoveredPageCandidate {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        if (!$this->pathMatcher->matchesAny($path, $profile->allowedPathPatterns)) {
            return null;
        }

        $queryName = trim($subject->productName !== '' ? $subject->productName : $subject->rawInput);
        $name = trim((string) $candidateName);
        $titleMatch = $name !== ''
            ? $this->matchEvaluator->analyzeTitleMatch($queryName, $name, $subject->brandName)
            : [
                'name_similarity' => 0.0,
                'core_similarity' => 0.0,
                'has_distinct_cores' => false,
                'has_exact_phrase' => false,
                'token_coverage' => 0.0,
            ];

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $isOfficial = $host === $profile->domain || str_ends_with($host, '.' . $profile->domain);

        return new DiscoveredPageCandidate(
            url: $url,
            candidateName: $name !== '' ? $name : null,
            discoverySource: $discoverySource,
            isOfficial: $isOfficial,
            nameSimilarity: (float) ($titleMatch['name_similarity'] ?? 0.0),
            coreSimilarity: (float) ($titleMatch['core_similarity'] ?? 0.0),
            hasDistinctCoreConflict: (bool) ($titleMatch['has_distinct_cores'] ?? false),
            evidence: $evidence,
            kcalHint: $kcalHint,
        );
    }

    public function isStrongMatch(DiscoveredPageCandidate $candidate): bool
    {
        if ($candidate->hasDistinctCoreConflict) {
            return false;
        }

        return $candidate->nameSimilarity >= 0.9
            || $candidate->coreSimilarity >= 0.9
            || (
                $candidate->candidateName !== null
                && $candidate->candidateName !== ''
                && $candidate->nameSimilarity >= 0.85
            );
    }

    public function passesSubjectFilter(DiscoveredPageCandidate $candidate, FoodSearchSubject $subject): bool
    {
        if ($candidate->hasDistinctCoreConflict) {
            return false;
        }

        $queryName = trim($subject->productName !== '' ? $subject->productName : $subject->rawInput);
        if ($queryName === '') {
            return true;
        }

        if ($candidate->candidateName === null || $candidate->candidateName === '') {
            // 名前不明でも detail path 候補として残し、後段 HTML で判定する余地を残す
            return true;
        }

        return ($candidate->nameSimilarity >= 0.72)
            || ($candidate->coreSimilarity >= 0.75);
    }
}
