<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/FoodVariantAnalyzer.php';
require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/OfficialSiteBrandResolver.php';
require_once __DIR__ . '/../src/ProductMatchResult.php';
require_once __DIR__ . '/../src/ProductMatchEvaluator.php';
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

function assertNotSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) {
        throw new RuntimeException('FAIL: ' . $message . ' (should not be ' . var_export($expected, true) . ')');
    }
}

echo "Product match evaluator tests\n";
echo str_repeat('=', 48) . "\n";

$evaluator = new ProductMatchEvaluator();

// Case 1: ナッシュ チンジャオロース
$case1 = $evaluator->evaluate([
    'queryProductName' => 'ナッシュ チンジャオロース',
    'queryBrandName' => null,
    'candidateProductName' => 'チンジャオロース',
    'evidenceText' => 'チンジャオロース 275kcal',
    'pageTitle' => 'チンジャオロース｜【nosh-ナッシュ】',
    'url' => 'https://nosh.jp/menu/detail/305',
    'sourceType' => 'html_single_product',
]);
assertSame(ProductMatchResult::DECISION_ACCEPTED, $case1->decision, 'case1 nash chinjao accepted');
echo "OK case1 nash chinjao => accepted score={$case1->score}\n";
echo json_encode($case1->reasons, JSON_UNESCAPED_UNICODE) . "\n";

// Case 2: unknown brand + spelling variant
$case2 = $evaluator->evaluate([
    'queryProductName' => 'サンプルデリ 炭火香る塩麹チキン',
    'queryBrandName' => null,
    'candidateProductName' => '炭火香る塩こうじチキン',
    'evidenceText' => '炭火香る塩こうじチキン 420kcal',
    'pageTitle' => '炭火香る塩こうじチキン｜サンプルデリ',
    'url' => 'https://example.com/item/1',
    'sourceType' => 'html_single_product',
]);
assertNotSame(ProductMatchResult::DECISION_REJECTED, $case2->decision, 'case2 unknown product not rejected');
echo "OK case2 unknown spelling => {$case2->decision} score={$case2->score}\n";

// Case 3: clearly different product
$case3 = $evaluator->evaluate([
    'queryProductName' => 'ナッシュ チンジャオロース',
    'queryBrandName' => 'ナッシュ',
    'candidateProductName' => 'クリームソースハンバーグ',
    'evidenceText' => 'クリームソースハンバーグ 355kcal',
    'pageTitle' => 'クリームソースハンバーグ｜【nosh-ナッシュ】',
    'url' => 'https://nosh.jp/menu/detail/999',
    'sourceType' => 'html_single_product',
]);
assertSame(ProductMatchResult::DECISION_REJECTED, $case3->decision, 'case3 different product rejected');
echo "OK case3 different product => rejected\n";

// Case 4: ambiguous similar product
$case4 = $evaluator->evaluate([
    'queryProductName' => '濃厚デミグラスハンバーグ',
    'queryBrandName' => null,
    'candidateProductName' => 'デミグラスソースのハンバーグ',
    'evidenceText' => 'デミグラスソースのハンバーグ 480kcal',
    'pageTitle' => 'デミグラスソースのハンバーグ',
    'url' => 'https://example.com/food/demi',
    'sourceType' => 'html_single_product',
]);
assertSame(
    ProductMatchResult::DECISION_NEEDS_CONFIRMATION,
    $case4->decision,
    'case4 ambiguous demi needs confirmation',
);
echo "OK case4 ambiguous => needs_confirmation score={$case4->score}\n";

// Case 4b: same category different flavor (no food-suffix dictionary)
$case4b = $evaluator->evaluate([
    'queryProductName' => 'トマトチーズハンバーグ',
    'queryBrandName' => null,
    'candidateProductName' => 'トマトデミハンバーグ',
    'evidenceText' => 'トマトデミハンバーグ 355kcal',
    'pageTitle' => 'トマトデミハンバーグ',
    'url' => 'https://example.com/food/tomato-demi',
    'sourceType' => 'html_single_product',
]);
assertNotSame(ProductMatchResult::DECISION_ACCEPTED, $case4b->decision, 'case4b tomato variants not accepted');
assertTrue(
    in_array($case4b->decision, [
        ProductMatchResult::DECISION_NEEDS_CONFIRMATION,
        ProductMatchResult::DECISION_REJECTED,
    ], true),
    'case4b tomato variants confirm or reject',
);
echo "OK case4b tomato variants => {$case4b->decision}\n";

// Case 4c: unknown category グラタン (no suffix dictionary)
$case4c = $evaluator->evaluate([
    'queryProductName' => '海老とほうれん草のグラタン',
    'queryBrandName' => null,
    'candidateProductName' => 'えびとほうれん草グラタン',
    'evidenceText' => 'えびとほうれん草グラタン 520kcal',
    'pageTitle' => 'えびとほうれん草グラタン',
    'url' => 'https://example.com/food/gratin',
    'sourceType' => 'html_single_product',
]);
assertNotSame(ProductMatchResult::DECISION_REJECTED, $case4c->decision, 'case4c gratin not rejected');
echo "OK case4c gratin => {$case4c->decision} score={$case4c->score}\n";

// Case 5: list page wrong row
$case5 = $evaluator->evaluate([
    'queryProductName' => 'ナッシュ チンジャオロース',
    'queryBrandName' => 'ナッシュ',
    'candidateProductName' => 'クリームソースハンバーグ',
    'evidenceText' => 'クリームソースハンバーグ 355kcal',
    'pageTitle' => 'メニュー一覧｜ナッシュ',
    'url' => 'https://nosh.jp/menu',
    'sourceType' => 'html_table',
]);
assertSame(ProductMatchResult::DECISION_REJECTED, $case5->decision, 'case5 list page wrong row rejected');
echo "OK case5 list page mismatch => rejected\n";

// Case 6: chinjao vs cream hamburger
$case6 = $evaluator->evaluate([
    'queryProductName' => 'チンジャオロース',
    'queryBrandName' => null,
    'candidateProductName' => 'クリームソースハンバーグ',
    'evidenceText' => 'クリームソースハンバーグ 355kcal',
    'pageTitle' => 'クリームソースハンバーグ',
    'url' => 'https://example.com/food/cream',
    'sourceType' => 'html_single_product',
]);
assertSame(ProductMatchResult::DECISION_REJECTED, $case6->decision, 'case6 chinjao vs cream rejected');
echo "OK case6 chinjao vs cream => rejected\n";

// HTML extraction path for case1
$extractor = new NutritionPageVariantExtractor();
$noshHtml = <<<'HTML'
<html>
<head><title>チンジャオロース｜【nosh-ナッシュ】</title></head>
<body>
<h1>チンジャオロース</h1>
<div>
<h3 class="pg-menu-detail-table__title">カロリー</h3>
<p class="pg-menu-detail-table__text">275kcal</p>
</div>
</body>
</html>
HTML;
$extracted = $extractor->extractFromHtml(
    $noshHtml,
    'ナッシュ チンジャオロース',
    null,
    'none',
    [],
    'https://nosh.jp/menu/detail/305',
);
assertTrue(count($extracted) === 1, 'extractor keeps nash chinjao candidate');
assertSame(275, $extracted[0]['kcal'] ?? null, 'extractor kcal');
assertSame(ProductMatchResult::DECISION_ACCEPTED, $extracted[0]['matchDecision'] ?? null, 'extractor decision accepted');
echo "OK extractor path for nash chinjao\n";

$wrongHtml = <<<'HTML'
<html>
<head><title>クリームソースハンバーグ｜【nosh-ナッシュ】</title></head>
<body>
<h1>クリームソースハンバーグ</h1>
<div>
<h3 class="pg-menu-detail-table__title">カロリー</h3>
<p class="pg-menu-detail-table__text">355kcal</p>
</div>
</body>
</html>
HTML;
$wrong = $extractor->extractFromHtml(
    $wrongHtml,
    'ナッシュ チンジャオロース',
    null,
    'none',
    [],
    'https://nosh.jp/menu/detail/999',
);
assertTrue($wrong === [], 'extractor rejects clearly different product');
echo "OK extractor rejects different product\n";

$listHtml = <<<'HTML'
<html>
<head><title>メニューカロリー一覧</title></head>
<body>
<table>
<tr><td>チンジャオロース</td><td>275 kcal</td></tr>
<tr><td>クリームソースハンバーグ</td><td>355 kcal</td></tr>
</table>
</body>
</html>
HTML;
$list = $extractor->extractFromHtml(
    $listHtml,
    'ナッシュ チンジャオロース',
    'ナッシュ',
    'named_size',
    ['通常'],
    'https://example.com/menu/calories',
);
foreach ($list as $item) {
    assertTrue(
        !str_contains((string) ($item['productName'] ?? ''), 'クリーム'),
        'list extraction must not keep wrong product row',
    );
}
echo "OK list page does not keep wrong kcal row\n";

echo str_repeat('=', 48) . "\n";
echo "All product match tests passed\n";
