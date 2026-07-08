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
require_once __DIR__ . '/../src/UserFoodRepository.php';
require_once __DIR__ . '/../src/FoodSearchNormalizer.php';
require_once __DIR__ . '/../src/FoodSearchAliasRepository.php';
require_once __DIR__ . '/../src/FoodRegistrationEventRepository.php';
require_once __DIR__ . '/../src/DailyNutritionSummaryRepository.php';
require_once __DIR__ . '/../src/CalorieEstimateService.php';
require_once __DIR__ . '/../src/BraveSearchService.php';
require_once __DIR__ . '/../src/NutritionPageExtractor.php';
require_once __DIR__ . '/../src/BraveNutritionSearchService.php';
require_once __DIR__ . '/../src/ExerciseMetEstimateService.php';
require_once __DIR__ . '/../src/UserProfileRepository.php';
require_once __DIR__ . '/../src/CalorieGoalCalculator.php';
require_once __DIR__ . '/../src/ChatCoachService.php';
require_once __DIR__ . '/../src/ChatMessageRepository.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/MailService.php';

$authService = new AuthService();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/**
 * Authorization ヘッダーから Bearer トークンを取り出す。
 */
$extractBearerToken = static function (): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
        return null;
    }

    return $matches[1];
};

// ブラウザの preflight リクエスト（CORS 確認）への応答
if ($requestMethod === 'OPTIONS') {
    json_response(['ok' => true]);
}

// POST /api/auth/register — 新規登録
if ($requestMethod === 'POST' && $requestPath === '/api/auth/register') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    try {
        $result = $authService->register($email, $password);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    } catch (RuntimeException $exception) {
        json_response(['message' => $exception->getMessage()], 502);
    }

    json_response($result);
}

// POST /api/auth/login — ログイン
if ($requestMethod === 'POST' && $requestPath === '/api/auth/login') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    try {
        $result = $authService->login($email, $password);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response($result);
}

// GET /api/auth/verify-email — メール認証
if ($requestMethod === 'GET' && $requestPath === '/api/auth/verify-email') {
    $token = trim((string) ($_GET['token'] ?? ''));

    try {
        $result = $authService->verifyEmail($token);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response($result);
}

// POST /api/auth/resend-verification — 確認メール再送
if ($requestMethod === 'POST' && $requestPath === '/api/auth/resend-verification') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

    $email = trim((string) ($body['email'] ?? ''));

    try {
        $result = $authService->resendVerificationEmail($email);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    } catch (RuntimeException $exception) {
        json_response(['message' => $exception->getMessage()], 502);
    }

    json_response($result);
}

// POST /api/auth/forgot-password — パスワード再設定メール送信
if ($requestMethod === 'POST' && $requestPath === '/api/auth/forgot-password') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

    $email = trim((string) ($body['email'] ?? ''));

    try {
        $result = $authService->requestPasswordReset($email);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    } catch (RuntimeException $exception) {
        json_response(['message' => $exception->getMessage()], 502);
    }

    json_response($result);
}

// POST /api/auth/reset-password — パスワード再設定
if ($requestMethod === 'POST' && $requestPath === '/api/auth/reset-password') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

    $token = trim((string) ($body['token'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    try {
        $result = $authService->resetPassword($token, $password);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response($result);
}

$authToken = $extractBearerToken();
$authenticatedUser = $authService->resolveUserFromToken($authToken);

// POST /api/auth/logout — ログアウト
if ($requestMethod === 'POST' && $requestPath === '/api/auth/logout') {
    if ($authToken !== null && $authToken !== '') {
        $authService->logout($authToken);
    }
    json_response(['ok' => true]);
}

// GET /api/auth/me — ログイン中のユーザー情報
if ($requestMethod === 'GET' && $requestPath === '/api/auth/me') {
    if ($authenticatedUser === null) {
        json_response(['message' => 'Unauthorized'], 401);
    }

    json_response(['user' => $authenticatedUser]);
}

if ($authenticatedUser === null) {
    json_response(['message' => 'Unauthorized'], 401);
}

$userId = (int) $authenticatedUser['id'];
$weightRepository = new WeightRepository($userId);
$userProfileRepository = new UserProfileRepository($userId);
$mealEntryRepository = new MealEntryRepository($userId);
$userFoodRepository = new UserFoodRepository();
$foodSearchAliasRepository = new FoodSearchAliasRepository();
$foodRegistrationEventRepository = new FoodRegistrationEventRepository($userId);
$dailyNutritionSummaryRepository = new DailyNutritionSummaryRepository($userId);
$activityRepository = new ActivityRepository($userId);
$chatMessageRepository = new ChatMessageRepository($userId);
$calorieEstimateService = new CalorieEstimateService();
$exerciseMetEstimateService = new ExerciseMetEstimateService();
$chatCoachService = new ChatCoachService(
    $userProfileRepository,
    $weightRepository,
    $mealEntryRepository,
    $dailyNutritionSummaryRepository,
    $activityRepository,
    $chatMessageRepository
);

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

// GET /api/user/profile — ユーザープロフィールの取得
if ($requestMethod === 'GET' && $requestPath === '/api/user/profile') {
    $profile = $userProfileRepository->get();
    json_response([
        'profile' => $profile,
        'calorieGoal' => CalorieGoalCalculator::calculate($profile),
    ]);
}

// PUT /api/user/profile — ユーザープロフィールの更新
if ($requestMethod === 'PUT' && $requestPath === '/api/user/profile') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

    $fields = [];
    $numericFields = [
        'heightCm',
        'currentWeightKg',
        'targetWeightKg',
        'targetPaceKgPerMonth',
    ];

    foreach ($numericFields as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }

        $value = $body[$field];
        if ($value !== null && !is_numeric($value)) {
            json_response(['message' => sprintf('%s must be a number or null', $field)], 422);
        }
        $fields[$field] = $value === null ? null : round((float) $value, 1);
    }

    $stringFields = [
        'gender',
        'birthDate',
        'activityLevel',
        'dietGoal',
        'desiredDietMethod',
        'allergiesDislikes',
        'pastDietExperience',
        'coachNotes',
    ];

    foreach ($stringFields as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }

        $value = $body[$field];
        if ($value !== null && !is_string($value)) {
            json_response(['message' => sprintf('%s must be a string or null', $field)], 422);
        }
        $fields[$field] = $value;
    }

    if ($fields === []) {
        json_response(['message' => 'At least one profile field is required'], 422);
    }

    try {
        $profile = $userProfileRepository->update($fields);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'profile' => $profile,
        'calorieGoal' => CalorieGoalCalculator::calculate($profile),
    ]);
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
    $nutritionSummary = $dailyNutritionSummaryRepository->getForDate($date);
    if ($nutritionSummary === null) {
        $nutritionSummary = $dailyNutritionSummaryRepository->recalculateForDate($date);
    }

    json_response([
        'date' => $weightSummary['dateLabel'] ?? WeightRepository::formatDateLabel($date),
        'recordedOn' => $date,
        'weight' => $weightSummary,
        'meals' => $mealSections,
        'steps' => $stepsSummary,
        'exercises' => $exerciseSummary,
        'nutritionSummary' => $nutritionSummary,
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
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

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

    $caloriesEdited = filter_var($body['caloriesEdited'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $calorieSource = trim((string) ($body['calorieSource'] ?? ''));
    $sourceUrl = trim((string) ($body['sourceUrl'] ?? ''));
    $confidence = trim((string) ($body['confidence'] ?? ''));

    $entryOptions = [
        'caloriesEdited' => $caloriesEdited,
        'calorieSource' => $calorieSource === '' ? null : $calorieSource,
        'sourceUrl' => $sourceUrl === '' ? null : $sourceUrl,
        'confidence' => $confidence === '' ? null : $confidence,
        'foodId' => $body['foodId'] ?? null,
        'rawInput' => $body['rawInput'] ?? null,
        'amount' => $body['amount'] ?? null,
        'unit' => $body['unit'] ?? null,
        'servingLabel' => $body['servingLabel'] ?? null,
        'servingWeightG' => $body['servingWeightG'] ?? null,
        'proteinG' => $body['proteinG'] ?? null,
        'fatG' => $body['fatG'] ?? null,
        'carbsG' => $body['carbsG'] ?? null,
        'fiberG' => $body['fiberG'] ?? null,
        'sodiumMg' => $body['sodiumMg'] ?? null,
    ];

    try {
        $entry = $mealEntryRepository->addEntry(
            $date,
            $mealType,
            $foodName,
            (int) round((float) $calories),
            $entryOptions,
        );
        $sections = $mealEntryRepository->getSectionsForDate($date);
        $nutritionSummary = $dailyNutritionSummaryRepository->recalculateForDate($date);

        if (isset($body['registrationMetrics']) && is_array($body['registrationMetrics'])) {
            $foodRegistrationEventRepository->recordFromMetrics(
                (int) $entry['id'],
                $date,
                $mealType,
                $foodName,
                (int) round((float) $calories),
                $caloriesEdited,
                $entry['calorieSource'] ?? null,
                isset($entry['foodId']) ? (int) $entry['foodId'] : null,
                $body['registrationMetrics'],
            );
        }
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'entry' => $entry,
        'meals' => $sections,
        'nutritionSummary' => $nutritionSummary,
    ]);
}

// DELETE /api/records/meals/{id} — 食事記録を削除
if ($requestMethod === 'DELETE' && preg_match('#^/api/records/meals/(\d+)$#', $requestPath, $mealDeleteMatches) === 1) {
    $entryId = (int) $mealDeleteMatches[1];

    try {
        $result = $mealEntryRepository->deleteEntry($entryId);
        $nutritionSummary = $dailyNutritionSummaryRepository->recalculateForDate($result['recordedOn']);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 404);
    }

    json_response([
        'recordedOn' => $result['recordedOn'],
        'meals' => $result['meals'],
        'nutritionSummary' => $nutritionSummary,
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

// GET /api/foods/aliases/search — 学習済みエイリアスを検索
if ($requestMethod === 'GET' && $requestPath === '/api/foods/aliases/search') {
    $query = trim((string) ($_GET['q'] ?? ''));

    try {
        $candidates = $foodSearchAliasRepository->searchByQuery($query, $userId);
        $queryNormalized = FoodSearchNormalizer::normalize($query);
        $needsConfirmation = $foodSearchAliasRepository->needsConfirmation($candidates, $query);
        $autoConfirm = $foodSearchAliasRepository->shouldAutoConfirm($candidates, $query);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'queryNormalized' => $queryNormalized,
        'candidates' => $candidates,
        'needsConfirmation' => $needsConfirmation,
        'autoConfirm' => $autoConfirm,
    ]);
}

// POST /api/foods/aliases — エイリアスを作成または selection_count を増やす
if ($requestMethod === 'POST' && $requestPath === '/api/foods/aliases') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

    $rawQuery = trim((string) ($body['rawQuery'] ?? ''));
    $foodId = (int) ($body['foodId'] ?? 0);
    $source = trim((string) ($body['source'] ?? 'user_selected'));

    try {
        $result = $foodSearchAliasRepository->upsert($rawQuery, $foodId, $source, $userId);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response($result);
}

// POST /api/foods/aliases/{id}/select — 候補選択時に selection_count を増やす
if ($requestMethod === 'POST' && preg_match('#^/api/foods/aliases/(\d+)/select$#', $requestPath, $aliasSelectMatches) === 1) {
    $aliasId = (int) $aliasSelectMatches[1];

    try {
        $alias = $foodSearchAliasRepository->incrementSelection($aliasId);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'alias' => $alias,
    ]);
}

// GET /api/foods/search — 自前食品DBを検索
if ($requestMethod === 'GET' && $requestPath === '/api/foods/search') {
    $query = trim((string) ($_GET['q'] ?? ''));

    try {
        $food = $userFoodRepository->searchBestMatch($query);
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'food' => $food,
    ]);
}

// POST /api/foods — 自前食品DBに登録（同一 displayName は上書き）
if ($requestMethod === 'POST' && $requestPath === '/api/foods') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        json_response(['message' => 'Invalid JSON body'], 422);
    }

    $displayName = trim((string) ($body['displayName'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    $amount = $body['amount'] ?? 1;
    $unit = trim((string) ($body['unit'] ?? '食'));
    $calories = $body['calories'] ?? null;
    $source = trim((string) ($body['source'] ?? 'ai_web_search'));
    $rawInput = trim((string) ($body['rawInput'] ?? ''));
    $rawInputOrNull = $rawInput === '' ? null : $rawInput;
    $sourceUrl = trim((string) ($body['sourceUrl'] ?? ''));
    $sourceUrlOrNull = $sourceUrl === '' ? null : $sourceUrl;

    if (!is_numeric($amount)) {
        json_response(['message' => 'amount is required'], 422);
    }
    if (!is_numeric($calories)) {
        json_response(['message' => 'calories is required'], 422);
    }

    try {
        $food = $userFoodRepository->upsert(
            $displayName,
            $name,
            (float) $amount,
            $unit,
            (int) round((float) $calories),
            $source,
            $rawInputOrNull,
            $sourceUrlOrNull,
        );
    } catch (InvalidArgumentException $exception) {
        json_response(['message' => $exception->getMessage()], 422);
    }

    json_response([
        'food' => $food,
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
