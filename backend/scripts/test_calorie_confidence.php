<?php

declare(strict_types=1);

/**
 * AIカロリー推定の confidence / should_offer_web_search を確認するスクリプト。
 *
 * Usage:
 *   php backend/scripts/test_calorie_confidence.php
 *   php backend/scripts/test_calorie_confidence.php --runs=2
 */

require_once __DIR__ . '/EnvLoader.php';
require_once __DIR__ . '/../src/CalorieEstimateService.php';

load_project_env();

$runs = 2;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--runs=(\d+)$/', $arg, $matches) === 1) {
        $runs = max(1, min(5, (int) $matches[1]));
    }
}

/**
 * @var list<array{
 *   input: string,
 *   expect_confidence?: list<string>,
 *   disallow_low?: bool,
 *   require_low?: bool,
 *   expect_web_search: bool
 * }> $cases
 */
$cases = [
    ['input' => 'お弁当', 'require_low' => true, 'expect_web_search' => false],
    ['input' => '日替わり定食', 'require_low' => true, 'expect_web_search' => false],
    ['input' => '手作り弁当', 'require_low' => true, 'expect_web_search' => false],
    ['input' => 'ローソン のり弁当', 'expect_web_search' => true],
    ['input' => 'セブンイレブン 幕の内弁当', 'expect_web_search' => true],
    ['input' => 'ファミチキ', 'expect_web_search' => true],
    ['input' => 'カップヌードル', 'expect_web_search' => true],
    [
        'input' => 'カレー',
        'expect_confidence' => ['medium', 'low'],
        'expect_web_search' => false,
    ],
    ['input' => 'マクドナルド ビッグマック', 'expect_confidence' => ['medium', 'low'], 'expect_web_search' => true],
    [
        'input' => 'ナッシュ たらと辛旨チリソース',
        'expect_confidence' => ['medium'],
        'expect_web_search' => true,
    ],
    // 既存の安定性確認
    ['input' => 'ゆで卵 2個', 'expect_confidence' => ['high'], 'disallow_low' => true, 'expect_web_search' => false],
];

$service = new CalorieEstimateService();
$failed = 0;

foreach ($cases as $case) {
    $confidences = [];
    $webFlags = [];
    $reasons = [];
    $kcals = [];

    for ($i = 0; $i < $runs; $i++) {
        try {
            $result = $service->estimate($case['input'], 'no_web');
            $confidence = (string) ($result['confidence'] ?? '');
            $offerWeb = (bool) ($result['should_offer_web_search'] ?? false);
            $confidences[] = $confidence;
            $webFlags[] = $offerWeb ? 'true' : 'false';
            $reasons[] = (string) ($result['web_search_reason'] ?? '');
            $kcals[] = (int) ($result['kcal'] ?? 0);
        } catch (Throwable $exception) {
            $confidences[] = 'error';
            $webFlags[] = 'error';
            $reasons[] = $exception->getMessage();
            $kcals[] = 0;
            fwrite(STDERR, $case['input'] . ' run' . ($i + 1) . ': ' . $exception->getMessage() . PHP_EOL);
        }
        usleep(200000);
    }

    $ok = true;

    if (isset($case['expect_confidence'])) {
        foreach ($confidences as $confidence) {
            if (!in_array($confidence, $case['expect_confidence'], true)) {
                $ok = false;
                break;
            }
        }
    }

    if (($case['disallow_low'] ?? false) && in_array('low', $confidences, true)) {
        $ok = false;
    }

    if (($case['require_low'] ?? false)) {
        foreach ($confidences as $confidence) {
            if ($confidence !== 'low') {
                $ok = false;
                break;
            }
        }
    }

    $expectedWeb = $case['expect_web_search'];
    foreach ($webFlags as $flag) {
        $asBool = $flag === 'true';
        if ($flag === 'error' || $asBool !== $expectedWeb) {
            $ok = false;
            break;
        }
    }

    if (!$ok) {
        $failed++;
    }

    $status = $ok ? 'OK' : 'NG';
    echo sprintf(
        "[%s] %s => confidence=%s web=%s kcal=%s\n",
        $status,
        $case['input'],
        implode(',', $confidences),
        implode(',', $webFlags),
        implode(',', $kcals),
    );
    if ($reasons !== [] && implode('', $reasons) !== '') {
        echo '  reason: ' . implode(' | ', array_unique(array_filter($reasons))) . PHP_EOL;
    }
}

echo PHP_EOL . ($failed === 0 ? "ALL PASSED\n" : "FAILED: {$failed} case(s)\n");
exit($failed === 0 ? 0 : 1);
