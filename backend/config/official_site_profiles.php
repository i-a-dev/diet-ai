<?php

declare(strict_types=1);

/**
 * サイト別公式探索 Profile。
 *
 * 個別商品名・商品ID・カロリーは含めない（サイト構造のみ）。
 *
 * @return list<array<string, mixed>>
 */
return [
    [
        'domain' => 'nosh.jp',
        'brandAliases' => ['ナッシュ', 'nosh'],
        'seedPaths' => ['/menu'],
        'allowedPathPatterns' => ['/menu/...'],
        'detailPathPatterns' => ['/menu/detail/{numeric-id}'],
        'enabledStrategies' => [
            'robots_sitemap',
            'sitemap',
            'listing_page',
            'structured_data',
            'embedded_json',
        ],
        'maxListingPages' => 2,
        'maxSitemaps' => 2,
        'maxCandidateUrls' => 50,
        'crawlDepth' => 1,
        'profileVersion' => 1,
    ],
    [
        'domain' => 'mcdonalds.co.jp',
        'brandAliases' => ['マクドナルド', 'マック'],
        'seedPaths' => ['/', '/product'],
        'allowedPathPatterns' => ['/product/...', '/menu/...'],
        'detailPathPatterns' => ['/product/{slug}', '/menu/{slug}'],
        'enabledStrategies' => [
            'robots_sitemap',
            'sitemap',
            'listing_page',
            'structured_data',
            'search_engine',
        ],
        'profileVersion' => 1,
    ],
];
