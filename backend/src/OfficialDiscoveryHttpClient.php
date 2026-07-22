<?php

declare(strict_types=1);

/**
 * 公式探索用の SSRF 対策付き HTTP クライアント。
 */
final class OfficialDiscoveryHttpClient
{
    private const MAX_BYTES = 3_145_728;
    private const TIMEOUT_SECONDS = 12;

    /** @var callable(string): ?string|null */
    private $fetcherOverride;

    /**
     * @param callable(string): ?string|null $fetcherOverride
     */
    public function __construct(?callable $fetcherOverride = null)
    {
        $this->fetcherOverride = $fetcherOverride;
    }

    public function withFetcher(?callable $fetcher): self
    {
        return new self($fetcher);
    }

    public function fetch(string $url, ?string $allowedDomain = null): ?string
    {
        if (!$this->isAllowedUrl($url, $allowedDomain)) {
            return null;
        }

        if ($this->fetcherOverride !== null) {
            return ($this->fetcherOverride)($url);
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DietAI-OfficialDiscovery/1.0)',
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        // 手動リダイレクト（最大3回）し、各 hop で公式ドメインを再検証
        $hops = 0;
        while ($hops < 3 && $code >= 300 && $code < 400 && $redirect !== '') {
            if (!$this->isAllowedUrl($redirect, $allowedDomain)) {
                return null;
            }
            $ch = curl_init($redirect);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DietAI-OfficialDiscovery/1.0)',
                CURLOPT_ENCODING => '',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            $hops++;
        }

        if (!is_string($body) || $body === '' || $code >= 400) {
            return null;
        }

        if (strlen($body) > self::MAX_BYTES) {
            $body = substr($body, 0, self::MAX_BYTES);
        }

        return $body;
    }

    public function isAllowedUrl(string $url, ?string $allowedDomain = null): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = mb_strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host)) {
                return false;
            }
        } else {
            $resolved = gethostbynamel($host);
            if (is_array($resolved)) {
                foreach ($resolved as $ip) {
                    if (!$this->isPublicIp($ip)) {
                        return false;
                    }
                }
            }
        }

        if ($allowedDomain !== null && $allowedDomain !== '') {
            $allowed = mb_strtolower(trim($allowedDomain));
            if ($host !== $allowed && !str_ends_with($host, '.' . $allowed)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
