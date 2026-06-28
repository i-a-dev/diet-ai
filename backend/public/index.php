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
require_once __DIR__ . '/../src/CalorieEstimateService.php';
require_once __DIR__ . '/../src/ExerciseMetEstimateService.php';
require_once __DIR__ . '/../src/UserProfileRepository.php';
require_once __DIR__ . '/../src/ChatCoachService.php';
require_once __DIR__ . '/../src/ChatMessageRepository.php';

$weightRepository = new WeightRepository();
$userProfileRepository = new UserProfileRepository();
$mealEntryRepository = new MealEntryRepository();
$activityRepository = new ActivityRepository();
$chatMessageRepository = new ChatMessageRepository();
$calorieEstimateService = new CalorieEstimateService();
$exerciseMetEstimateService = new ExerciseMetEstimateService();
$chatCoachService = new ChatCoachService(
    $userProfileRepository,
    $weightRepository,
    $mealEntryRepository,
    $activityRepository,
    $chatMessageRepository
);
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

// GET /api/user/profile — ユーザープロフィール（身長・目標体重）の取得
if ($requestMethod === 'GET' && $requestPath === '/api/user/profile') {
    json_response(['profile' => $userProfileRepository->get()]);
}

// PUT /api/user/profile — ユーザープロフィール（身長・目標体重）の更新
if ($requestMethod === 'PUT' && $requestPath === '/api/user/profile') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $fields = [];

    if (array_key_exists('targetWeightKg', $body)) {
        $targetWeightKg = $body['targetWeightKg'];
        if ($targetWeightKg !== null && !is_numeric($targetWeightKg)) {
            json_response(['message' => 'targetWeightKg must be a number or null'], 422);
        }
        $fields['targetWeightKg'] = $targetWeightKg === null ? null : round((float) $targetWeightKg, 1);
    }

    if (array_key_exists('heightCm', $body)) {
        $heightCm = $body['heightCm'];
        if ($heightCm !== null && !is_numeric($heightCm)) {
            json_response(['message' => 'heightCm must be a number or null'], 422);
        }
        $fields['heightCm'] = $heightCm === null ? null : round((float) $heightCm, 1);
    }

    if ($fields === []) {
        json_response(['message' => 'targetWeightKg or heightCm is required'], 422);
    }

    try {
        $profile = $userProfileRepository->update($fields);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response(['profile' => $profile]);
}

// GET /api/chat/messages — チャット履歴の取得
if ($requestMethod === 'GET' && $requestPath === '/api/chat/messages') {
    $limit = (int) ($_GET['limit'] ?? ChatMessageRepository::DISPLAY_LIMIT);
    if ($limit < 1 || $limit > ChatMessageRepository::MAX_STORED_MESSAGES) {
        json_response(['message' => 'limit is out of range'], 422);
    }

    json_response([
        'messages' => $chatMessageRepository->listForDisplay($limit),
    ]);
}

// POST /api/chat — AIコーチとの会話
if ($requestMethod === 'POST' && $requestPath === '/api/chat') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $content = trim((string) ($body['content'] ?? ''));

    if ($content === '') {
        json_response(['message' => 'content is required'], 422);
    }

    try {
        $result = $chatCoachService->sendUserMessage($content);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    } catch (RuntimeException $exception) {
        json_response(['message' => $exception->getMessage()], 502);
    }

    json_response($result);
}

// GET /api/records/daily — 記録画面用の日次データ
if ($requestMethod === 'GET' && $requestPath === '/api/records/daily') {
    $date = trim((string) ($_GET['date'] ?? WeightRepository::todayDate()));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['message' => 'date must be YYYY-MM-DD'], 422);
    }

    $weightSummary = $weightRepository->getSummaryForDate($date);
    $mealSections = $mealEntryRepository->getSectionsForDate($date);
    $stepsSummary = $activityRepository->getStepsForDate($date);
    $exerciseSummary = $activityRepository->getExercisesForDate($date);

    json_response([
        'date' => $weightSummary['dateLabel'] ?? WeightRepository::formatDateLabel($date),
        'recordedOn' => $date,
        'weight' => $weightSummary,
        'meals' => $mealSections,
        'steps' => $stepsSummary,
        'exercises' => $exerciseSummary,
    ]);
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

// GET /api/reports/weight-timeline — 体重グラフの横スクロール用時系列データ
if ($requestMethod === 'GET' && $requestPath === '/api/reports/weight-timeline') {
    $today = WeightRepository::todayDate();
    $endDate = trim((string) ($_GET['endDate'] ?? $today));
    $visibleDays = (int) ($_GET['visibleDays'] ?? 7);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_response(['message' => 'endDate must be YYYY-MM-DD'], 422);
    }

    if ($visibleDays < 1 || $visibleDays > 3660) {
        json_response(['message' => 'visibleDays must be between 1 and 3660'], 422);
    }

    $timelineRange = $weightRepository->resolveTimelineRange($endDate, $visibleDays);
    $startDate = $timelineRange['fetchStart'];

    $points = $weightRepository->getPointsBetween($startDate, $endDate);
    $profile = $userProfileRepository->get();
    $targetWeightKg = $profile['targetWeightKg'];
    $values = array_values(array_filter(
        array_map(static fn (array $point): ?float => $point['value'], $points),
        static fn (?float $value): bool => $value !== null
    ));
    $periodMin = $values !== [] ? min($values) : null;
    $chartBounds = $weightRepository->computeChartBounds($targetWeightKg, $periodMin);

    json_response([
        'weight' => [
            'points' => $points,
            'targetWeightKg' => $targetWeightKg,
            'chartMin' => $chartBounds['min'],
            'chartMax' => $chartBounds['max'],
            'scrollFloor' => $timelineRange['scrollFloor'],
        ],
    ]);
}

/**
 * 棒グラフ用の日次メトリクス上限を、見やすい目盛りに切り上げる。
 */
function computeCalorieChartMax(int $maxValue): int
{
    if ($maxValue <= 0) {
        return 3000;
    }

    $step = 1000;
    $rounded = (int) (ceil($maxValue / $step) * $step);

    return max($step, $rounded);
}

/**
 * 棒グラフ用の歩数目盛り上限を、見やすい値に切り上げる。
 */
function computeStepChartMax(int $maxValue): int
{
    if ($maxValue <= 0) {
        return 12000;
    }

    $candidates = [4000, 8000, 12000, 16000, 20000, 24000, 30000];
    foreach ($candidates as $candidate) {
        if ($maxValue <= $candidate) {
            return $candidate;
        }
    }

    return (int) (ceil($maxValue / 4000) * 4000);
}

// GET /api/reports/metric-timeline — 食事・運動・歩数の棒グラフ用日次データ
if ($requestMethod === 'GET' && $requestPath === '/api/reports/metric-timeline') {
    $metric = trim((string) ($_GET['metric'] ?? ''));
    $endDate = trim((string) ($_GET['endDate'] ?? WeightRepository::todayDate()));
    $visibleDays = (int) ($_GET['visibleDays'] ?? 7);

    if (!in_array($metric, ['meals', 'exercise', 'steps'], true)) {
        json_response(['message' => 'metric must be meals|exercise|steps'], 422);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_response(['message' => 'endDate must be YYYY-MM-DD'], 422);
    }

    if ($visibleDays < 1 || $visibleDays > 3660) {
        json_response(['message' => 'visibleDays must be between 1 and 3660'], 422);
    }

    $timezone = new DateTimeZone('Asia/Tokyo');
    $end = new DateTimeImmutable($endDate, $timezone);
    $startDate = $end->modify(sprintf('-%d days', max(0, $visibleDays - 1)))->format('Y-m-d');

    if ($metric === 'meals') {
        $points = $mealEntryRepository->getDailyTotalsBetween($startDate, $endDate);
    } elseif ($metric === 'exercise') {
        $points = $activityRepository->getDailyExerciseCaloriesBetween($startDate, $endDate);
    } else {
        $points = $activityRepository->getDailyStepsBetween($startDate, $endDate);
    }

    $values = array_column($points, 'value');
    $maxValue = $values !== [] ? max($values) : 0;
    $chartMax = $metric === 'steps'
        ? computeStepChartMax($maxValue)
        : computeCalorieChartMax($maxValue);
    $average = $values !== []
        ? (int) round(array_sum($values) / count($values))
        : null;

    json_response([
        'metric' => $metric,
        'points' => $points,
        'chartMax' => $chartMax,
        'average' => $average,
    ]);
}

// どのルートにも一致しなかった場合
json_response(['message' => 'Not Found'], 404);
