<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/OfficialSiteBrandResolver.php';
require_once __DIR__ . '/../src/WebSearchUrlRanker.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

echo "WebSearchUrlRanker unit tests\n";
echo str_repeat('=', 48) . "\n";

$ranker = new WebSearchUrlRanker();

$results = [
    [
        'title' => 'メニュー｜【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/use/menu',
        'description' => 'ナッシュのメニュー一覧',
    ],
    [
        'title' => 'ナッシュで選べるメニューの紹介【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/menu',
        'description' => 'メニュー紹介',
    ],
    [
        'title' => '糖質30g・塩分2.5g以下へのこだわり【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/nutritional-value',
        'description' => '栄養へのこだわり',
    ],
    [
        'title' => 'たらのレモンあん｜【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/menu/detail/1015',
        'description' => 'たらのレモンあんの商品ページ',
    ],
];

$singleRanked = $ranker->rank($results, 'たらのレモンあん', 'ナッシュ', 'single_product');
assertTrue(
    ($singleRanked[0]['url'] ?? '') === 'https://nosh.jp/menu/detail/1015',
    'single_product should rank product detail first',
);
echo "OK single_product prefers detail page\n";

$listRanked = $ranker->rank($results, 'たらのレモンあん', 'ナッシュ', 'variant_list_page');
$listUrls = array_map(static fn (array $row): string => $row['url'], $listRanked);
assertTrue(
    in_array('https://nosh.jp/menu', $listUrls, true),
    'variant_list_page should still keep menu list urls',
);
echo "OK variant_list_page keeps list pages\n";

// 詳細がBrave下位でも、単品モードではTOP3に入ることを確認
$buried = [
    [
        'title' => 'ナッシュで選べるメニューの紹介【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/menu',
        'description' => 'メニュー紹介',
    ],
    [
        'title' => 'メニュー｜【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/use/menu',
        'description' => 'ナッシュのメニュー一覧',
    ],
    [
        'title' => '糖質30g・塩分2.5g以下へのこだわり【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/nutritional-value',
        'description' => '栄養へのこだわり',
    ],
    [
        'title' => 'ぶりの照り焼き｜【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/menu/detail/100',
        'description' => '別商品',
    ],
    [
        'title' => 'たらのレモンあん｜【nosh-ナッシュ】',
        'url' => 'https://nosh.jp/menu/detail/1015',
        'description' => 'たらのレモンあんの商品ページ',
    ],
];
$buriedRanked = $ranker->rank($buried, 'たらのレモンあん', 'ナッシュ', 'single_product');
$top3 = array_slice(array_map(static fn (array $row): string => $row['url'], $buriedRanked), 0, 3);
assertTrue(
    in_array('https://nosh.jp/menu/detail/1015', $top3, true),
    'single_product should keep matching detail inside top 3 HTML budget',
);
assertTrue(
    ($buriedRanked[0]['url'] ?? '') === 'https://nosh.jp/menu/detail/1015',
    'title-matching detail should beat unrelated detail and index pages',
);
echo "OK buried detail rises above index pages in single_product\n";

echo str_repeat('=', 48) . "\n";
echo "All WebSearchUrlRanker tests passed\n";
