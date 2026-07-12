<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/FoodWebSearchPlan.php';
require_once __DIR__ . '/../src/FoodWebSearchPlanInputGuard.php';
require_once __DIR__ . '/../src/FoodVariantAnalyzer.php';
require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/NutritionSearchQueryBuilder.php';
require_once __DIR__ . '/../src/WebSearchBudget.php';
require_once __DIR__ . '/../src/NutritionPageVariantExtractor.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'FAIL: %s (expected %s, got %s)',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

echo "AI Web Search unit tests\n";
echo str_repeat('=', 48) . "\n";

$builder = new NutritionSearchQueryBuilder();
$analyzer = new FoodVariantAnalyzer();

$macPlan = FoodWebSearchPlan::fromArray([
    'isFood' => true,
    'normalizedProductName' => 'マックフライポテト',
    'brandName' => 'マクドナルド',
    'productType' => 'restaurant_menu',
    'variantAnalysis' => [
        'likelyHasVariants' => true,
        'dimension' => 'named_size',
        'expectedLabels' => ['S', 'M', 'L'],
        'confidence' => 'high',
    ],
    'searchMode' => 'variant_list_page',
    'queryTerms' => ['栄養成分', 'サイズ', 'カロリー'],
]);
$macQueries = $builder->build($macPlan);
assertTrue(count($macQueries) <= 2, 'mac queries max 2');
assertTrue(count($macQueries) >= 1, 'mac queries at least 1');
assertTrue(str_contains($macQueries[0], 'マックフライポテト'), 'mac query contains product');
echo "OK mac plan queries\n";

$sukiyaPlan = FoodWebSearchPlan::fromArray([
    'isFood' => true,
    'normalizedProductName' => '牛丼',
    'brandName' => 'すき家',
    'productType' => 'restaurant_menu',
    'variantAnalysis' => [
        'likelyHasVariants' => true,
        'dimension' => 'serving_size',
        'expectedLabels' => ['並盛', '大盛', '特盛'],
        'confidence' => 'high',
    ],
    'searchMode' => 'variant_list_page',
    'queryTerms' => ['栄養成分', 'サイズ一覧', 'カロリー'],
]);
$sukiyaQueries = $builder->build($sukiyaPlan);
assertTrue(str_contains($sukiyaQueries[0], '牛丼'), 'sukiya query contains product');
echo "OK sukiya plan queries\n";

$wasabeefPlan = FoodWebSearchPlan::fromArray([
    'isFood' => true,
    'normalizedProductName' => 'わさビーフ',
    'brandName' => '山芳製菓',
    'productType' => 'packaged_food',
    'variantAnalysis' => [
        'likelyHasVariants' => true,
        'dimension' => 'weight',
        'expectedLabels' => [],
        'confidence' => 'medium',
    ],
    'searchMode' => 'product_list_page',
    'queryTerms' => ['商品情報', '内容量', '栄養成分'],
]);
$wasabeefQueries = $builder->build($wasabeefPlan);
assertTrue(str_contains($wasabeefQueries[0], '商品情報') || str_contains($wasabeefQueries[0], '内容量'), 'wasabeef product list query');
echo "OK wasabeef plan queries\n";

$planGuard = new FoodWebSearchPlanInputGuard();
$inventedBrandPlan = FoodWebSearchPlan::fromArray([
    'isFood' => true,
    'normalizedProductName' => 'わさビーフ',
    'brandName' => 'ビーフ（味わい工房等）',
    'productType' => 'packaged_food',
    'variantAnalysis' => [
        'likelyHasVariants' => true,
        'dimension' => 'weight',
        'expectedLabels' => ['40g'],
        'confidence' => 'medium',
    ],
    'searchMode' => 'variant_list_page',
    'queryTerms' => ['わさビーフ', 'わさび味', 'ビーフジャーキー', 'カロリー'],
]);
$sanitizedWasabeef = $planGuard->apply('わさビーフ', $inventedBrandPlan);
assertSame(null, $sanitizedWasabeef->brandName, 'invented brand removed when not in input');
$wasabeefGuardedQueries = $builder->build($sanitizedWasabeef);
assertTrue(!str_contains($wasabeefGuardedQueries[0], 'ビーフ（'), 'brave query excludes invented brand prefix');
assertTrue(!str_contains($wasabeefGuardedQueries[0], 'ビーフジャーキー'), 'brave query excludes invented query term');
assertTrue(str_contains($wasabeefGuardedQueries[0], 'わさビーフ'), 'brave query keeps product name');
echo "OK invented brand stripped from plan and queries\n";

$macInputPlan = FoodWebSearchPlan::fromArray([
    'isFood' => true,
    'normalizedProductName' => 'フライドポテト',
    'brandName' => 'マクドナルド',
    'productType' => 'restaurant_menu',
    'variantAnalysis' => [
        'likelyHasVariants' => true,
        'dimension' => 'named_size',
        'expectedLabels' => ['S', 'M', 'L'],
        'confidence' => 'high',
    ],
    'searchMode' => 'variant_list_page',
    'queryTerms' => ['栄養成分', 'サイズ', 'カロリー'],
]);
$sanitizedMac = $planGuard->apply('フライドポテト マック', $macInputPlan);
assertSame('マクドナルド', $sanitizedMac->brandName, 'brand kept when alias appears in input');
echo "OK explicit brand kept for mac input\n";

$nashPlan = FoodWebSearchPlan::fromArray([
    'isFood' => true,
    'normalizedProductName' => 'おろしハンバーグ',
    'brandName' => null,
    'productType' => 'prepared_food',
    'variantAnalysis' => [
        'likelyHasVariants' => false,
        'dimension' => 'none',
        'expectedLabels' => [],
        'confidence' => 'high',
    ],
    'searchMode' => 'single_product',
    'queryTerms' => ['カロリー', '栄養成分'],
]);
$nashQueries = $builder->build($nashPlan, 'ナッシュ おろしハンバーグ');
assertTrue(str_contains($nashQueries[0], 'ナッシュ'), 'nash query keeps user token when brand is null');
assertTrue(str_contains($nashQueries[0], 'おろしハンバーグ'), 'nash query keeps normalized product');
assertSame('ナッシュ おろしハンバーグ', $builder->resolveSearchName($nashPlan, 'ナッシュ おろしハンバーグ'), 'search name preserves user words');
echo "OK user input tokens preserved in brave query\n";

$homePlan = FoodWebSearchPlan::fromArray([
    'isFood' => true,
    'normalizedProductName' => '鶏むね肉とキャベツの炒め物',
    'brandName' => null,
    'productType' => 'homemade_food',
    'variantAnalysis' => [
        'likelyHasVariants' => false,
        'dimension' => 'none',
        'expectedLabels' => [],
        'confidence' => 'high',
    ],
    'searchMode' => 'no_web_search',
    'queryTerms' => [],
]);
assertSame([], $builder->build($homePlan), 'homemade no queries');
echo "OK homemade no_web_search\n";

$fallback = FoodWebSearchPlan::fallbackFromInput('フライドポテト マック', $analyzer);
assertSame('single_product', $fallback->searchMode, 'fallback does not use fixed L/M/S mode');
$fallbackQueries = $builder->build($fallback);
assertTrue(count($fallbackQueries) <= 2, 'fallback max 2 queries');
echo "OK fallback plan\n";

$budget = new WebSearchBudget();
assertTrue($budget->shouldExecuteBraveQuery('query a'), 'budget allows first query');
$budget->recordBraveSearch('query a');
assertTrue(!$budget->shouldExecuteBraveQuery('query a'), 'budget dedupes same query');
$budget->recordBraveSearch('query b');
$budget->recordBraveSearch('query c');
$budget->recordBraveSearch('query d');
assertTrue(!$budget->canBraveSearch(), 'budget max 4 brave');
echo "OK search budget\n";

$extractor = new NutritionPageVariantExtractor();
$smlTable = <<<'HTML'
<table>
<tr><td>マックフライポテト S</td><td>224 kcal</td></tr>
<tr><td>マックフライポテト M</td><td>410 kcal</td></tr>
<tr><td>マックフライポテト L</td><td>515 kcal</td></tr>
</table>
HTML;
$sml = $extractor->extractFromHtml($smlTable, 'マックフライポテト', 'マクドナルド', 'named_size', ['S', 'M', 'L']);
assertTrue(count($sml) >= 2, 'SML table extracts multiple variants');
echo "OK SML table extraction\n";

$servingTable = <<<'HTML'
<table>
<tr><td>牛丼 ミニ</td><td>496 kcal</td></tr>
<tr><td>牛丼 並盛</td><td>733 kcal</td></tr>
<tr><td>牛丼 大盛</td><td>966 kcal</td></tr>
</table>
HTML;
$serving = $extractor->extractFromHtml($servingTable, '牛丼', 'すき家', 'serving_size', ['並盛', '大盛']);
assertTrue(count($serving) >= 2, 'serving table extracts variants');
$miniFound = false;
foreach ($serving as $row) {
    if (($row['variantLabel'] ?? '') === 'ミニ') {
        $miniFound = true;
    }
}
assertTrue($miniFound, 'unexpected mini variant extracted');
echo "OK serving table extraction\n";

$weightTable = <<<'HTML'
<table>
<tr><td>わさビーフ 1袋(55g)あたり</td><td>250 kcal</td></tr>
<tr><td>わさビーフ 1袋(75g)あたり</td><td>340 kcal</td></tr>
</table>
HTML;
$weight = $extractor->extractFromHtml($weightTable, 'わさビーフ', '山芳製菓', 'weight', []);
assertTrue(count($weight) >= 2, 'weight table extracts content amounts');
echo "OK weight table extraction\n";

$fatsecretHtml = <<<'HTML'
<table>
<tr><td>わさビーフ&nbsp;&nbsp;(山芳製菓) 1袋(55g)あたり - カロリー: 250kcal | 脂質: 13.70g | 炭水化物: 28.00g | たんぱく質: 3.50g</td></tr>
<tr><td>男気わさビーフ&nbsp;&nbsp;(山芳製菓) 1袋(50g)あたり - カロリー: 284kcal | 脂質: 9.84g | 炭水化物: 31.00g</td></tr>
<tr><td>わさビーフ ザクうまいか天&nbsp;&nbsp;(山芳製菓) 1袋(22g)あたり - カロリー: 110kcal | 脂質: 5.50g</td></tr>
</table>
HTML;
$fatsecret = $extractor->extractFromHtml($fatsecretHtml, 'わさビーフ', '山芳製菓', 'weight', ['55g', '75g']);
assertTrue(count($fatsecret) === 3, 'fatsecret-like table keeps valid content amounts only');
$labels = array_map(fn (array $row): string => ($row['productName'] ?? '') . '|' . ($row['variantLabel'] ?? ''), $fatsecret);
assertTrue(in_array('わさビーフ|55g', $labels, true), 'wasabeef 55g kept');
assertTrue(in_array('男気わさビーフ|50g', $labels, true), 'danji wasabeef 50g kept with product name');
foreach ($fatsecret as $row) {
    assertTrue(!str_contains((string) ($row['variantLabel'] ?? ''), '.'), 'no decimal nutrition grams as variant');
}
echo "OK fatsecret-like table excludes nutrition grams\n";

$officialPageHtml = <<<'HTML'
<html>
<head><title>ポテトチップス わさビーフ | 商品情報 | 山芳製菓株式会社</title></head>
<body>
<h1>ポテトチップス わさビーフ</h1>
<p>ポテトチップス わさビーフ 50g／1袋 標準小売価格 160円</p>
<p>エネルギー 284kcal</p>
</body>
</html>
HTML;
$official = $extractor->extractFromHtml(
    $officialPageHtml,
    'わさビーフ',
    '山芳製菓',
    'weight',
    [],
    'https://www.8044.jp/item/226/',
);
assertTrue(count($official) === 1, 'official single product page extracts one candidate');
assertSame('ポテトチップス わさビーフ', $official[0]['productName'] ?? null, 'official product name');
assertSame('50g', $official[0]['variantLabel'] ?? null, 'official package size');
assertSame(284, $official[0]['kcal'] ?? null, 'official kcal');
echo "OK official single product page extraction\n";

$noshPageHtml = <<<'HTML'
<html>
<head><title>和風おろしハンバーグ｜【nosh-ナッシュ】</title></head>
<body>
<h1>和風おろしハンバーグ</h1>
<div>
<h3 class="pg-menu-detail-table__title">カロリー</h3>
<p class="pg-menu-detail-table__text">321kcal</p>
</div>
</body>
</html>
HTML;
$nosh = $extractor->extractFromHtml(
    $noshPageHtml,
    'おろしハンバーグ',
    null,
    'none',
    [],
    'https://nosh.jp/menu/detail/469',
);
assertTrue(count($nosh) === 1, 'nosh menu page extracts one candidate');
assertSame(321, $nosh[0]['kcal'] ?? null, 'nosh kcal');
assertSame('和風おろしハンバーグ', $nosh[0]['productName'] ?? null, 'nosh product name');
assertSame('ナッシュ', $nosh[0]['brandName'] ?? null, 'nosh brand from html title');
echo "OK nosh menu page extraction\n";

$pageExtractor = new NutritionPageExtractor();
assertSame('ナッシュ', $pageExtractor->extractBrandFromPageHtml($noshPageHtml), 'html title brand extraction');
$single = $pageExtractor->extractSingleProductCandidate(
    $noshPageHtml,
    'おろしハンバーグ',
    'https://nosh.jp/menu/detail/469',
);
assertTrue(is_array($single), 'single product candidate extracted');
assertSame('和風おろしハンバーグ', $single['productName'] ?? null, 'single product html name');
assertSame('ナッシュ', $single['brandName'] ?? null, 'single product html brand');
assertSame(321, $single['kcal'] ?? null, 'single product html kcal');
echo "OK html title brand and product extraction\n";

require_once __DIR__ . '/../src/OfficialSiteBrandResolver.php';
$officialBrandResolver = new OfficialSiteBrandResolver();
assertSame('ナッシュ', $officialBrandResolver->resolveFromUrl('https://nosh.jp/menu/detail/469'), 'nosh.jp resolves to ナッシュ');
assertSame(
    'ナッシュ',
    $officialBrandResolver->resolveFromUrl('https://example.com/item', '和風おろしハンバーグ｜【nosh-ナッシュ】'),
    'nosh title marker resolves to ナッシュ',
);
assertSame(null, $officialBrandResolver->resolveFromUrl('https://example.com/item'), 'unknown host has no brand');
echo "OK official site brand resolver\n";

echo str_repeat('=', 48) . "\n";
echo "All tests passed\n";
