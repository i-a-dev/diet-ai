<?php

declare(strict_types=1);

/**
 * Profile の path pattern を正規表現へコンパイルする。
 */
final class OfficialPathPatternMatcher
{
    /**
     * @param list<string> $patterns
     */
    public function matchesAny(string $path, array $patterns): bool
    {
        $path = $this->normalizePath($path);
        if ($patterns === []) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if ($this->matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function matches(string $path, string $pattern): bool
    {
        $path = $this->normalizePath($path);
        $regex = $this->toRegex($pattern);

        return preg_match($regex, $path) === 1;
    }

    public function toRegex(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern === '/...') {
            return '#^/.*$#u';
        }

        $pattern = '/' . ltrim($pattern, '/');
        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace(
            [
                preg_quote('{numeric-id}', '#'),
                preg_quote('{slug}', '#'),
                preg_quote('...', '#'),
            ],
            [
                '\\d+',
                '[A-Za-z0-9][A-Za-z0-9_-]*',
                '.*',
            ],
            $escaped,
        );

        return '#^' . $escaped . '$#u';
    }

    public function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return rtrim($path, '/') ?: '/';
    }

    public function looksLikeDetailPath(string $path, OfficialSiteProfile $profile): bool
    {
        if ($profile->detailPathPatterns === []) {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            $last = $segments !== [] ? $segments[array_key_last($segments)] : '';

            return $last !== '' && (
                preg_match('/^\\d{2,}$/', $last) === 1
                || preg_match('/^[a-z0-9][a-z0-9_-]{4,}$/i', $last) === 1
            );
        }

        return $this->matchesAny($path, $profile->detailPathPatterns);
    }
}
