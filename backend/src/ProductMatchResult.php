<?php

declare(strict_types=1);

/**
 * 商品名一致判定の結果（3段階）。
 */
final readonly class ProductMatchResult
{
    public const DECISION_ACCEPTED = 'accepted';
    public const DECISION_NEEDS_CONFIRMATION = 'needs_confirmation';
    public const DECISION_REJECTED = 'rejected';

    /**
     * @param array<string, mixed> $reasons
     */
    public function __construct(
        public float $score,
        public string $decision,
        public array $reasons,
    ) {
    }

    public function isAccepted(): bool
    {
        return $this->decision === self::DECISION_ACCEPTED;
    }

    public function needsConfirmation(): bool
    {
        return $this->decision === self::DECISION_NEEDS_CONFIRMATION;
    }

    public function isRejected(): bool
    {
        return $this->decision === self::DECISION_REJECTED;
    }
}
