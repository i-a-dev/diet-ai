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
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
