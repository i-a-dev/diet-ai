<?php

declare(strict_types=1);

/**
 * 検索対象の正規化結果（raw / brand / product を分離して保持する）。
 */
final readonly class FoodSearchSubject
{
    /**
     * @param list<string> $productTokens
     */
    public function __construct(
        public string $rawInput,
        public ?string $brandName,
        public string $productName,
        public array $productTokens,
    ) {
    }
}
