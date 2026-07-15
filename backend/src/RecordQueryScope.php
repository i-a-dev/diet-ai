<?php

declare(strict_types=1);

/**
 * ユーザー質問から解決された記録参照期間。
 * Carbon は未使用のため DateTimeImmutable（Asia/Tokyo）で保持する。
 */
final readonly class RecordQueryScope
{
    public function __construct(
        public RecordScopeType $type,
        public DateTimeImmutable $startDate,
        public DateTimeImmutable $endDate,
        public string $originalExpression,
        public string $timezone = 'Asia/Tokyo',
    ) {
        if ($this->startDate > $this->endDate) {
            throw new InvalidArgumentException('startDate must be on or before endDate');
        }
    }

    public function startDateString(): string
    {
        return $this->startDate->format('Y-m-d');
    }

    public function endDateString(): string
    {
        return $this->endDate->format('Y-m-d');
    }

    /**
     * @return array{
     *   scope_type: string,
     *   start_date: string,
     *   end_date: string,
     *   timezone: string,
     *   original_expression: string
     * }
     */
    public function toArray(): array
    {
        return [
            'scope_type' => $this->type->value,
            'start_date' => $this->startDateString(),
            'end_date' => $this->endDateString(),
            'timezone' => $this->timezone,
            'original_expression' => $this->originalExpression,
        ];
    }
}
