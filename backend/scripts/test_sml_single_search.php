<?php

declare(strict_types=1);

require_once __DIR__ . '/EnvLoader.php';
require_once __DIR__ . '/BraveSearchClient.php';
require_once __DIR__ . '/../src/FoodVariantAnalyzer.php';
require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/BraveSearchService.php';
require_once __DIR__ . '/../src/BraveNutritionSearchService.php';

load_project_env();

$userInput = $argv[1] ?? 'フライドポテト マック';
$apiKey = trim((string) (getenv('BRAVE_SEARCH_API_KEY') ?: ''));

if ($apiKey === '') {
    fwrite(STDERR, "ERROR: BRAVE_SEARCH_API_KEY が未設定です\n");
    exit(1);
}

$variantAnalyzer = new FoodVariantAnalyzer();
$braveService = new BraveNutritionSearchService();

echo "=== 現行フロー: collectCandidates (S/M/L 個別検索) ===\n";
echo "入力: {$userInput}\n\n";

$inputAnalysis = $variantAnalyzer->analyzeInput($userInput);
echo "base_product_name: {$inputAnalysis['base_product_name']}\n";
echo "variant_risk: {$inputAnalysis['variant_risk']}\n";
echo "shouldExploreVariants: " . ($variantAnalyzer->shouldExploreVariants($inputAnalysis) ? 'yes' : 'no') . "\n";

$queries = $variantAnalyzer->buildVariantSearchQueries($inputAnalysis['base_product_name']);
$queries[] = $inputAnalysis['base_product_name'] . ' カロリー';
$queries = array_values(array_unique($queries));

echo "検索クエリ数: " . count($queries) . "\n";
foreach ($queries as $i => $q) {
    echo "  [" . ($i + 1) . "] {$q}\n";
}

$start = microtime(true);
$candidates = $braveService->collectCandidates($userInput, $userInput, [], $inputAnalysis);
$elapsed = round(microtime(true) - $start, 2);

echo "\n取得候補数: " . count($candidates) . " ({$elapsed}s)\n";
foreach ($candidates as $c) {
    $variant = $c['variant_label'] ?? '?';
    echo "  [{$variant}] {$c['kcal']} kcal — {$c['product_name']}\n";
    echo "    {$c['source_url']}\n";
}

echo "\n" . str_repeat('=', 72) . "\n";
echo "=== 提案フロー: 1回検索 + ページ内 S/M/L 一括抽出 ===\n\n";

$singleQueries = [
    $inputAnalysis['base_product_name'] . ' カロリー',
    $inputAnalysis['base_product_name'] . ' S M L カロリー',
    $inputAnalysis['base_product_name'] . ' サイズ カロリー',
    $inputAnalysis['base_product_name'] . ' 栄養成分 エネルギー kcal',
    $userInput . ' カロリー',
];

$braveClient = new BraveSearchClient($apiKey);
$extractor = new NutritionPageExtractor();

/** @var array<string, array{title: string, url: string, description: string}> $mergedResults */
$mergedResults = [];
$mergedUrls = [];
$usedQuery = '';

foreach ($singleQueries as $query) {
    $search = $braveClient->search($query, 10);
    if (!$search['ok']) {
        continue;
    }

    $usedQuery = $query;
    foreach ($search['results'] as $result) {
        $url = $result['url'];
        if (!isset($mergedResults[$url])) {
            $mergedResults[$url] = $result;
            $mergedUrls[] = $url;
        }
    }

    if (count($mergedUrls) >= 5) {
        break;
    }
}

echo "使用クエリ: {$usedQuery}\n";
echo "Brave 検索回数: 1\n";
echo "URL候補数: " . count($mergedUrls) . "\n\n";

$ranked = $extractor->rankUrls($mergedUrls, [
    'query' => $usedQuery,
    'results' => array_values($mergedResults),
]);

/**
 * @param list<array{variant: string, kcal: int}> $variants
 */
function scoreMultiVariantResult(array $variants): int
{
    $score = count($variants) * 10;
    $sizes = array_map(fn (array $v): string => $v['variant'], $variants);

    if (in_array('Sサイズ', $sizes, true) && in_array('Mサイズ', $sizes, true) && in_array('Lサイズ', $sizes, true)) {
        $score += 50;
    }

    foreach ($variants as $variant) {
        $kcal = $variant['kcal'];
        if ($kcal >= 200 && $kcal <= 600) {
            $score += 5;
        }
    }

    return $score;
}

/**
 * ページ HTML から S/M/L サイズ別 kcal を抽出する（PoC）。
 *
 * @return list<array{variant: string, kcal: int, context: string}>
 */
function extractSizeVariantKcalsFromHtml(string $html, string $productHint = ''): array
{
    $found = [];
    $seen = [];

    // パターン1: S/M/L サイズ + kcal（近接）
    $patterns = [
        '/(?:^|[^a-zA-Z])([SML])\s*サイズ(?:なら|は|が|（|\()[^0-9]{0,20}(\d{2,4})\s*kcal/iu',
        '/(?:^|[^a-zA-Z])([SML])\s*サイズ[^0-9]{0,40}(\d{2,4})\s*kcal/iu',
        '/(?:^|[^a-zA-Z])([SMLsml])\s*(?:サイズ|size)?[^0-9]{0,40}?(\d{2,4})\s*kcal/iu',
        '/(?:^|[^a-zA-Z])([SMLsml])\s*(?:サイズ|size)?[^0-9]{0,40}?(\d{2,4})\s*(?:kcal|カロリー)/iu',
        '/(?:S|M|L)\s*(?:サイズ)?[^0-9]{0,20}(\d{2,4})\s*kcal/iu',
        '/(?:\(S\)|\(M\)|\(L\))[^0-9]{0,30}(\d{2,4})\s*kcal/iu',
        '/(?:S|M|L)サイズ[^0-9]{0,30}(\d{2,4})\s*kcal/iu',
        '/(?:S|M|L)サイズ[^0-9]{0,30}(\d{2,4})\s*(?:kcal|カロリー)/iu',
    ];

    // パターン2: テーブル行 — サイズ列 + エネルギー列
    if (preg_match_all(
        '/<tr[^>]*>[\s\S]{0,500}?(?:S|M|L)(?:サイズ)?[\s\S]{0,200}?(\d{2,4})\s*kcal[\s\S]{0,500}?<\/tr>/iu',
        $html,
        $tableMatches,
        PREG_SET_ORDER,
    ) >= 1) {
        foreach ($tableMatches as $match) {
            $row = $match[0];
            $kcal = (int) $match[1];
            if ($kcal < 50 || $kcal > 2000) {
                continue;
            }

            $size = null;
            if (preg_match('/\b(S|M|L)\b/u', $row, $sizeMatch) === 1) {
                $size = strtoupper($sizeMatch[1]);
            } elseif (preg_match('/\(S\)/u', $row)) {
                $size = 'S';
            } elseif (preg_match('/\(M\)/u', $row)) {
                $size = 'M';
            } elseif (preg_match('/\(L\)/u', $row)) {
                $size = 'L';
            }

            if ($size === null) {
                continue;
            }

            $key = $size . '|' . $kcal;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $found[] = [
                    'variant' => $size . 'サイズ',
                    'kcal' => $kcal,
                    'context' => mb_substr(strip_tags($row), 0, 80),
                ];
            }
        }
    }

    // パターン3: JSON-LD / data attributes
    if (preg_match_all(
        '/"(?:size|variant|name)"\s*:\s*"[^"]*(?:S|M|L)[^"]*"[\s\S]{0,120}?"(?:kcal|calories|energy)"\s*:\s*(\d{2,4})/iu',
        $html,
        $jsonMatches,
        PREG_SET_ORDER,
    ) >= 1) {
        foreach ($jsonMatches as $match) {
            $block = $match[0];
            $kcal = (int) $match[1];
            if ($kcal < 50 || $kcal > 2000) {
                continue;
            }

            $size = null;
            if (preg_match('/\b(S|M|L)\b/u', $block, $sizeMatch) === 1) {
                $size = strtoupper($sizeMatch[1]);
            }

            if ($size === null) {
                continue;
            }

            $key = $size . '|' . $kcal;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $found[] = [
                    'variant' => $size . 'サイズ',
                    'kcal' => $kcal,
                    'context' => mb_substr($block, 0, 80),
                ];
            }
        }
    }

    // パターン4: テキスト上の S/M/L + kcal
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            continue;
        }

        foreach ($matches as $match) {
            if (isset($match[1]) && is_array($match[1])) {
                $sizeRaw = $match[1][0] ?? '';
                $kcalRaw = $match[2][0] ?? ($match[1][0] ?? '');
                $offset = (int) ($match[0][1] ?? 0);
            } else {
                continue;
            }

            $kcal = (int) $kcalRaw;
            if ($kcal < 50 || $kcal > 2000) {
                continue;
            }

            $size = null;
            if (preg_match('/^[sml]$/iu', (string) $sizeRaw)) {
                $size = strtoupper((string) $sizeRaw);
            } elseif (preg_match('/\b(S|M|L)\b/u', $match[0][0] ?? '', $sizeMatch) === 1) {
                $size = strtoupper($sizeMatch[1]);
            }

            if ($size === null) {
                continue;
            }

            // PREG_OFFSET_CAPTURE の offset はバイト位置
            $contextStart = max(0, $offset - 200);
            $context = substr($html, $contextStart, 400);
            $contextLower = mb_strtolower(strip_tags($context));

            if ($productHint !== '') {
                $hintLower = mb_strtolower($productHint);
                $hasProductHint = mb_strpos($contextLower, 'ポテト') !== false
                    || mb_strpos($contextLower, 'potato') !== false
                    || mb_strpos($contextLower, 'フライ') !== false
                    || mb_strpos($contextLower, mb_strtolower($hintLower)) !== false;
                if (!$hasProductHint) {
                    continue;
                }
            }

            $key = $size . '|' . $kcal;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $found[] = [
                    'variant' => $size . 'サイズ',
                    'kcal' => $kcal,
                    'context' => mb_substr(strip_tags($context), 0, 80),
                ];
            }
        }
    }

    // サイズごとに最も妥当な kcal を1件に絞る
    $bySize = [];
    foreach ($found as $entry) {
        $sizeKey = $entry['variant'][0] ?? '';
        if ($sizeKey === '') {
            continue;
        }

        $bySize[$sizeKey][] = $entry;
    }

    $deduped = [];
    foreach (['S', 'M', 'L'] as $sizeKey) {
        if (!isset($bySize[$sizeKey])) {
            continue;
        }

        $entries = $bySize[$sizeKey];
        usort(
            $entries,
            fn (array $a, array $b): int => (
                ($a['kcal'] >= 200 && $a['kcal'] <= 600 ? 10 : 0)
                <=> ($b['kcal'] >= 200 && $b['kcal'] <= 600 ? 10 : 0)
            ) ?: ($a['kcal'] <=> $b['kcal']),
        );

        $deduped[] = $entries[0];
    }

    // S < M < L の順序が成立する組み合わせを優先（サイズ表記ページ向け）
    if (count($deduped) === 3) {
        $s = $deduped[0]['kcal'];
        $m = $deduped[1]['kcal'];
        $l = $deduped[2]['kcal'];

        if (!($s < $m && $m < $l) && isset($bySize['M']) && count($bySize['M']) > 1) {
            $sKcal = $deduped[0]['kcal'];
            $lKcal = $deduped[2]['kcal'];

            foreach ($bySize['M'] as $mEntry) {
                $mKcal = $mEntry['kcal'];
                if ($mKcal > $sKcal && $mKcal < $lKcal) {
                    $deduped[1] = $mEntry;
                    break;
                }
            }
        }
    }

    usort($deduped, function (array $a, array $b): int {
        $order = ['Sサイズ' => 0, 'Mサイズ' => 1, 'Lサイズ' => 2];

        return ($order[$a['variant']] ?? 99) <=> ($order[$b['variant']] ?? 99);
    });

    return $deduped;
}

$bestMultiResult = null;

foreach (array_slice($ranked, 0, 8) as $entry) {
    $url = $entry['url'];
    $score = $entry['score'];

    if ($extractor->isBlockedSourceUrl($url)) {
        echo "[skip blocked] {$url}\n";
        continue;
    }

    // probeSingleUrl で HTML を取得する代わりに、rank + probe の流れを利用
    $single = $extractor->probeSingleUrl($url, $userInput);
    $variants = [];

    // probeSingleUrl は1件だけ返すので、直接 HTML を再取得して multi 抽出
    // Reflection で fetchPublicHtml を呼ぶ
    $ref = new ReflectionClass($extractor);
    $fetchMethod = $ref->getMethod('fetchPublicHtml');
    $fetchMethod->setAccessible(true);
    $html = $fetchMethod->invoke($extractor, $url);

    if ($html !== null) {
        $variants = extractSizeVariantKcalsFromHtml($html, $inputAnalysis['base_product_name']);
    }

    $variantCount = count($variants);
    $singleKcal = $single['kcal'] ?? null;

    echo "[score {$score}] {$url}\n";
    echo "  single extract: " . ($singleKcal !== null ? "{$singleKcal} kcal" : 'none') . "\n";
    echo "  multi extract: {$variantCount} variants\n";

    foreach ($variants as $v) {
        echo "    [{$v['variant']}] {$v['kcal']} kcal\n";
    }

    if ($variantCount >= 2 && ($bestMultiResult === null || scoreMultiVariantResult($variants) > scoreMultiVariantResult($bestMultiResult['variants']))) {
        $bestMultiResult = [
            'url' => $url,
            'score' => $score,
            'variants' => $variants,
        ];
    }

    echo "\n";

    if ($bestMultiResult !== null && count($bestMultiResult['variants']) >= 3) {
        break;
    }
}

echo str_repeat('=', 72) . "\n";
echo "=== 結果サマリー ===\n\n";

echo "【現行】Brave 個別検索 → 候補:\n";
foreach ($candidates as $c) {
    echo "  [{$c['variant_label']}] {$c['kcal']} kcal\n";
}

echo "\n【提案】1回検索 → ページ内 S/M/L 一括抽出:\n";
if ($bestMultiResult !== null) {
    echo "  URL: {$bestMultiResult['url']}\n";
    foreach ($bestMultiResult['variants'] as $v) {
        echo "  [{$v['variant']}] {$v['kcal']} kcal\n";
    }

    $hasAllThree = count(array_filter(
        $bestMultiResult['variants'],
        fn (array $v): bool => in_array($v['variant'], ['Sサイズ', 'Mサイズ', 'Lサイズ'], true),
    )) >= 3;

    echo "\n  S/M/L 3サイズ取得: " . ($hasAllThree ? 'YES ✓' : 'NO (部分取得)') . "\n";
} else {
    echo "  複数サイズを含むページは見つかりませんでした\n";
}
