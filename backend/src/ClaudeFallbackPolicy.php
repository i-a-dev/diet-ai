<?php

declare(strict_types=1);

/**
 * Claude Web Search フォールバック可否を一元判定する。
 */
final class ClaudeFallbackPolicy
{
    /**
     * @param array{accepted: list<array<string, mixed>>, confirmation: list<array<string, mixed>>} $deterministicOutcome
     */
    public function decide(
        FoodSearchSubject $subject,
        array $deterministicOutcome,
        SearchRuntimeContext $runtime,
        WebSearchBudget $budget,
        FoodWebSearchPlan $plan,
        bool $hasStrongConfirmation,
        bool $claudeNotFoundCached,
        bool $circuitOpen,
        bool $dailyBudgetRemaining,
    ): ClaudeFallbackDecision {
        $mode = $runtime->claudeFallbackMode;

        if ($mode === 'off') {
            return new ClaudeFallbackDecision(false, 'mode_off');
        }

        if ($mode === 'manual') {
            if (!$runtime->allowExpensiveFallback) {
                return new ClaudeFallbackDecision(false, 'manual_requires_explicit_request');
            }
        }

        if ($mode === 'conditional' && $runtime->allowExpensiveFallback) {
            // manual deep search 相当: conditional でも明示リクエストなら許可（予算・deadline は見る）
        } elseif ($mode === 'conditional') {
            // 自動 conditional のみ厳格条件
        } elseif ($mode === 'always') {
            // legacy
        }

        if (!$budget->canClaudeWebSearch()) {
            return new ClaudeFallbackDecision(false, 'claude_budget_exhausted');
        }

        if (!$runtime->timing->hasDeadlineRemaining($runtime->totalDeadlineMs)) {
            return new ClaudeFallbackDecision(false, 'deadline_exceeded');
        }

        if ($claudeNotFoundCached) {
            return new ClaudeFallbackDecision(false, 'claude_not_found_negative_cached');
        }

        if ($circuitOpen && !($mode === 'manual' && $runtime->allowExpensiveFallback)) {
            $allowManualWhenOpen = !in_array(
                strtolower(trim((string) (getenv('CLAUDE_WEB_SEARCH_CIRCUIT_STOPS_MANUAL') ?: '0'))),
                ['1', 'true', 'yes', 'on'],
                true,
            );
            if (!($mode === 'manual' && $runtime->allowExpensiveFallback && $allowManualWhenOpen)) {
                return new ClaudeFallbackDecision(false, 'circuit_breaker_open');
            }
        }

        if (!$dailyBudgetRemaining && !($mode === 'manual' && $runtime->allowExpensiveFallback)) {
            return new ClaudeFallbackDecision(false, 'daily_budget_exhausted');
        }

        $accepted = $deterministicOutcome['accepted'] ?? [];
        $confirmation = $deterministicOutcome['confirmation'] ?? [];
        if ($this->hasConfirmedAccepted($accepted)) {
            return new ClaudeFallbackDecision(false, 'already_confirmed');
        }

        if ($hasStrongConfirmation || $this->hasEvidenceConfirmation($confirmation)) {
            return new ClaudeFallbackDecision(false, 'has_confirmation_candidates');
        }

        if ($mode === 'manual') {
            return new ClaudeFallbackDecision(true, 'manual_deep_search');
        }

        if ($mode === 'always') {
            return new ClaudeFallbackDecision(true, 'legacy_always');
        }

        // conditional
        if ($plan->searchMode === 'no_web_search' || $plan->productType === 'homemade_food') {
            return new ClaudeFallbackDecision(false, 'homemade_or_no_web');
        }

        if (!$this->looksLikeSpecificProduct($subject, $plan)) {
            return new ClaudeFallbackDecision(false, 'not_specific_product');
        }

        $raw = mb_strtolower(trim($subject->rawInput));
        foreach (['自炊', '手作り', '定食', '炒め', '味噌汁'] as $marker) {
            if (str_contains($raw, mb_strtolower($marker)) && ($subject->brandName === null || $subject->brandName === '')) {
                return new ClaudeFallbackDecision(false, 'generic_or_homemade_dish');
            }
        }

        return new ClaudeFallbackDecision(true, 'conditional_all_checks_passed');
    }

    /**
     * @param list<array<string, mixed>> $accepted
     */
    private function hasConfirmedAccepted(array $accepted): bool
    {
        foreach ($accepted as $candidate) {
            if ((int) ($candidate['kcal'] ?? 0) <= 0) {
                continue;
            }
            if (($candidate['identity_confidence'] ?? '') === 'high') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $confirmation
     */
    private function hasEvidenceConfirmation(array $confirmation): bool
    {
        foreach ($confirmation as $candidate) {
            if ((int) ($candidate['kcal'] ?? 0) <= 0) {
                continue;
            }
            $score = (float) ($candidate['match_score'] ?? 0);
            $normalizedScore = $score > 1.0 ? $score / 100.0 : $score;
            $nameSim = (float) (($candidate['match_reasons']['name_similarity'] ?? 0));
            if ($normalizedScore >= 0.72 || $nameSim >= 0.72) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSpecificProduct(FoodSearchSubject $subject, FoodWebSearchPlan $plan): bool
    {
        if ($subject->brandName !== null && trim($subject->brandName) !== '') {
            return mb_strlen(trim($subject->productName)) >= 4;
        }

        $name = trim($subject->productName !== '' ? $subject->productName : $subject->rawInput);

        return mb_strlen($name) >= 6 && $plan->searchMode === 'single_product';
    }
}
