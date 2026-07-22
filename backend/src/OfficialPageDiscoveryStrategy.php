<?php

declare(strict_types=1);

/**
 * 公式ページ探索 Strategy。
 */
interface OfficialPageDiscoveryStrategy
{
    public function name(): string;

    public function supports(OfficialSiteContext $site, DiscoveryEnvironment $environment): bool;

    /**
     * @return list<DiscoveredPageCandidate>
     */
    public function discover(
        FoodSearchSubject $subject,
        OfficialSiteContext $site,
        OfficialDiscoveryBudget $budget,
        DiscoveryEnvironment $environment,
    ): array;
}
