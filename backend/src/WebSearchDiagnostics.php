<?php

declare(strict_types=1);

/**
 * AI Web 検索の計測・構造化ログ。
 */
final class WebSearchDiagnostics
{
    private float $startedAt;

    /** @var list<string> */
    private array $fallbackReasons = [];

    public function __construct(
        private readonly string $userInput,
        private readonly WebSearchBudget $budget,
    ) {
        $this->startedAt = microtime(true);
    }

    private string $normalizedProductName = '';
    private ?string $brandName = null;
    private string $searchMode = '';
    private string $variantDimension = '';
    private int $claudeExpectedLabelCount = 0;
    private int $finalCandidateCount = 0;

    /** @var 'sufficient_variants'|'single_candidate_confirmed'|'search_budget_exhausted'|'no_candidates'|'no_web_search'|'fallback'|'' */
    private string $stoppedReason = '';

    public function setPlan(FoodWebSearchPlan $plan): void
    {
        $this->normalizedProductName = $plan->normalizedProductName;
        $this->brandName = $plan->brandName;
        $this->searchMode = $plan->searchMode;
        $this->variantDimension = $plan->variantDimension;
        $this->claudeExpectedLabelCount = count($plan->expectedLabels);
    }

    public function setFinalCandidateCount(int $count): void
    {
        $this->finalCandidateCount = $count;
    }

    public function setStoppedReason(string $reason): void
    {
        $this->stoppedReason = $reason;
    }

    public function addFallbackReason(string $reason): void
    {
        if ($reason !== '' && !in_array($reason, $this->fallbackReasons, true)) {
            $this->fallbackReasons[] = $reason;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $snapshot = $this->budget->snapshot();

        return [
            'userInput' => $this->userInput,
            'normalizedProductName' => $this->normalizedProductName,
            'brandName' => $this->brandName,
            'searchMode' => $this->searchMode,
            'variantDimension' => $this->variantDimension,
            'claudeExpectedLabelCount' => $this->claudeExpectedLabelCount,
            'haikuCalls' => $snapshot['haikuCalls'],
            'braveSearchCalls' => $snapshot['braveSearchCalls'],
            'htmlFetchCalls' => $snapshot['htmlFetchCalls'],
            'claudeWebSearchCalls' => $snapshot['claudeWebSearchCalls'],
            'finalCandidateCount' => $this->finalCandidateCount,
            'durationMs' => (int) round((microtime(true) - $this->startedAt) * 1000),
            'stoppedReason' => $this->stoppedReason,
            'fallbackReasons' => $this->fallbackReasons,
        ];
    }

    public function log(): void
    {
        error_log('[ai_web_search] ' . json_encode($this->toArray(), JSON_UNESCAPED_UNICODE));
    }
}
