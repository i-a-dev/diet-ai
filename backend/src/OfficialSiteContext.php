<?php

declare(strict_types=1);

/**
 * 探索対象の公式サイト文脈。
 */
final readonly class OfficialSiteContext
{
    public function __construct(
        public OfficialSiteProfile $profile,
        public string $profileSource = 'registered',
    ) {
    }

    public function domain(): string
    {
        return $this->profile->domain;
    }
}
