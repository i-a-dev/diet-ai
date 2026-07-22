<?php

declare(strict_types=1);

/**
 * AI Web 検索のステージ別・HTTP単位計測。
 */
final class SearchTiming
{
    private float $startedAt;

    /** @var array<string, float> */
    private array $stageMs = [];

    /** @var list<array<string, mixed>> */
    private array $httpEvents = [];

    public function __construct()
    {
        $this->startedAt = hrtime(true) / 1e6;
    }

    public function measure(string $stage, callable $fn): mixed
    {
        $t0 = hrtime(true);
        try {
            return $fn();
        } finally {
            $elapsed = (hrtime(true) - $t0) / 1e6;
            $this->stageMs[$stage] = ($this->stageMs[$stage] ?? 0.0) + $elapsed;
        }
    }

    public function addStageMs(string $stage, float $ms): void
    {
        $this->stageMs[$stage] = ($this->stageMs[$stage] ?? 0.0) + max(0.0, $ms);
    }

    /**
     * @param array{
     *   request_type: string,
     *   summary: string,
     *   duration_ms: float|int,
     *   http_status?: int|null,
     *   response_size?: int|null,
     *   timeout?: bool,
     *   cache_hit?: bool
     * } $event
     */
    public function recordHttp(array $event): void
    {
        $this->httpEvents[] = [
            'request_type' => (string) ($event['request_type'] ?? ''),
            'summary' => mb_substr((string) ($event['summary'] ?? ''), 0, 160),
            'started_at' => gmdate('c'),
            'duration_ms' => (int) round((float) ($event['duration_ms'] ?? 0)),
            'http_status' => $event['http_status'] ?? null,
            'response_size' => $event['response_size'] ?? null,
            'timeout' => (bool) ($event['timeout'] ?? false),
            'cache_hit' => (bool) ($event['cache_hit'] ?? false),
        ];
    }

    public function totalMs(): float
    {
        return (hrtime(true) / 1e6) - $this->startedAt;
    }

    public function remainingDeadlineMs(int $deadlineMs): float
    {
        return max(0.0, $deadlineMs - $this->totalMs());
    }

    public function hasDeadlineRemaining(int $deadlineMs, int $minRemainingMs = 200): bool
    {
        return $this->remainingDeadlineMs($deadlineMs) >= $minRemainingMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stages = [];
        foreach ($this->stageMs as $k => $v) {
            $stages[$k] = (int) round($v);
        }
        $stages['total_ms'] = (int) round($this->totalMs());

        return [
            'stages' => $stages,
            'http_events' => $this->httpEvents,
        ];
    }
}
