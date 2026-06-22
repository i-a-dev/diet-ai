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

$repository = new MockRepository();
$weightRepository = new WeightRepository();
$mealEntryRepository = new MealEntryRepository();
$activityRepository = new ActivityRepository();
$calorieEstimateService = new CalorieEstimateService();
$foodNormalizeService = new FoodNormalizeService();
$exerciseMetEstimateService = new ExerciseMetEstimateService();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// ブラウザの preflight リクエスト（CORS 確認）への応答
if ($requestMethod === 'OPTIONS') {
    json_response(['ok' => true]);
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
        $weightSummary = $weightRepository->getSummaryForDate($date);
        $resolvedWeight = $weightSummary['current'] ?? $weightSummary['referenceWeight'] ?? 60.0;
        $usedDefaultWeight = !is_numeric($weightSummary['current']) && !is_numeric($weightSummary['referenceWeight']);

        $estimated = $exerciseMetEstimateService->estimate(
            $exerciseName,
            (int) round((float) $amount),
            $unit,
            (float) $resolvedWeight
        );
        $entry = $activityRepository->addExercise(
            $date,
            $estimated['exercise'],
            (int) round((float) $amount),
            $unit,
            $estimated['minutes'],
            $estimated['mets'],
            $estimated['calories'],
            $estimated['source'],
            $estimated['confidence'],
            $estimated['isEstimated'],
            $estimated['note'] !== '' ? $estimated['note'] : null
        );
        $exercises = $activityRepository->getExercisesForDate($date);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'entry' => $entry,
        'exercises' => $exercises,
        'meta' => [
            'weightKg' => (float) $resolvedWeight,
            'usedDefaultWeight' => $usedDefaultWeight,
            'weightHint' => $usedDefaultWeight ? '体重を登録するとより正確になります' : null,
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

// GET /api/reports/weekly — グラフ画面用の週次レポート（体重は DB から取得）
if ($requestMethod === 'GET' && $requestPath === '/api/reports/weekly') {
    $endDate = trim((string) ($_GET['endDate'] ?? WeightRepository::todayDate()));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_response(['message' => 'endDate must be YYYY-MM-DD'], 422);
    }

    $report = $repository->getWeeklyReport();
    $points = $weightRepository->getPointsEndingOn($endDate, 7);

    // DB に体重データがあれば、モックの weight 部分を上書き
    if ($points !== []) {
        $values = array_column($points, 'value');
        $first = $values[0];
        $last = $values[count($values) - 1];
        $average = round(array_sum($values) / count($values), 1);

        $timezone = new DateTimeZone('Asia/Tokyo');
        $start = (new DateTimeImmutable($points[0]['date'], $timezone))->format('n/j');
        $end = (new DateTimeImmutable($points[count($points) - 1]['date'], $timezone))->format('n/j');
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
        $startWeekday = $weekdays[(int) (new DateTimeImmutable($points[0]['date'], $timezone))->format('w')];
        $endWeekday = $weekdays[(int) (new DateTimeImmutable($points[count($points) - 1]['date'], $timezone))->format('w')];

        $report['rangeLabel'] = sprintf('%s（%s）〜 %s（%s）', $start, $startWeekday, $end, $endWeekday);
        $report['weight'] = [
            'points' => array_map(
                static fn (array $point): array => [
                    'label' => $point['label'],
                    'value' => $point['value'],
                ],
                $points
            ),
            'weeklyAverage' => $average,
            'weeklyDiff' => round($last - $first, 1),
            'targetDiff' => round($last - 57.0, 1),
        ];
    }

    json_response($report);
}

// どのルートにも一致しなかった場合
json_response(['message' => 'Not Found'], 404);
