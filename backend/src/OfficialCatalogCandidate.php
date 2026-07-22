<?php

declare(strict_types=1);

/**
 * 公式カタログ探索で得た商品候補。
 */
final class OfficialCatalogCandidate
{
    public function __construct(
        public readonly string $url,
        public readonly string $productName,
        public readonly ?string $brandName = null,
        public readonly ?int $kcal = null,
        public readonly string $source = 'official_catalog',
    ) {
    }

    /**
     * @return array{title: string, url: string, description: string, extra_snippets?: list<string>}
     */
    public function toSearchResult(): array
    {
        $descriptionParts = [];
        if ($this->kcal !== null && $this->kcal > 0) {
            $descriptionParts[] = $this->kcal . 'kcal';
        }
        $descriptionParts[] = 'official_catalog';

        return [
            'title' => $this->productName,
            'url' => $this->url,
            'description' => implode(' ', $descriptionParts),
            'extra_snippets' => $descriptionParts,
        ];
    }
}
