<?php

declare(strict_types=1);

/**
 * 公式サイトから商品詳細 URL を発見する提供者。
 */
interface OfficialCatalogProvider
{
    public function supports(string $officialDomain, ?string $brandName): bool;

    /**
     * @return list<OfficialCatalogCandidate>
     */
    public function discover(FoodSearchSubject $subject, WebSearchBudget $budget): array;
}
