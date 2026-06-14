<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/MockRepository.php';

$repository = new MockRepository();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($requestMethod === 'OPTIONS') {
    json_response(['ok' => true]);
}

if ($requestMethod === 'GET' && $requestPath === '/api/chat/messages') {
    json_response(['messages' => $repository->getChatMessages()]);
}

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

if ($requestMethod === 'GET' && $requestPath === '/api/records/daily') {
    json_response($repository->getDailyRecord());
}

if ($requestMethod === 'GET' && $requestPath === '/api/reports/weekly') {
    json_response($repository->getWeeklyReport());
}

json_response(['message' => 'Not Found'], 404);
