<?php

declare(strict_types=1);

/**
 * Claude Web Search の日次予算・サーキットブレーカー。
 */
final class ClaudeWebSearchGuard
{
    public function __construct(
        private readonly string $stateDir = '',
    ) {
    }

    private function dir(): string
    {
        if ($this->stateDir !== '') {
            return $this->stateDir;
        }

        return dirname(__DIR__) . '/data/claude_web_search_guard';
    }

    private function statePath(): string
    {
        return rtrim($this->dir(), '/') . '/state_' . gmdate('Ymd') . '.json';
    }

    /**
     * @return array{requests: int, failures: int, confirmed: int, estimated_cost_usd: float}
     */
    private function load(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return ['requests' => 0, 'failures' => 0, 'confirmed' => 0, 'estimated_cost_usd' => 0.0];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded)) {
            return ['requests' => 0, 'failures' => 0, 'confirmed' => 0, 'estimated_cost_usd' => 0.0];
        }

        return [
            'requests' => (int) ($decoded['requests'] ?? 0),
            'failures' => (int) ($decoded['failures'] ?? 0),
            'confirmed' => (int) ($decoded['confirmed'] ?? 0),
            'estimated_cost_usd' => (float) ($decoded['estimated_cost_usd'] ?? 0),
        ];
    }

    /**
     * @param array{requests: int, failures: int, confirmed: int, estimated_cost_usd: float} $state
     */
    private function save(array $state): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($this->statePath(), json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    public function hasDailyBudgetRemaining(): bool
    {
        $state = $this->load();
        $maxReq = (int) (getenv('CLAUDE_WEB_SEARCH_MAX_REQUESTS_PER_DAY') ?: 50);
        $maxUsd = (float) (getenv('CLAUDE_WEB_SEARCH_DAILY_BUDGET_USD') ?: 5);

        return $state['requests'] < max(0, $maxReq)
            && $state['estimated_cost_usd'] < max(0.0, $maxUsd);
    }

    public function isCircuitOpen(): bool
    {
        $state = $this->load();
        $minSamples = (int) (getenv('CLAUDE_WEB_SEARCH_CIRCUIT_BREAKER_MIN_SAMPLES') ?: 5);
        $failureRate = (float) (getenv('CLAUDE_WEB_SEARCH_CIRCUIT_BREAKER_FAILURE_RATE') ?: 0.8);
        if ($state['requests'] < max(1, $minSamples)) {
            return false;
        }
        $rate = $state['failures'] / max(1, $state['requests']);

        return $rate >= $failureRate;
    }

    public function recordAttempt(bool $confirmed, float $estimatedCostUsd = 0.0): void
    {
        $state = $this->load();
        $state['requests']++;
        $state['estimated_cost_usd'] += max(0.0, $estimatedCostUsd);
        if ($confirmed) {
            $state['confirmed']++;
        } else {
            $state['failures']++;
        }
        $this->save($state);
    }

    /**
     * @return array<string, mixed>
     */
    public function metricsSnapshot(): array
    {
        $state = $this->load();
        $attempts = max(1, $state['requests']);

        return [
            'claude_attempts' => $state['requests'],
            'claude_confirmed' => $state['confirmed'],
            'claude_not_found' => $state['failures'],
            'claude_rescue_rate' => $state['confirmed'] / $attempts,
            'claude_estimated_cost_usd' => $state['estimated_cost_usd'],
            'claude_cost_per_rescued_result' => $state['confirmed'] > 0
                ? $state['estimated_cost_usd'] / $state['confirmed']
                : null,
            'circuit_open' => $this->isCircuitOpen(),
        ];
    }
}
