<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/RecordScopeType.php';
require_once __DIR__ . '/../src/RecordQueryScope.php';
require_once __DIR__ . '/../src/RecordQueryScopeResolver.php';
require_once __DIR__ . '/../src/ChatHistorySanitizer.php';
require_once __DIR__ . '/../src/AuthoritativeRecordContextBuilder.php';
require_once __DIR__ . '/../src/ChatLlmMessageComposer.php';
require_once __DIR__ . '/../src/ChatCoachService.php';

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

echo "Chat record scope unit tests\n";
echo str_repeat('=', 48) . "\n";

$tz = new DateTimeZone('Asia/Tokyo');
$today = new DateTimeImmutable('2026-07-16', $tz);
$resolver = new RecordQueryScopeResolver();
$sanitizer = new ChatHistorySanitizer();
$builder = new AuthoritativeRecordContextBuilder();
$composer = new ChatLlmMessageComposer();

// --- Test: 今日 ---
$scopeToday = $resolver->resolve('今日のご飯についてアドバイスして', $today);
assertSame(RecordScopeType::TODAY, $scopeToday->type, 'today scope type');
assertSame('2026-07-16', $scopeToday->startDateString(), 'today start');
assertSame('2026-07-16', $scopeToday->endDateString(), 'today end');
echo "OK today scope\n";

// --- Test: 昨日 ---
$scopeYesterday = $resolver->resolve('昨日の食事はどう？', $today);
assertSame(RecordScopeType::YESTERDAY, $scopeYesterday->type, 'yesterday type');
assertSame('2026-07-15', $scopeYesterday->startDateString(), 'yesterday start');
assertSame('2026-07-15', $scopeYesterday->endDateString(), 'yesterday end');
echo "OK yesterday scope\n";

// --- Test4: 直近3日 ---
$scope3 = $resolver->resolve('直近3日の食事を見て', $today);
assertSame(RecordScopeType::RECENT_DAYS, $scope3->type, 'recent 3 type');
assertSame('2026-07-14', $scope3->startDateString(), 'recent 3 start = today-2');
assertSame('2026-07-16', $scope3->endDateString(), 'recent 3 end');
echo "OK recent 3 days\n";

// --- 全角数字 ---
$scopeFw = $resolver->resolve('直近７日の食事', $today);
assertSame('2026-07-10', $scopeFw->startDateString(), 'fullwidth 7 days start');
assertSame('2026-07-16', $scopeFw->endDateString(), 'fullwidth 7 days end');
echo "OK fullwidth digits\n";

// --- Test5: 今週 vs 直近一週間 ---
$scopeWeek = $resolver->resolve('今週の食事について', $today);
$scopeRecentWeek = $resolver->resolve('直近一週間の食事についてアドバイスして', $today);
assertSame(RecordScopeType::CURRENT_WEEK, $scopeWeek->type, 'current week type');
assertSame('2026-07-13', $scopeWeek->startDateString(), 'week starts Monday');
assertSame('2026-07-16', $scopeWeek->endDateString(), 'week ends today');
assertSame(RecordScopeType::RECENT_DAYS, $scopeRecentWeek->type, 'recent week type');
assertSame('2026-07-10', $scopeRecentWeek->startDateString(), 'recent week start');
assertSame('2026-07-16', $scopeRecentWeek->endDateString(), 'recent week end');
assertTrue(
    $scopeWeek->startDateString() !== $scopeRecentWeek->startDateString(),
    '今週 and 直近一週間 must differ',
);
echo "OK this week vs recent week\n";

// --- 先週 ---
$scopePrevWeek = $resolver->resolve('先週の食事傾向は？', $today);
assertSame('2026-07-06', $scopePrevWeek->startDateString(), 'prev week Mon');
assertSame('2026-07-12', $scopePrevWeek->endDateString(), 'prev week Sun');
echo "OK previous week\n";

// --- 直近傾向デフォルト ---
$scopeTrend = $resolver->resolve('最近の食生活について教えて', $today);
assertSame(RecordScopeType::RECENT_DAYS, $scopeTrend->type, 'trend default type');
assertSame('2026-07-10', $scopeTrend->startDateString(), 'trend default 7d start');
echo "OK recent trend default\n";

// --- Test1: 今日の記録 vs 過去履歴 ---
$historyConflict = [
    ['role' => 'user', 'content' => 'パスタだけの場合は？'],
    [
        'role' => 'assistant',
        'content' => "**現在の記録（2026-07-14）：**\n- 昼：パスタ312kcal\n- 間食：368kcal\n\n合計でも988kcalです。",
    ],
    ['role' => 'user', 'content' => '今日のご飯についてアドバイスして'],
];

$sanitizedConflict = $sanitizer->sanitize(array_slice($historyConflict, 0, -1));
$joinedHistory = implode("\n", array_map(
    static fn (array $m): string => $m['content'],
    $sanitizedConflict['messages'],
));
assertTrue($sanitizedConflict['excluded_count'] >= 1, 'pasta assistant summary excluded');
assertNotContains('昼：パスタ312kcal', $joinedHistory, 'past pasta kcal must not remain as fact');
assertNotContains('パスタ312kcal', $joinedHistory, 'past pasta kcal fragment must not remain');

$todayMeals = [
    [
        'recordedOn' => '2026-07-16',
        'mealType' => 'lunch',
        'foodName' => '白米 200g',
        'calories' => 312,
        'amount' => 200.0,
        'unit' => 'g',
    ],
];
$authToday = $builder->build(
    $scopeToday,
    $todayMeals,
    [],
    [],
    [],
    [],
    ['daily_intake_goal_kcal' => 1200],
);
assertSame(1, $authToday['meal_count'], 'today meal count');
assertContains('白米 200g', $authToday['json'], 'authoritative json has rice');
assertNotContains('パスタ', $authToday['json'], 'authoritative json must not invent pasta');

$finalUser = $composer->composeFinalUserMessage(
    '今日のご飯についてアドバイスして',
    $scopeToday,
    $authToday,
);
assertContains('白米 200g', $finalUser, 'final user message has rice');
assertContains('【ユーザーの質問】', $finalUser, 'final user message keeps question');
assertNotContains('昼：パスタ312kcal', $finalUser, 'final user message has no past pasta fact');

// 安全化履歴 + 正式記録を結合した LLM 入力シミュレーション
$llmMessages = $sanitizedConflict['messages'];
$llmMessages[] = ['role' => 'user', 'content' => $finalUser];
$llmBlob = json_encode($llmMessages, JSON_UNESCAPED_UNICODE) ?: '';
assertContains('白米 200g', $llmBlob, 'llm input includes rice');
assertNotContains('昼：パスタ312kcal', $llmBlob, 'llm input excludes past assistant pasta summary');
echo "OK conflict today rice vs history pasta\n";

// --- Test2: 直近一週間 ---
$weekMeals = [
    [
        'recordedOn' => '2026-07-10',
        'mealType' => 'lunch',
        'foodName' => 'パスタ',
        'calories' => 500,
    ],
    [
        'recordedOn' => '2026-07-16',
        'mealType' => 'lunch',
        'foodName' => '白米 200g',
        'calories' => 312,
        'amount' => 200.0,
        'unit' => 'g',
    ],
];
$authWeek = $builder->build($scopeRecentWeek, $weekMeals, [], [], [], [], []);
assertSame('2026-07-10', $scopeRecentWeek->startDateString(), 'week range start');
assertSame('2026-07-16', $scopeRecentWeek->endDateString(), 'week range end');
assertSame(2, $authWeek['meal_count'], 'week meal count');
$dates = array_column($authWeek['daily_records'], 'date');
assertTrue(in_array('2026-07-10', $dates, true), 'includes Jul 10');
assertTrue(in_array('2026-07-16', $dates, true), 'includes Jul 16');
assertTrue(count($authWeek['daily_records']) === 7, '7 daily slots');

$jul10 = null;
$jul16 = null;
foreach ($authWeek['daily_records'] as $day) {
    if ($day['date'] === '2026-07-10') {
        $jul10 = $day;
    }
    if ($day['date'] === '2026-07-16') {
        $jul16 = $day;
    }
}
assertSame('パスタ', $jul10['meals'][0]['food_name'] ?? null, 'Jul10 pasta in correct day');
assertSame('白米 200g', $jul16['meals'][0]['food_name'] ?? null, 'Jul16 rice in correct day');
assertSame(500, $jul10['total_calories'] ?? null, 'Jul10 total');
assertSame(312, $jul16['total_calories'] ?? null, 'Jul16 total');
echo "OK recent week structured records\n";

// --- Test3: 対象期間外の履歴を参照しない ---
$outOfScopeHistory = [
    [
        'role' => 'assistant',
        'content' => "先月はパスタについて長く話しました。\n昼：カルボナーラ650kcalが記録でした。",
    ],
    ['role' => 'user', 'content' => '直近一週間はどうだった？'],
];
$sanitizedOut = $sanitizer->sanitize(array_slice($outOfScopeHistory, 0, -1));
$outJoined = implode("\n", array_map(static fn (array $m): string => $m['content'], $sanitizedOut['messages']));
assertNotContains('カルボナーラ650kcal', $outJoined, 'out-of-scope pasta kcal removed from history facts');

$onlyRiceWeek = [
    [
        'recordedOn' => '2026-07-16',
        'mealType' => 'lunch',
        'foodName' => '白米 200g',
        'calories' => 312,
    ],
];
$authOnlyRice = $builder->build($scopeRecentWeek, $onlyRiceWeek, [], [], [], [], []);
assertContains('白米 200g', $authOnlyRice['json'], 'week context has rice');
assertNotContains('カルボナーラ', $authOnlyRice['json'], 'week context has no past pasta');
assertNotContains('パスタ', $authOnlyRice['json'], 'week context has no pasta when DB has none');
echo "OK out-of-scope history not used as facts\n";

// --- Test6: 記録なし ---
$emptyScope = $resolver->resolve('今日のご飯どう？', $today);
$authEmpty = $builder->build($emptyScope, [], [], [], [], [], []);
$emptyDay = $authEmpty['daily_records'][0] ?? null;
assertSame('no_record', $emptyDay['record_status'] ?? null, 'no_record status');
assertSame([], $emptyDay['meals'] ?? ['x'], 'empty meals array');
assertContains('未記録', $authEmpty['text'], 'text says 未記録');
assertContains('食べていないとは断定しない', $authEmpty['text'], 'do not assert uneaten');
assertSame(0, $authEmpty['meal_count'], 'zero meals');
echo "OK no record handling\n";

// --- 今月 / 先月 ---
$scopeMonth = $resolver->resolve('今月の食事を振り返って', $today);
assertSame('2026-07-01', $scopeMonth->startDateString(), 'month start');
assertSame('2026-07-16', $scopeMonth->endDateString(), 'month end today');
$scopePrevMonth = $resolver->resolve('先月どうだった？', $today);
assertSame('2026-06-01', $scopePrevMonth->startDateString(), 'prev month start');
assertSame('2026-06-30', $scopePrevMonth->endDateString(), 'prev month end');
echo "OK month scopes\n";

// --- 直近2週間 ---
$scope2w = $resolver->resolve('直近２週間の傾向は', $today);
assertSame('2026-07-03', $scope2w->startDateString(), '2 weeks start');
assertSame('2026-07-16', $scope2w->endDateString(), '2 weeks end');
echo "OK recent 2 weeks\n";

// --- 当日未記録言及の時間帯ルール ---
$beforeCutoff = new DateTimeImmutable('2026-07-17 17:59:00', $tz);
$atCutoff = new DateTimeImmutable('2026-07-17 18:00:00', $tz);
assertTrue(
    ChatCoachService::shouldSuppressTodayMissingRecordMention($beforeCutoff),
    '17:59 suppresses today missing-record mention',
);
assertTrue(
    !ChatCoachService::shouldSuppressTodayMissingRecordMention($atCutoff),
    '18:00 does not suppress today missing-record mention',
);

// UTC 入力でも Asia/Tokyo で判定されること
// 2026-07-17 08:59 UTC = 17:59 JST / 09:00 UTC = 18:00 JST
$utc = new DateTimeZone('UTC');
$beforeCutoffUtc = new DateTimeImmutable('2026-07-17 08:59:00', $utc);
$atCutoffUtc = new DateTimeImmutable('2026-07-17 09:00:00', $utc);
assertTrue(
    ChatCoachService::shouldSuppressTodayMissingRecordMention($beforeCutoffUtc),
    'UTC 08:59 (=17:59 JST) suppresses',
);
assertTrue(
    !ChatCoachService::shouldSuppressTodayMissingRecordMention($atCutoffUtc),
    'UTC 09:00 (=18:00 JST) does not suppress',
);

$authForRule = $builder->build($scopeToday, [], [], [], [], [], []);
$finalBefore = $composer->composeFinalUserMessage(
    '今日の調子はどう？',
    $scopeToday,
    $authForRule,
    null,
    $beforeCutoff,
    true,
);
assertContains('Asia/Tokyo', $finalBefore, 'before-cutoff message uses Asia/Tokyo');
assertContains('2026-07-17 17:59', $finalBefore, 'before-cutoff message shows injected now');
assertContains(
    '今日の食事・歩数・運動が未記録でも、原則として言及しない',
    $finalBefore,
    'before-cutoff final message suppresses missing-record mention',
);
assertNotContains('時間帯による当日の未記録言及の抑制はありません', $finalBefore, 'before-cutoff must not say no suppress');

$finalAfter = $composer->composeFinalUserMessage(
    '今日の調子はどう？',
    $scopeToday,
    $authForRule,
    null,
    $atCutoff,
    false,
);
assertContains('Asia/Tokyo', $finalAfter, 'after-cutoff message uses Asia/Tokyo');
assertContains('2026-07-17 18:00', $finalAfter, 'after-cutoff message shows injected now');
assertContains(
    '時間帯による当日の未記録言及の抑制はありません',
    $finalAfter,
    'after-cutoff final message lifts time-based suppress',
);
assertNotContains(
    '今日の食事・歩数・運動が未記録でも、原則として言及しない',
    $finalAfter,
    'after-cutoff must not keep suppress instruction',
);
echo "OK today missing-record mention time rule\n";

// --- 期間未指定は直近7日 ---
$scopeUnspecified = $resolver->resolve('ダイエットの進捗を教えて', $today);
assertSame(RecordScopeType::RECENT_DAYS, $scopeUnspecified->type, 'unspecified uses recent_days');
assertSame('2026-07-10', $scopeUnspecified->startDateString(), 'unspecified start = today-6');
assertSame('2026-07-16', $scopeUnspecified->endDateString(), 'unspecified end = today');
assertContains('デフォルト直近7日', $scopeUnspecified->originalExpression, 'unspecified expression');
echo "OK unspecified defaults to recent 7 days\n";

// --- 層付きコンテキスト（Claude API 不使用） ---
$todayForLayer = new DateTimeImmutable('2026-07-16', $tz);
$mealRows6m = [
    [
        'recordedOn' => '2026-02-01',
        'mealType' => 'lunch',
        'foodName' => '半年前の定食',
        'calories' => 900,
    ],
    [
        'recordedOn' => '2026-06-20',
        'mealType' => 'lunch',
        'foodName' => '過去の定食',
        'calories' => 800,
    ],
    [
        'recordedOn' => '2026-07-10',
        'mealType' => 'lunch',
        'foodName' => 'パスタ',
        'calories' => 500,
    ],
    [
        'recordedOn' => '2026-07-16',
        'mealType' => 'lunch',
        'foodName' => '白米 200g',
        'calories' => 312,
        'amount' => 200.0,
        'unit' => 'g',
    ],
];
$weightByDate6m = [
    '2026-02-01' => 72.0,
    '2026-06-20' => 70.0,
    '2026-07-16' => 68.5,
];
$stepsByDate7 = [
    '2026-07-16' => ['count' => 5000, 'burnedCalories' => 150],
];
$exercisesByDate7 = [];
$stepsCount6m = [
    '2026-07-16' => 5000,
    '2026-07-01' => 3000,
    '2026-02-01' => 2000,
];
$exerciseKcal6m = [
    '2026-07-15' => 200,
];

$layered = $builder->buildLayered(
    $scopeUnspecified,
    $todayForLayer,
    $mealRows6m,
    [],
    $weightByDate6m,
    $stepsByDate7,
    $exercisesByDate7,
    $stepsCount6m,
    $exerciseKcal6m,
    ['daily_intake_goal_kcal' => 1200],
    [
        'first_meal_recorded_on' => '2026-07-04',
        'first_weight_recorded_on' => '2026-07-04',
        'first_steps_recorded_on' => null,
        'first_exercise_recorded_on' => null,
    ],
);

assertSame('recent_7d_and_summary_30d', $layered['primary_focus'], 'progress primary focus');
assertSame('2026-07-04', $layered['recording_meta']['first_any_recorded_on'] ?? null, 'recording start date');
assertSame('2026-07-04', $layered['recording_meta']['first_meal_recorded_on'] ?? null, 'first meal date');
assertSame('2026-07-04', $layered['recording_meta']['first_weight_recorded_on'] ?? null, 'first weight date');
assertSame(12, $layered['recording_meta']['days_since_first_record'] ?? null, 'days since first record');
assertTrue(($layered['recording_meta']['has_any_record'] ?? false) === true, 'has any record');
assertContains('recording_meta', $layered['json'], 'json has recording_meta');
assertSame('2026-07-16', $layered['today_detail']['date'] ?? null, 'today_detail date');
assertSame('白米 200g', $layered['today_detail']['meals'][0]['food_name'] ?? null, 'today_detail meal');
assertSame(7, count($layered['recent_7d']), 'recent_7d has 7 days');
assertContains('today_detail', $layered['json'], 'json has today_detail');
assertContains('recent_7d', $layered['json'], 'json has recent_7d');
assertContains('summary_30d', $layered['json'], 'json has summary_30d');
assertContains('summary_6m', $layered['json'], 'json has summary_6m');
assertSame('2026-06-17', $layered['summary_30d']['period_start'] ?? null, 'summary 30d start');
assertSame('2026-07-16', $layered['summary_30d']['period_end'] ?? null, 'summary 30d end');
assertSame(3, $layered['summary_30d']['days_with_meals'] ?? null, 'summary 30d meal days');
assertSame(-1.5, $layered['summary_30d']['weight_delta_kg'] ?? null, 'summary 30d weight delta');
assertSame('2026-06-20', $layered['summary_30d']['weight_start_recorded_on'] ?? null, 'summary 30d weight start date');
assertSame('2026-07-16', $layered['summary_30d']['weight_end_recorded_on'] ?? null, 'summary 30d weight end date');
assertSame('no_record', $layered['summary_30d']['weight_on_period_start']['status'] ?? null, 'no weight on period_start');
assertTrue(
    array_key_exists('kg', $layered['summary_30d']['weight_on_period_start'] ?? []),
    'period_start has kg key',
);
assertSame(null, $layered['summary_30d']['weight_on_period_start']['kg'], 'period_start kg is null');
assertSame('recorded', $layered['summary_30d']['weight_on_period_end']['status'] ?? null, 'weight on period_end');
assertSame(68.5, $layered['summary_30d']['weight_on_period_end']['kg'] ?? null, 'period_end kg');
assertSame('2026-01-16', $layered['summary_6m']['period_start'] ?? null, 'summary 6m start');
assertSame('2026-07-16', $layered['summary_6m']['period_end'] ?? null, 'summary 6m end');
assertSame(4, $layered['summary_6m']['days_with_meals'] ?? null, 'summary 6m meal days');
assertSame(-3.5, $layered['summary_6m']['weight_delta_kg'] ?? null, 'summary 6m weight delta');
assertSame('2026-02-01', $layered['summary_6m']['weight_start_recorded_on'] ?? null, 'summary 6m weight start date');
assertSame('no_record', $layered['summary_6m']['weight_on_period_start']['status'] ?? null, '6m no weight on period_start');
assertContains('weight_start_recorded_on', $layered['json'], 'json exposes weight_start_recorded_on');
assertContains('weight_on_period_start', $layered['json'], 'json exposes weight_on_period_start');
assertTrue(
    !isset($layered['summary_30d']['meals']) && !str_contains(
        json_encode($layered['summary_30d'], JSON_UNESCAPED_UNICODE) ?: '',
        '過去の定食',
    ),
    'summary_30d must not include meal food names',
);
assertTrue(
    !str_contains(json_encode($layered['summary_6m'], JSON_UNESCAPED_UNICODE) ?: '', '半年前の定食'),
    'summary_6m must not include meal food names',
);

$layeredTodayScope = $builder->buildLayered(
    $scopeToday,
    $todayForLayer,
    $mealRows6m,
    [],
    $weightByDate6m,
    $stepsByDate7,
    $exercisesByDate7,
    $stepsCount6m,
    $exerciseKcal6m,
    [],
);
assertSame('today_detail', $layeredTodayScope['primary_focus'], 'today scope primary focus');

$finalLayered = $composer->composeFinalUserMessage(
    'ダイエットの進捗を教えて',
    $scopeUnspecified,
    $layered,
);
assertContains('today_detail', $finalLayered, 'final message mentions today_detail layer');
assertContains('summary_30d', $finalLayered, 'final message mentions summary_30d layer');
assertContains('summary_6m', $finalLayered, 'final message mentions summary_6m layer');
assertContains('recording_meta', $finalLayered, 'final message mentions recording_meta');
assertContains('first_any_recorded_on', $finalLayered, 'final message mentions first_any_recorded_on');
assertContains('weight_start_recorded_on', $finalLayered, 'final message mentions weight date fields');
assertContains('primary_focus: recent_7d_and_summary_30d', $finalLayered, 'final message has primary_focus');
echo "OK layered authoritative context\n";

echo str_repeat('=', 48) . "\n";
echo "All chat record scope tests passed.\n";
