<?php

declare(strict_types=1);

/**
 * Claude フォールバック可否の決定結果。
 */
final readonly class ClaudeFallbackDecision
{
    public function __construct(
        public bool $shouldRun,
        public string $reason,
    ) {
    }
}
