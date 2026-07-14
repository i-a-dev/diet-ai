<?php

declare(strict_types=1);

/**
 * API の JSON レスポンスを返す共通関数。
 * CORS ヘッダー付きで JSON を出力し、処理を終了する。
 *
 * @param array<string, mixed> $payload レスポンス body として返す連想配列
 * @param int $statusCode HTTP ステータスコード（200, 422, 404 など）
 */
function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    // ブラウザ（React）から API を呼べるように CORS を許可
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Server-Sent Events (SSE) 用のレスポンスヘッダーを送出する。
 * プロキシ／PHP のバッファリングを止め、トークン単位で即時フラッシュできるようにする。
 */
function sse_headers(): void
{
    http_response_code(200);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

    // 既存の出力バッファをすべて開放し、以降は即時フラッシュする
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
}

/**
 * SSE イベントを1件送出し、クライアントへ即座に届ける。
 *
 * @param array<string, mixed> $data
 */
function sse_event(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}
