<?php

declare(strict_types=1);

/**
 * 公式ページ探索で得た共通候補。
 */
final readonly class DiscoveredPageCandidate
{
    /**
     * @param list<string> $evidence
     */
    public function __construct(
        public string $url,
        public ?string $candidateName,
        public string $discoverySource,
        public bool $isOfficial,
        public float $nameSimilarity,
        public float $coreSimilarity,
        public bool $hasDistinctCoreConflict,
        public array $evidence = [],
        public ?int $kcalHint = null,
    ) {
    }

    /**
     * @return array{title: string, url: string, description: string, extra_snippets?: list<string>, fetch_source?: string}
     */
    public function toSearchResult(): array
    {
        $title = $this->candidateName ?? '';
        $descriptionParts = $this->evidence;
        if ($this->kcalHint !== null && $this->kcalHint > 0) {
            $descriptionParts[] = $this->kcalHint . 'kcal';
        }
        $descriptionParts[] = $this->discoverySource;

        return [
            'title' => $title,
            'url' => $this->url,
            'description' => implode(' ', array_values(array_unique(array_filter($descriptionParts)))),
            'extra_snippets' => array_values(array_unique(array_filter($descriptionParts))),
            'fetch_source' => 'official_catalog',
        ];
    }

    /**
     * @param list<string> $extraEvidence
     */
    public function withMergedEvidence(array $extraEvidence, ?string $preferredName = null): self
    {
        $evidence = array_values(array_unique(array_merge($this->evidence, $extraEvidence)));
        $name = $this->candidateName;
        if (($name === null || $name === '') && $preferredName !== null && $preferredName !== '') {
            $name = $preferredName;
        }

        return new self(
            url: $this->url,
            candidateName: $name,
            discoverySource: $this->discoverySource,
            isOfficial: $this->isOfficial,
            nameSimilarity: $this->nameSimilarity,
            coreSimilarity: $this->coreSimilarity,
            hasDistinctCoreConflict: $this->hasDistinctCoreConflict,
            evidence: $evidence,
            kcalHint: $this->kcalHint,
        );
    }
}
