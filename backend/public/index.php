<?php

declare(strict_types=1);

/**
 * API エントリーポイント。
 * リクエストの URL と HTTP メソッドに応じて、各 Repository を呼び出し JSON を返す。
 */

require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/WeightRepository.php';
require_once __DIR__ . '/../src/MockRepository.php';
require_once __DIR__ . '/../src/CalorieEstimateService.php';
require_once __DIR__ . '/../src/FoodNormalizeService.php';

$repository = new MockRepository();
$weightRepository = new WeightRepository();
$calorieEstimateService = new CalorieEstimateService();
$foodNormalizeService = new FoodNormalizeService();
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

    // モックの weight を DB の値で上書き
    $record['date'] = $weightSummary['dateLabel'] ?? WeightRepository::formatDateLabel($date);
    $record['recordedOn'] = $date;
    $record['weight'] = $weightSummary ?? [
        'current' => null,
        'diffFromPreviousDay' => null,
        'recordedOn' => $date,
        'dateLabel' => WeightRepository::formatDateLabel($date),
    ];

    json_response($record);
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
