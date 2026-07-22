<?php

declare(strict_types=1);

/**
 * 食品 Web 検索の集計メトリクス（ファイルベース）。
 */
final class WebSearchMetricsStore
{
    public function __construct(
        private readonly string $storeDir = '',
    ) {
    }

    private function dir(): string
    {
        if ($this->storeDir !== '') {
            return $this->storeDir;
        }

        return dirname(__DIR__) . '/data/web_search_metrics';
    }

    private function path(): string
    {
        return rtrim($this->dir(), '/') . '/metrics.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return $this->emptyState();
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? array_merge($this->emptyState(), $decoded) : $this->emptyState();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(): array
    {
        return [
            'deterministic_search_attempts' => 0,
            'deterministic_confirmed' => 0,
            'official_discovery_confirmed' => 0,
            'third_party_confirmed' => 0,
            'claude_attempts' => 0,
            'claude_confirmed' => 0,
            'claude_not_found' => 0,
            'claude_cost_usd_total' => 0.0,
            'total_cost_usd' => 0.0,
            'total_ms_samples' => [],
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    public function record(array $event): void
    {
        $state = $this->load();
        if (($event['deterministic_attempt'] ?? false) === true) {
            $state['deterministic_search_attempts']++;
        }
        if (($event['deterministic_confirmed'] ?? false) === true) {
            $state['deterministic_confirmed']++;
            $strategy = (string) ($event['source_strategy'] ?? '');
            if ($strategy === 'official_catalog' || $strategy === 'official_html') {
                $state['official_discovery_confirmed']++;
            } elseif ($strategy === 'third_party_html') {
                $state['third_party_confirmed']++;
            }
        }
        if (($event['claude_attempt'] ?? false) === true) {
            $state['claude_attempts']++;
        }
        if (($event['claude_confirmed'] ?? false) === true) {
            $state['claude_confirmed']++;
        }
        if (($event['claude_not_found'] ?? false) === true) {
            $state['claude_not_found']++;
        }
        $cost = (float) ($event['estimated_cost_usd'] ?? 0);
        if ($cost > 0) {
            $state['total_cost_usd'] += $cost;
            if (($event['claude_attempt'] ?? false) === true) {
                $state['claude_cost_usd_total'] += $cost;
            }
        }
        $totalMs = (int) ($event['total_ms'] ?? 0);
        if ($totalMs > 0) {
            $samples = is_array($state['total_ms_samples'] ?? null) ? $state['total_ms_samples'] : [];
            $samples[] = $totalMs;
            if (count($samples) > 500) {
                $samples = array_slice($samples, -500);
            }
            $state['total_ms_samples'] = $samples;
        }

        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($this->path(), json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $state = $this->load();
        $attempts = max(1, (int) $state['claude_attempts']);
        $samples = is_array($state['total_ms_samples'] ?? null) ? $state['total_ms_samples'] : [];
        sort($samples);
        $detAttempts = max(1, (int) $state['deterministic_search_attempts']);

        return [
            'deterministic_search_attempts' => (int) $state['deterministic_search_attempts'],
            'deterministic_confirmed' => (int) $state['deterministic_confirmed'],
            'official_discovery_confirmed' => (int) $state['official_discovery_confirmed'],
            'third_party_confirmed' => (int) $state['third_party_confirmed'],
            'claude_attempts' => (int) $state['claude_attempts'],
            'claude_confirmed' => (int) $state['claude_confirmed'],
            'claude_not_found' => (int) $state['claude_not_found'],
            'claude_rescue_rate' => ((int) $state['claude_confirmed']) / $attempts,
            'average_cost_per_food_search' => ((float) $state['total_cost_usd']) / $detAttempts,
            'claude_cost_per_rescued_result' => ((int) $state['claude_confirmed']) > 0
                ? ((float) $state['claude_cost_usd_total']) / ((int) $state['claude_confirmed'])
                : null,
            'P50_total_ms' => $this->percentile($samples, 50),
            'P90_total_ms' => $this->percentile($samples, 90),
            'P95_total_ms' => $this->percentile($samples, 95),
        ];
    }

    /**
     * @param list<int|float> $sorted
     */
    private function percentile(array $sorted, int $p): ?int
    {
        if ($sorted === []) {
            return null;
        }
        $idx = (int) floor((count($sorted) - 1) * ($p / 100));

        return (int) round((float) $sorted[$idx]);
    }
}
