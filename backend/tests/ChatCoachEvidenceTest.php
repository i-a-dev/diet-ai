<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/RecordScopeType.php';
require_once __DIR__ . '/../src/RecordQueryScope.php';
require_once __DIR__ . '/../src/RecordQueryScopeResolver.php';
require_once __DIR__ . '/../src/DietAnswerEvidenceBuilder.php';
require_once __DIR__ . '/../src/AuthoritativeRecordContextBuilder.php';
require_once __DIR__ . '/../src/ChatLlmMessageComposer.php';
require_once __DIR__ . '/../src/ChatCoachService.php';
require_once __DIR__ . '/../src/CalorieGoalCalculator.php';

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

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('FAIL: ' . $message . ' (missing: ' . $needle . ')');
    }
}

function assertNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException('FAIL: ' . $message . ' (unexpected: ' . $needle . ')');
    }
}

echo "Chat coach evidence & prompt tests\n";
echo str_repeat('=', 48) . "\n";

$tz = new DateTimeZone('Asia/Tokyo');
$today = new DateTimeImmutable('2026-07-20', $tz);
$resolver = new RecordQueryScopeResolver();
$evidenceBuilder = new DietAnswerEvidenceBuilder();
$builder = new AuthoritativeRecordContextBuilder($evidenceBuilder);
$composer = new ChatLlmMessageComposer();

// --- RecordQueryScopeResolver: requested cases ---
$scopeMenu = $resolver->resolve('今日のメニュー痩せる？', $today);
assertSame(RecordScopeType::TODAY, $scopeMenu->type, '今日のメニュー痩せる？ → TODAY');

$scopeGohan = $resolver->resolve('今日のご飯どう？', $today);
assertSame(RecordScopeType::TODAY, $scopeGohan->type, '今日のご飯どう？ → TODAY');

$scopeRecent = $resolver->resolve('最近の食事どう？', $today);
assertSame(RecordScopeType::RECENT_DAYS, $scopeRecent->type, '最近の食事どう？ → RECENT_DAYS');
assertSame('2026-07-14', $scopeRecent->startDateString(), '最近の食事どう？ start');
assertSame('2026-07-20', $scopeRecent->endDateString(), '最近の食事どう？ end');

$scopeYesterday = $resolver->resolve('昨日の食事どう？', $today);
assertSame(RecordScopeType::YESTERDAY, $scopeYesterday->type, '昨日の食事どう？ → YESTERDAY');
assertSame('2026-07-19', $scopeYesterday->startDateString(), '昨日 start');

$scopePrevWeek = $resolver->resolve('先週の食事バランス', $today);
assertSame(RecordScopeType::PREVIOUS_WEEK, $scopePrevWeek->type, '先週の食事バランス → PREVIOUS_WEEK');
assertSame('2026-07-13', $scopePrevWeek->startDateString(), 'prev week Mon');
assertSame('2026-07-19', $scopePrevWeek->endDateString(), 'prev week Sun');

// 明示期間がヒント語より優先（「最近」ヒントがあっても「昨日」が勝つ）
$scopeExplicitWins = $resolver->resolve('昨日の最近の食事バランスどう？', $today);
assertSame(RecordScopeType::YESTERDAY, $scopeExplicitWins->type, '明示期間がヒントより優先');

$scopeDietOriented = $resolver->resolve('この食事は減量向き？', $today);
assertSame(RecordScopeType::TODAY, $scopeDietOriented->type, 'この食事は減量向き？ → TODAY');

$scopeBalance = $resolver->resolve('食事バランスどう？', $today);
assertSame(RecordScopeType::TODAY, $scopeBalance->type, '食事バランスどう？ → TODAY');
echo "OK RecordQueryScopeResolver cases\n";

// --- PFC status ---
$scopeToday = new RecordQueryScope(RecordScopeType::TODAY, $today, $today, '今日');

$completeMeals = [
    [
        'recordedOn' => '2026-07-20',
        'mealType' => 'lunch',
        'foodName' => '鶏むね肉',
        'calories' => 200,
        'amount' => 100.0,
        'unit' => 'g',
        'proteinG' => 25.0,
        'fatG' => 3.0,
        'carbsG' => 0.0,
        'servingLabel' => '100g',
        'servingWeightG' => 100.0,
    ],
    [
        'recordedOn' => '2026-07-20',
        'mealType' => 'lunch',
        'foodName' => '白米',
        'calories' => 250,
        'amount' => 150.0,
        'unit' => 'g',
        'proteinG' => 4.0,
        'fatG' => 0.5,
        'carbsG' => 55.0,
    ],
];
$authComplete = $builder->build($scopeToday, $completeMeals, [], [], [], [], [
    'daily_intake_goal_kcal' => 1800,
    'tdee_kcal' => 2200,
    'target_weight_kg' => 60.0,
]);
assertSame('complete', $authComplete['pfc_evidence']['status'] ?? null, 'PFC complete');
assertSame(2, $authComplete['pfc_evidence']['registered_pfc_entry_count'] ?? null, 'complete pfc count');
assertSame(29.0, $authComplete['pfc_evidence']['registered_totals']['protein_g'] ?? null, 'complete protein total');
assertContains('鶏むね肉', $authComplete['json'], 'food detail in context');
assertContains('serving_label', $authComplete['json'], 'serving label in context');
assertNotContains('部分合計であり、期間全体のPFC総量ではない', (string) ($authComplete['pfc_evidence']['note'] ?? ''), 'complete is not partial note');
echo "OK PFC complete\n";

$partialMeals = [
    [
        'recordedOn' => '2026-07-20',
        'mealType' => 'breakfast',
        'foodName' => 'プロテイン',
        'calories' => 120,
        'proteinG' => 20.0,
        'fatG' => 1.0,
        'carbsG' => 3.0,
    ],
    [
        'recordedOn' => '2026-07-20',
        'mealType' => 'lunch',
        'foodName' => '唐揚げ定食',
        'calories' => 850,
        'proteinG' => null,
        'fatG' => null,
        'carbsG' => null,
    ],
    [
        'recordedOn' => '2026-07-20',
        'mealType' => 'dinner',
        'foodName' => 'サラダ',
        'calories' => 80,
        'proteinG' => null,
        'fatG' => null,
        'carbsG' => null,
    ],
];
$authPartial = $builder->build($scopeToday, $partialMeals, [], [], [], [], [
    'daily_intake_goal_kcal' => 1800,
]);
assertSame('partial', $authPartial['pfc_evidence']['status'] ?? null, 'PFC partial');
assertSame(1, $authPartial['pfc_evidence']['registered_pfc_entry_count'] ?? null, 'partial pfc count');
assertSame(20.0, $authPartial['pfc_evidence']['registered_totals']['protein_g'] ?? null, 'partial protein is partial sum');
assertContains('部分合計', (string) ($authPartial['pfc_evidence']['note'] ?? ''), 'partial note warns against full-day use');
assertContains('唐揚げ定食', $authPartial['json'], 'partial food details included');
$finalPartial = $composer->composeFinalUserMessage('今日の食事バランスどう？', $scopeToday, $authPartial);
assertContains('部分合計', $finalPartial, 'composer warns partial totals');
assertContains('唐揚げ定食', $finalPartial, 'composer includes food details');
echo "OK PFC partial\n";

$noneMeals = [
    [
        'recordedOn' => '2026-07-20',
        'mealType' => 'lunch',
        'foodName' => 'ラーメン',
        'calories' => 600,
    ],
];
$authNone = $builder->build($scopeToday, $noneMeals, [], [], [], [], []);
assertSame('none', $authNone['pfc_evidence']['status'] ?? null, 'PFC none');
assertSame(0, $authNone['pfc_evidence']['registered_pfc_entry_count'] ?? null, 'none pfc count');
assertTrue(($authNone['pfc_evidence']['may_estimate_missing_from_foods'] ?? false) === true, 'may estimate when meals exist');
echo "OK PFC none\n";

// --- Answer permissions ---
$authEmpty = $builder->build($scopeToday, [], [], [], [], [], [
    'daily_intake_goal_kcal' => 1800,
    'tdee_kcal' => 2200,
]);
assertSame(false, $authEmpty['answer_permissions']['may_estimate_pfc_from_foods'] ?? null, 'no meals → no pfc estimate');
assertSame(false, $authEmpty['answer_permissions']['may_comment_on_meal_composition'] ?? null, 'no meals → no composition');
assertSame(false, $authEmpty['answer_permissions']['may_assert_fat_loss'] ?? null, 'fat loss always false');
assertSame(false, $authEmpty['answer_permissions']['may_predict_next_day_weight'] ?? null, 'next day weight always false');
assertSame(false, $authEmpty['answer_permissions']['may_estimate_energy_balance'] ?? null, 'energy balance false when unknown completion');
assertSame('unknown', $authEmpty['meal_record_meta']['day_completion'] ?? null, 'day_completion unknown');
assertSame(false, $authEmpty['energy_evidence']['may_estimate_energy_balance'] ?? null, 'energy evidence balance false');

assertSame(true, $authNone['answer_permissions']['may_estimate_pfc_from_foods'] ?? null, 'meals → may estimate pfc');
assertSame(true, $authNone['answer_permissions']['may_comment_on_meal_composition'] ?? null, 'named meals → composition');
assertSame(false, $authNone['answer_permissions']['may_assert_fat_loss'] ?? null, 'fat loss still false with meals');
assertSame(false, $authNone['answer_permissions']['may_predict_next_day_weight'] ?? null, 'next day still false');

$authOneWeight = $builder->build(
    $scopeToday,
    $noneMeals,
    [],
    ['2026-07-20' => 65.0],
    [],
    [],
    ['target_weight_kg' => 60.0],
);
assertSame('insufficient_data', $authOneWeight['weight_evidence']['trend_status'] ?? null, 'single weight insufficient');
assertSame(false, $authOneWeight['answer_permissions']['may_evaluate_weight_trend'] ?? null, 'single weight no trend');
assertSame(true, $authOneWeight['answer_permissions']['may_compute_remaining_to_target'] ?? null, 'can compute remaining with latest+target');
assertSame(5.0, $authOneWeight['weight_evidence']['remaining_to_target_kg'] ?? null, 'remaining to target');

$authTrend = $builder->build(
    $scopeToday,
    [],
    [],
    [
        '2026-07-01' => 65.0,
        '2026-07-20' => 64.4,
    ],
    [],
    [],
    ['target_weight_kg' => 60.0],
);
assertSame('available', $authTrend['weight_evidence']['trend_status'] ?? null, 'multi weight trend available');
assertSame('decreasing', $authTrend['weight_evidence']['trend_direction'] ?? null, 'decreasing direction');
assertSame(true, $authTrend['answer_permissions']['may_evaluate_weight_trend'] ?? null, 'may evaluate trend');
assertSame(false, $authTrend['answer_permissions']['may_assert_fat_loss'] ?? null, 'trend does not allow fat-loss assert');

$authNoLatest = $builder->build($scopeToday, [], [], [], [], [], ['target_weight_kg' => 60.0]);
assertSame(false, $authNoLatest['weight_evidence']['can_compute_remaining_to_target'] ?? null, 'no weight → cannot compute remaining');
assertSame(false, $authNoLatest['answer_permissions']['may_compute_remaining_to_target'] ?? null, 'permission false without weight');
echo "OK answer permissions & weight evidence\n";

// --- Profile / calorie goal (no profile current weight) ---
$goalWithWeight = CalorieGoalCalculator::calculate([
    'gender' => 'female',
    'birthDate' => '1990-01-01',
    'heightCm' => 160.0,
    'weightKg' => 55.0,
    'activityLevel' => 'sedentary',
    'targetPaceKgPerMonth' => 2.0,
]);
assertTrue($goalWithWeight['bmrKcal'] !== null, 'bmr with weight record');
assertTrue($goalWithWeight['tdeeKcal'] !== null, 'tdee with weight record');
assertTrue($goalWithWeight['dailyIntakeGoalKcal'] !== null, 'intake goal with weight record');
assertTrue($goalWithWeight['dailyDeficitKcal'] !== null, 'deficit from target pace');

$goalWithoutWeight = CalorieGoalCalculator::calculate([
    'gender' => 'female',
    'birthDate' => '1990-01-01',
    'heightCm' => 160.0,
    'weightKg' => null,
    'activityLevel' => 'sedentary',
    'targetPaceKgPerMonth' => 2.0,
]);
assertSame(null, $goalWithoutWeight['bmrKcal'], 'no weight → bmr null');
assertSame(null, $goalWithoutWeight['tdeeKcal'], 'no weight → tdee null');
assertSame(null, $goalWithoutWeight['dailyIntakeGoalKcal'], 'no weight → intake goal null');
assertTrue($goalWithoutWeight['dailyDeficitKcal'] !== null, 'target pace deficit still calculable');

$profileSnapshotKeys = array_keys($authComplete['profile']);
assertTrue(!in_array('current_weight_kg', $profileSnapshotKeys, true), 'no current_weight_kg in profile snapshot');
assertTrue(!in_array('currentWeightKg', $profileSnapshotKeys, true), 'no currentWeightKg in profile snapshot');
assertTrue(array_key_exists('target_weight_kg', $authComplete['profile']), 'target weight kept');
assertSame(60.0, $authComplete['profile']['target_weight_kg'] ?? null, 'target weight value');

$systemPrompt = (new ReflectionClass(ChatCoachService::class))->getConstant('SYSTEM_PROMPT');
assertTrue(is_string($systemPrompt), 'system prompt is string');
assertContains('目標体重', $systemPrompt, 'system keeps 目標体重');
assertContains('目標ペース', $systemPrompt, 'system keeps 目標ペース');
assertNotContains('プロフィールの現在体重', $systemPrompt, 'does not instruct using profile current weight as source');
assertContains('プロフィールに現在体重項目はない', $systemPrompt, 'explicitly notes no profile current weight');
echo "OK profile & calorie goal\n";

// --- System prompt rules ---
assertContains('登録済みPFCは正式記録として優先', $systemPrompt, 'registered PFC priority');
assertContains('範囲で示し', $systemPrompt, 'PFC estimate as range');
assertContains('参考推定', $systemPrompt, 'reference estimate wording');
assertContains('単日の食事だけで「痩せる」', $systemPrompt, 'no single-day fat loss assert');
assertContains('may_predict_next_day_weight は常に false', $systemPrompt, 'no next-day weight prediction');
assertContains('登録された範囲では', $systemPrompt, 'registered-range wording');
assertContains('最初の一文でユーザーの質問そのものに答える', $systemPrompt, 'answer question first');
assertContains('アドバイスを原則1つ', $systemPrompt, 'one advice');
assertNotContains('PFCが不完全なときは、不足している旨を短く伝え、カロリー（kcal）を中心にアドバイスする', $systemPrompt, 'old PFC rule removed');
echo "OK system prompt rules\n";

// --- Scope isolation: yesterday question must not center on today meals ---
$yesterday = $today->modify('-1 day');
$scopeY = new RecordQueryScope(RecordScopeType::YESTERDAY, $yesterday, $yesterday, '昨日');
$mixedMeals = [
    [
        'recordedOn' => '2026-07-19',
        'mealType' => 'lunch',
        'foodName' => '昨日のサラダ',
        'calories' => 300,
        'proteinG' => 10.0,
        'fatG' => 5.0,
        'carbsG' => 20.0,
    ],
    [
        'recordedOn' => '2026-07-20',
        'mealType' => 'lunch',
        'foodName' => '今日のラーメン',
        'calories' => 900,
    ],
];
$layeredY = $builder->buildLayered(
    $scopeY,
    $today,
    $mixedMeals,
    [],
    ['2026-07-10' => 66.0, '2026-07-20' => 65.0],
    [],
    [],
    [],
    [],
    ['daily_intake_goal_kcal' => 1800, 'target_weight_kg' => 60.0, 'target_pace_kg_per_month' => 2.0],
);
assertSame('scope_records', $layeredY['primary_focus'] ?? null, 'yesterday primary focus');
assertSame(1, $layeredY['meal_count'] ?? null, 'yesterday meal_count excludes today');
assertContains('昨日のサラダ', $layeredY['json'], 'yesterday food in scope');
$scopeJson = json_encode($layeredY['scope_records'], JSON_UNESCAPED_UNICODE) ?: '';
assertContains('昨日のサラダ', $scopeJson, 'scope_records has yesterday');
assertNotContains('今日のラーメン', $scopeJson, 'scope_records excludes today food');
assertSame('今日のラーメン', $layeredY['today_detail']['meals'][0]['food_name'] ?? null, 'today_detail still has today');
assertContains('対象期間外なら回答の主根拠にしない', (string) ($layeredY['layer_guidance'] ?? ''), 'guidance warns against today as primary');
$finalY = $composer->composeFinalUserMessage('昨日の食事どう？', $scopeY, $layeredY);
assertContains('scope_records', $finalY, 'final message emphasizes scope_records');
assertContains('対象期間外なら主根拠にしない', $finalY, 'final message scope rule');
echo "OK scope isolation\n";

// --- Energy compare vs assert ---
assertSame(true, $authPartial['energy_evidence']['may_compare_with_goal'] ?? null, 'may compare with goal');
assertSame(false, $authPartial['energy_evidence']['may_assert_fat_loss'] ?? null, 'may not assert fat loss');
assertSame(1050, $authPartial['registered_intake_kcal'] ?? null, 'registered intake sum');
assertSame('unknown', $authPartial['meal_record_meta']['day_completion'] ?? null, 'completion unknown');
echo "OK energy evidence\n";

// --- Numeric comparisons: avg slightly above BMR must be "above", never inverted ---
$authAboveBmr = $builder->build(
    $scopeToday,
    [
        [
            'recordedOn' => '2026-07-20',
            'mealType' => 'lunch',
            'foodName' => '定食',
            'calories' => 1261,
        ],
    ],
    [],
    [],
    [],
    [],
    [
        'bmr_kcal' => 1257,
        'tdee_kcal' => 1800,
        'daily_intake_goal_kcal' => 1500,
    ],
);
assertSame(1261, $authAboveBmr['energy_evidence']['registered_avg_intake_kcal_on_days_with_meals'] ?? null, 'avg equals single day');
assertSame(1257, $authAboveBmr['energy_evidence']['bmr_kcal'] ?? null, 'bmr from profile snapshot');
assertSame('above', $authAboveBmr['energy_evidence']['comparisons']['registered_avg_vs_bmr'] ?? null, '1261 > 1257 => above');
assertSame('above', $authAboveBmr['numeric_comparisons']['registered_avg_vs_bmr'] ?? null, 'numeric_comparisons mirrors above');
assertSame('below', $authAboveBmr['energy_evidence']['comparisons']['registered_avg_vs_goal'] ?? null, '1261 < 1500 => below goal');
assertSame('below', $authAboveBmr['energy_evidence']['comparisons']['registered_avg_vs_tdee'] ?? null, '1261 < 1800 => below tdee');

$authBelowBmr = $builder->build(
    $scopeToday,
    [
        [
            'recordedOn' => '2026-07-20',
            'mealType' => 'lunch',
            'foodName' => '軽い食事',
            'calories' => 1000,
        ],
    ],
    [],
    [],
    [],
    [],
    ['bmr_kcal' => 1257],
);
assertSame('below', $authBelowBmr['energy_evidence']['comparisons']['registered_avg_vs_bmr'] ?? null, '1000 < 1257 => below');

$finalAbove = $composer->composeFinalUserMessage('最近の平均どう？', $scopeToday, $authAboveBmr);
assertContains('registered_avg_vs_bmr":"above"', $finalAbove, 'composer exposes above comparison');
assertContains('avg>BMR なのに下回ると言わない', $finalAbove, 'composer forbids inverted below wording');
assertContains('数値比較の正確性', $systemPrompt, 'system prompt has numeric accuracy section');
assertContains('registered_avg_vs_bmr=above のとき', $systemPrompt, 'system forbids saying below when above');
echo "OK numeric comparisons\n";

// --- Composer sections snapshot-ish ---
$composed = $composer->composeFinalUserMessage(
    '今日のメニュー痩せる？',
    $scopeToday,
    $authPartial,
    '糖質を抑えめにしたい',
    new DateTimeImmutable('2026-07-20 12:00:00', $tz),
    true,
);
foreach ([
    '【質問対象期間】',
    '【正式な記録】',
    '【記録状態】',
    '【PFCの証拠状態】',
    '【体重・エネルギーの証拠状態】',
    '【回答可能範囲】',
    '【時間帯による未記録言及ルール】',
    '【ユーザーの質問】',
    '今日のメニュー痩せる？',
    '糖質を抑えめにしたい',
] as $section) {
    assertContains($section, $composed, 'composer section: ' . $section);
}
assertContains('"may_assert_fat_loss":false', $composed, 'permissions in composer');
echo "OK composer sections\n";

echo str_repeat('=', 48) . "\n";
echo "All chat coach evidence tests passed.\n";
