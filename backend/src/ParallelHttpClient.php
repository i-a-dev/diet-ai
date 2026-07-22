<?php

declare(strict_types=1);

/**
 * curl_multi による制限付き並列 HTTP GET。
 */
final class ParallelHttpClient
{
    /**
     * @param list<string> $urls
     * @return array<string, array{ok: bool, body: ?string, http_status: int, duration_ms: int, response_size: int, timeout: bool}>
     */
    public function getMany(
        array $urls,
        SearchRuntimeContext $runtime,
        ?callable $isAllowedUrl = null,
        ?SearchTiming $timing = null,
        string $requestType = 'html',
    ): array {
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        $results = [];
        if ($urls === [] || !function_exists('curl_multi_init')) {
            foreach ($urls as $url) {
                $results[$url] = $this->getOne($url, $runtime, $isAllowedUrl, $timing, $requestType);
            }

            return $results;
        }

        $queue = $urls;
        $activeByHost = [];
        $handles = [];
        $mh = curl_multi_init();
        if ($mh === false) {
            foreach ($urls as $url) {
                $results[$url] = $this->getOne($url, $runtime, $isAllowedUrl, $timing, $requestType);
            }

            return $results;
        }

        $started = [];
        $open = 0;

        $launch = function () use (
            &$queue,
            &$handles,
            &$activeByHost,
            &$open,
            &$started,
            $mh,
            $runtime,
            $isAllowedUrl,
        ): void {
            while (
                $queue !== []
                && $open < $runtime->maxParallelFetches
            ) {
                $url = array_shift($queue);
                if ($url === null) {
                    break;
                }
                if ($isAllowedUrl !== null && !$isAllowedUrl($url)) {
                    $handles['skip:' . $url] = ['url' => $url, 'ch' => null, 'host' => '', 'skipped' => true];
                    continue;
                }
                $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
                $hostCount = $activeByHost[$host] ?? 0;
                if ($host !== '' && $hostCount >= $runtime->maxParallelPerHost) {
                    array_unshift($queue, $url);
                    break;
                }

                $ch = curl_init($url);
                if ($ch === false) {
                    continue;
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => $runtime->maxRedirects,
                    CURLOPT_CONNECTTIMEOUT_MS => $runtime->connectTimeoutMs,
                    CURLOPT_TIMEOUT_MS => $runtime->requestTimeoutMs,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DietAI/1.0)',
                    CURLOPT_ENCODING => '',
                ]);
                curl_multi_add_handle($mh, $ch);
                $id = (int) $ch;
                $handles[$id] = ['url' => $url, 'ch' => $ch, 'host' => $host, 'skipped' => false];
                $started[$id] = hrtime(true);
                if ($host !== '') {
                    $activeByHost[$host] = $hostCount + 1;
                }
                $open++;
            }
        };

        $launch();

        do {
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = (int) $ch;
                $meta = $handles[$id] ?? null;
                if (!is_array($meta)) {
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }
                $url = (string) $meta['url'];
                $body = curl_multi_getcontent($ch);
                $errno = curl_errno($ch);
                $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $durationMs = (int) round(((hrtime(true) - ($started[$id] ?? hrtime(true))) / 1e6));
                $size = is_string($body) ? strlen($body) : 0;
                if ($size > $runtime->maxResponseBytes && is_string($body)) {
                    $body = substr($body, 0, $runtime->maxResponseBytes);
                    $size = strlen($body);
                }
                $ok = $errno === 0 && is_string($body) && $httpStatus > 0 && $httpStatus < 400;
                $results[$url] = [
                    'ok' => $ok,
                    'body' => $ok ? $body : null,
                    'http_status' => $httpStatus,
                    'duration_ms' => $durationMs,
                    'response_size' => $size,
                    'timeout' => in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED], true),
                ];
                $timing?->recordHttp([
                    'request_type' => $requestType,
                    'summary' => $this->safeUrlSummary($url),
                    'duration_ms' => $durationMs,
                    'http_status' => $httpStatus,
                    'response_size' => $size,
                    'timeout' => $results[$url]['timeout'],
                    'cache_hit' => false,
                ]);

                $host = (string) ($meta['host'] ?? '');
                if ($host !== '' && isset($activeByHost[$host])) {
                    $activeByHost[$host] = max(0, $activeByHost[$host] - 1);
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($handles[$id], $started[$id]);
                $open--;
                $launch();
            }

            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running || $open > 0 || $queue !== []);

        foreach ($handles as $meta) {
            if (($meta['skipped'] ?? false) === true) {
                $url = (string) $meta['url'];
                $results[$url] = [
                    'ok' => false,
                    'body' => null,
                    'http_status' => 0,
                    'duration_ms' => 0,
                    'response_size' => 0,
                    'timeout' => false,
                ];
            }
        }

        curl_multi_close($mh);

        return $results;
    }

    /**
     * @return array{ok: bool, body: ?string, http_status: int, duration_ms: int, response_size: int, timeout: bool}
     */
    public function getOne(
        string $url,
        SearchRuntimeContext $runtime,
        ?callable $isAllowedUrl = null,
        ?SearchTiming $timing = null,
        string $requestType = 'html',
    ): array {
        if ($isAllowedUrl !== null && !$isAllowedUrl($url)) {
            return [
                'ok' => false,
                'body' => null,
                'http_status' => 0,
                'duration_ms' => 0,
                'response_size' => 0,
                'timeout' => false,
            ];
        }

        $t0 = hrtime(true);
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'body' => null,
                'http_status' => 0,
                'duration_ms' => 0,
                'response_size' => 0,
                'timeout' => false,
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'body' => null,
                'http_status' => 0,
                'duration_ms' => 0,
                'response_size' => 0,
                'timeout' => false,
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => $runtime->maxRedirects,
            CURLOPT_CONNECTTIMEOUT_MS => $runtime->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $runtime->requestTimeoutMs,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DietAI/1.0)',
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $durationMs = (int) round((hrtime(true) - $t0) / 1e6);
        $size = is_string($body) ? strlen($body) : 0;
        if ($size > $runtime->maxResponseBytes && is_string($body)) {
            $body = substr($body, 0, $runtime->maxResponseBytes);
            $size = strlen($body);
        }
        $ok = $errno === 0 && is_string($body) && $httpStatus > 0 && $httpStatus < 400;
        $timeout = in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED], true);
        $timing?->recordHttp([
            'request_type' => $requestType,
            'summary' => $this->safeUrlSummary($url),
            'duration_ms' => $durationMs,
            'http_status' => $httpStatus,
            'response_size' => $size,
            'timeout' => $timeout,
            'cache_hit' => false,
        ]);

        return [
            'ok' => $ok,
            'body' => $ok ? $body : null,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'response_size' => $size,
            'timeout' => $timeout,
        ];
    }

    private function safeUrlSummary(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'invalid_url';
        }
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '/');

        return $host . mb_substr($path, 0, 80);
    }
}
