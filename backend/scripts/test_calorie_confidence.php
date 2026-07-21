<?php

declare(strict_types=1);

/**
 * AIカロリー推定の confidence 判定を手動確認するスクリプト。
 *
 * Usage:
 *   php backend/scripts/test_calorie_confidence.php
 *   php backend/scripts/test_calorie_confidence.php --runs=3
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

/** @var list<array{input: string, expect: list<string>, disallow_low?: bool, require_low?: bool}> $cases */
$cases = [
    ['input' => 'ゆで卵 2個', 'expect' => ['high'], 'disallow_low' => true],
    ['input' => 'ゆで卵 1個', 'expect' => ['high'], 'disallow_low' => true],
    ['input' => '白米 150g', 'expect' => ['high'], 'disallow_low' => true],
    ['input' => '牛乳 200ml', 'expect' => ['high'], 'disallow_low' => true],
    ['input' => 'バナナ 1本', 'expect' => ['high'], 'disallow_low' => true],
    ['input' => 'おにぎり 1個', 'expect' => ['medium', 'high'], 'disallow_low' => true],
    ['input' => '食パン 1枚', 'expect' => ['medium', 'high'], 'disallow_low' => true],
    ['input' => '味噌汁 1杯', 'expect' => ['medium', 'high'], 'disallow_low' => true],
    ['input' => '日替わり定食', 'expect' => ['low'], 'require_low' => true],
    // APIは3文字未満を拒否するため、弁当相当の曖昧入力として検証
    ['input' => 'お弁当', 'expect' => ['low'], 'require_low' => true],
    ['input' => 'ラーメン', 'expect' => ['low'], 'require_low' => true],
    ['input' => 'カレー', 'expect' => ['low'], 'require_low' => true],
    ['input' => 'サラダ', 'expect' => ['low'], 'require_low' => true],
    ['input' => 'パスタ', 'expect' => ['low'], 'require_low' => true],
];

$service = new CalorieEstimateService();
$failed = 0;

foreach ($cases as $case) {
    $confidences = [];
    $kcals = [];

    for ($i = 0; $i < $runs; $i++) {
        try {
            $result = $service->estimate($case['input'], 'no_web');
            $confidence = (string) ($result['confidence'] ?? '');
            $kcal = (int) ($result['kcal'] ?? 0);
            $confidences[] = $confidence;
            $kcals[] = $kcal;
        } catch (Throwable $exception) {
            $confidences[] = 'error';
            $kcals[] = 0;
            fwrite(STDERR, $case['input'] . ' run' . ($i + 1) . ': ' . $exception->getMessage() . PHP_EOL);
        }
        usleep(200000);
    }

    $unique = array_values(array_unique($confidences));
    $ok = true;
    foreach ($confidences as $confidence) {
        if (!in_array($confidence, $case['expect'], true)) {
            $ok = false;
            break;
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
    if (!$ok) {
        $failed++;
    }

    $status = $ok ? 'OK' : 'NG';
    echo sprintf(
        "[%s] %s => confidence=%s kcal=%s (expect: %s)\n",
        $status,
        $case['input'],
        implode(',', $confidences),
        implode(',', $kcals),
        implode('|', $case['expect']),
    );

    if (count($unique) > 1) {
        echo "  note: confidence varied across runs: " . implode(',', $unique) . PHP_EOL;
    }
}

echo PHP_EOL . ($failed === 0 ? "ALL PASSED\n" : "FAILED: {$failed} case(s)\n");
exit($failed === 0 ? 0 : 1);
