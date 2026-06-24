<?php

declare(strict_types=1);

/**
 * API エントリーポイント。
 * リクエストの URL と HTTP メソッドに応じて、各 Repository を呼び出し JSON を返す。
 */

require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/WeightRepository.php';
require_once __DIR__ . '/../src/MealEntryRepository.php';
require_once __DIR__ . '/../src/ActivityRepository.php';
require_once __DIR__ . '/../src/MockRepository.php';
require_once __DIR__ . '/../src/CalorieEstimateService.php';
require_once __DIR__ . '/../src/FoodNormalizeService.php';
require_once __DIR__ . '/../src/ExerciseMetEstimateService.php';
require_once __DIR__ . '/../src/UserProfileRepository.php';

$repository = new MockRepository();
$weightRepository = new WeightRepository();
$userProfileRepository = new UserProfileRepository();
$mealEntryRepository = new MealEntryRepository();
$activityRepository = new ActivityRepository();
$calorieEstimateService = new CalorieEstimateService();
$foodNormalizeService = new FoodNormalizeService();
$exerciseMetEstimateService = new ExerciseMetEstimateService();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/**
 * 変更: 運動カロリープレビュー/保存で共通利用する体重解決ロジック。
 *
 * @return array{weightKg: float, weightSource: string, referenceRecordedOn: string|null}
 */
$resolveWeightContext = static function (array $weightSummary): array {
    if (is_numeric($weightSummary['current'] ?? null)) {
        return [
            'weightKg' => round((float) $weightSummary['current'], 1),
            'weightSource' => 'current',
            'referenceRecordedOn' => $weightSummary['recordedOn'] ?? null,
        ];
    }

    if (is_numeric($weightSummary['referenceWeight'] ?? null)) {
        return [
            'weightKg' => round((float) $weightSummary['referenceWeight'], 1),
            'weightSource' => 'reference',
            'referenceRecordedOn' => $weightSummary['referenceRecordedOn'] ?? null,
        ];
    }

    return [
        'weightKg' => 60.0,
        'weightSource' => 'default',
        'referenceRecordedOn' => null,
    ];
};

// ブラウザの preflight リクエスト（CORS 確認）への応答
if ($requestMethod === 'OPTIONS') {
    json_response(['ok' => true]);
}

/**
 * 変更: AI推定時に「何相当で計算したか」が分かるノート文言を生成する。
 */
function composeExerciseEstimateNote(string $source, string $inputExercise, string $estimatedExercise, string $rawNote): string
{
    $trimmedRawNote = trim($rawNote);
    if ($source !== 'llm_estimate') {
        return $trimmedRawNote;
    }

    if ($estimatedExercise !== '' && $estimatedExercise !== $inputExercise) {
        $base = sprintf('AI推定: 「%s」相当で計算', $estimatedExercise);
        return $trimmedRawNote !== '' ? $base . '。' . $trimmedRawNote : $base;
    }

    $base = 'AI推定で計算';
    return $trimmedRawNote !== '' ? $base . '。' . $trimmedRawNote : $base;
}

// GET /api/chat/messages — チャット履歴を取得
if ($requestMethod === 'GET' && $requestPath === '/api/chat/messages') {
    json_response(['messages' => $repository->getChatMessages()]);
}

// POST /api/chat/messages — チャットメッセージを送信（固定返信）
if ($requestMethod === 'POST' && $requestPath === '/api/chat/messages') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $text = trim((string) ($body['text'] ?? ''));

    if ($text === '') {
        json_response(['message' => 'text is required'], 422);
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM);
    $replyText = 'ナイス記録です。明日は朝食を高タンパクにして、夜は軽めに調整しましょう。';

    json_response([
        'userMessage' => [
            'id' => 'u-' . bin2hex(random_bytes(6)),
            'role' => 'user',
            'text' => $text,
            'sentAt' => $now,
        ],
        'assistantMessage' => [
            'id' => 'a-' . bin2hex(random_bytes(6)),
            'role' => 'assistant',
            'text' => $replyText,
            'sentAt' => $now,
        ],
    ]);
}

// GET /api/records/daily — 記録画面用の日次データ（体重は DB から取得）
if ($requestMethod === 'GET' && $requestPath === '/api/records/daily') {
    $date = trim((string) ($_GET['date'] ?? WeightRepository::todayDate()));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['message' => 'date must be YYYY-MM-DD'], 422);
    }

    $record = $repository->getDailyRecord();
    $weightSummary = $weightRepository->getSummaryForDate($date);
    $mealSections = $mealEntryRepository->getSectionsForDate($date);
    $stepsSummary = $activityRepository->getStepsForDate($date);
    $exerciseSummary = $activityRepository->getExercisesForDate($date);

    // モックの weight を DB の値で上書き
    $record['date'] = $weightSummary['dateLabel'] ?? WeightRepository::formatDateLabel($date);
    $record['recordedOn'] = $date;
    $record['weight'] = $weightSummary;
    $record['meals'] = $mealSections;
    $record['steps'] = $stepsSummary;
    $record['exercises'] = $exerciseSummary;

    json_response($record);
}

// POST /api/records/steps — 歩数を登録・更新
if ($requestMethod === 'POST' && $requestPath === '/api/records/steps') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $date = trim((string) ($body['date'] ?? WeightRepository::todayDate()));
    $count = $body['count'] ?? null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['message' => 'date must be YYYY-MM-DD'], 422);
    }

    if (!is_numeric($count)) {
        json_response(['message' => 'count is required'], 422);
    }

    try {
        $steps = $activityRepository->upsertSteps($date, (int) round((float) $count));
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response(['steps' => $steps]);
}

// POST /api/records/exercises — 運動を登録
if ($requestMethod === 'POST' && $requestPath === '/api/records/exercises') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $date = trim((string) ($body['date'] ?? WeightRepository::todayDate()));
    $exerciseName = trim((string) ($body['exerciseName'] ?? ''));
    $amount = $body['amount'] ?? null;
    $unit = trim((string) ($body['unit'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['message' => 'date must be YYYY-MM-DD'], 422);
    }

    if (!is_numeric($amount)) {
        json_response(['message' => 'amount is required'], 422);
    }

    try {
        $inputExerciseName = $exerciseName;
        $weightSummary = $weightRepository->getSummaryForDate($date);
        $weightContext = $resolveWeightContext($weightSummary);

        $estimated = $exerciseMetEstimateService->estimate(
            $inputExerciseName,
            (int) round((float) $amount),
            $unit,
            $weightContext['weightKg']
        );
        $estimateNote = composeExerciseEstimateNote(
            $estimated['source'],
            $inputExerciseName,
            $estimated['exercise'],
            $estimated['note']
        );
        $entry = $activityRepository->addExercise(
            $date,
            // 変更: 表示・履歴の運動名はユーザー入力を優先して保存する。
            $inputExerciseName,
            (int) round((float) $amount),
            $unit,
            $estimated['minutes'],
            $estimated['mets'],
            $estimated['calories'],
            $estimated['source'],
            $estimated['confidence'],
            $estimated['isEstimated'],
            $weightContext['weightKg'],
            $weightContext['weightSource'],
            $estimateNote !== '' ? $estimateNote : null
        );
        $exercises = $activityRepository->getExercisesForDate($date);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    } catch (RuntimeException $exception) {
        json_response(['message' => $exception->getMessage()], 502);
    }

    json_response([
        'entry' => $entry,
        'exercises' => $exercises,
        'meta' => [
            'weightKg' => $weightContext['weightKg'],
            'weightSource' => $weightContext['weightSource'],
            'weightRecordedOn' => $weightContext['referenceRecordedOn'],
            'usedDefaultWeight' => $weightContext['weightSource'] === 'default',
            'weightHint' => $weightContext['weightSource'] === 'default'
                ? '体重が未登録のため60kgで計算しています'
                : null,
        ],
    ]);
}

// POST /api/records/exercises/preview — 運動の事前カロリープレビュー
if ($requestMethod === 'POST' && $requestPath === '/api/records/exercises/preview') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $date = trim((string) ($body['date'] ?? WeightRepository::todayDate()));
    $exerciseName = trim((string) ($body['exerciseName'] ?? ''));
    $amount = $body['amount'] ?? null;
    $unit = trim((string) ($body['unit'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['message' => 'date must be YYYY-MM-DD'], 422);
    }
    if (!is_numeric($amount)) {
        json_response(['message' => 'amount is required'], 422);
    }

    try {
        // 変更: 保存前に同じロジックで体重解決・METs推定を実行する。
        $inputExerciseName = $exerciseName;
        $weightSummary = $weightRepository->getSummaryForDate($date);
        $weightContext = $resolveWeightContext($weightSummary);
        $estimated = $exerciseMetEstimateService->estimate(
            $inputExerciseName,
            (int) round((float) $amount),
            $unit,
            $weightContext['weightKg']
        );
        $estimateNote = composeExerciseEstimateNote(
            $estimated['source'],
            $inputExerciseName,
            $estimated['exercise'],
            $estimated['note']
        );
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    } catch (RuntimeException $exception) {
        json_response(['message' => $exception->getMessage()], 502);
    }

    json_response([
        'preview' => [
            'exercise' => $inputExerciseName,
            'estimatedExercise' => $estimated['exercise'],
            'minutes' => $estimated['minutes'],
            'mets' => $estimated['mets'],
            'confidence' => $estimated['confidence'],
            'note' => $estimateNote,
            'source' => $estimated['source'],
            'isEstimated' => $estimated['isEstimated'],
            'caloriesBurned' => $estimated['calories'],
        ],
        'weight' => [
            'kg' => $weightContext['weightKg'],
            'source' => $weightContext['weightSource'],
            'recordedOn' => $weightContext['referenceRecordedOn'],
        ],
    ]);
}

// GET /api/records/exercises/history — 運動履歴を取得
if ($requestMethod === 'GET' && $requestPath === '/api/records/exercises/history') {
    $limit = (int) ($_GET['limit'] ?? 30);
    $history = $activityRepository->getExerciseHistory($limit);
    json_response([
        'history' => $history,
    ]);
}

// POST /api/records/weight — 体重を登録・更新
if ($requestMethod === 'POST' && $requestPath === '/api/records/weight') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $weight = $body['weight'] ?? null;
    $date = trim((string) ($body['date'] ?? WeightRepository::todayDate()));

    if (!is_numeric($weight)) {
        json_response(['message' => 'weight is required'], 422);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['message' => 'date must be YYYY-MM-DD'], 422);
    }

    try {
        $summary = $weightRepository->upsert($date, (float) $weight);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response(['weight' => $summary]);
}

// POST /api/records/meals — 食事を登録
if ($requestMethod === 'POST' && $requestPath === '/api/records/meals') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $date = trim((string) ($body['date'] ?? WeightRepository::todayDate()));
    $mealType = trim((string) ($body['mealType'] ?? ''));
    $foodName = trim((string) ($body['foodName'] ?? ''));
    $calories = $body['calories'] ?? null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['message' => 'date must be YYYY-MM-DD'], 422);
    }

    if (!is_numeric($calories)) {
        json_response(['message' => 'calories is required'], 422);
    }

    try {
        $entry = $mealEntryRepository->addEntry($date, $mealType, $foodName, (int) round((float) $calories));
        $sections = $mealEntryRepository->getSectionsForDate($date);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'entry' => $entry,
        'meals' => $sections,
    ]);
}

// GET /api/records/meals/history — 食事履歴を取得
if ($requestMethod === 'GET' && $requestPath === '/api/records/meals/history') {
    $mealType = trim((string) ($_GET['mealType'] ?? ''));
    $limit = (int) ($_GET['limit'] ?? 30);
    $mealTypeOrNull = $mealType === '' ? null : $mealType;

    try {
        $history = $mealEntryRepository->getHistory($mealTypeOrNull, $limit);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'history' => $history,
    ]);
}

// POST /api/foods/estimate-calories — Claude Haiku 4.5 で食品名からカロリーを推定
if ($requestMethod === 'POST' && $requestPath === '/api/foods/estimate-calories') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $foodName = trim((string) ($body['foodName'] ?? ''));
    $mode = trim((string) ($body['mode'] ?? 'auto'));

    try {
        // 変更: no_web / web を切り替え可能にして検索フローに対応。
        $result = $calorieEstimateService->estimate($foodName, $mode);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    } catch (RuntimeException $exception) {
        json_response(['message' => $exception->getMessage()], 502);
    }

    json_response($result);
}

// POST /api/foods/normalize — Claude Haiku で食品入力を正規化
if ($requestMethod === 'POST' && $requestPath === '/api/foods/normalize') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $foodName = trim((string) ($body['foodName'] ?? ''));

    try {
        $result = $foodNormalizeService->normalize($foodName);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    } catch (RuntimeException $exception) {
        json_response(['message' => $exception->getMessage()], 502);
    }

    json_response($result);
}

// GET /api/profile — 目標体重・身長などのプロフィール
if ($requestMethod === 'GET' && $requestPath === '/api/profile') {
    json_response(['profile' => $userProfileRepository->get()]);
}

// PATCH /api/profile — プロフィールの更新
if ($requestMethod === 'PATCH' && $requestPath === '/api/profile') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $fields = [];

    if (array_key_exists('targetWeightKg', $body)) {
        $value = $body['targetWeightKg'];
        $fields['targetWeightKg'] = is_numeric($value) ? round((float) $value, 1) : null;
    }

    if (array_key_exists('heightCm', $body)) {
        $value = $body['heightCm'];
        $fields['heightCm'] = is_numeric($value) ? round((float) $value, 1) : null;
    }

    if ($fields === []) {
        json_response(['message' => 'No updatable fields provided'], 422);
    }

    try {
        $profile = $userProfileRepository->update($fields);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response(['profile' => $profile]);
}

// GET /api/reports/weekly — グラフ画面用の週次レポート（体重は DB から取得）
if ($requestMethod === 'GET' && $requestPath === '/api/reports/weekly') {
    $endDate = trim((string) ($_GET['endDate'] ?? WeightRepository::todayDate()));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_response(['message' => 'endDate must be YYYY-MM-DD'], 422);
    }

    $report = $repository->getWeeklyReport();
    $points = $weightRepository->getPointsEndingOn($endDate, 7);
    $profile = $userProfileRepository->get();
    $targetWeightKg = $profile['targetWeightKg'];

    $values = array_values(array_filter(
        array_map(static fn (array $point): ?float => $point['value'], $points),
        static fn (?float $value): bool => $value !== null
    ));

    $timezone = new DateTimeZone('Asia/Tokyo');
    $end = new DateTimeImmutable($endDate, $timezone);
    $start = $end->modify('-6 days');
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $startWeekday = $weekdays[(int) $start->format('w')];
    $endWeekday = $weekdays[(int) $end->format('w')];

    $report['rangeLabel'] = sprintf(
        '%s（%s）〜 %s（%s）',
        $start->format('n/j'),
        $startWeekday,
        $end->format('n/j'),
        $endWeekday
    );

    $periodMin = $values !== [] ? min($values) : null;
    $chartBounds = $weightRepository->computeChartBounds($targetWeightKg, $periodMin);
    $first = $values !== [] ? $values[0] : null;
    $last = $values !== [] ? $values[count($values) - 1] : null;

    $report['weight'] = [
        'points' => array_map(
            static fn (array $point): array => [
                'label' => $point['label'],
                'value' => $point['value'],
            ],
            $points
        ),
        'weeklyAverage' => $values !== []
            ? round(array_sum($values) / count($values), 1)
            : null,
        'weeklyDiff' => ($first !== null && $last !== null)
            ? round($last - $first, 1)
            : null,
        'targetWeightKg' => $targetWeightKg,
        'targetDiff' => ($targetWeightKg !== null && $last !== null)
            ? round($targetWeightKg - $last, 1)
            : null,
        'chartMin' => $chartBounds['min'],
        'chartMax' => $chartBounds['max'],
    ];

    json_response($report);
}

// どのルートにも一致しなかった場合
json_response(['message' => 'Not Found'], 404);
