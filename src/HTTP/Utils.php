<?php

declare(strict_types=1);

namespace SimplePie\HTTP;

/**
 * HTTP util functions
 *
 * @internal
 */
final class Utils
{
    /**
     * Negotiate the cache expiration time based on the HTTP response headers.
     * Return the cache duration time in number of seconds since the Unix Epoch, accounting for:
     * - `Cache-Control: max-age` minus `Age`, extendable by `$simplepie_cache_duration`
     * - `Cache-Control: must-revalidate` will prevent `$simplepie_cache_duration` from extending past the `max-age`
     * - `Cache-Control: no-cache` will return the current time
     * - `Cache-Control: no-store` will return `0`
     * - `Expires` but only if `Cache-Control: max-age` is absent
     *
     * @param int $simplepie_cache_duration Cache duration in seconds desired from SimplePie
     * @param array<string,mixed> $http_headers HTTP headers of the response
     * @return int
     * FreshRSS
     */
    public static function negociate_cache_expiration_time(int $simplepie_cache_duration, array $http_headers): int
    {
        $cache_control = $http_headers['cache-control'] ?? '';
        if ($cache_control !== '') {
            if (preg_match('/\bno-store\b/', $cache_control)) {
                return 0;
            }
            if (preg_match('/\bno-cache\b/', $cache_control)) {
                return time() + 1; // +1 to account for inequalities
            }
            if (preg_match('/\bmust-revalidate\b/', $cache_control)) {
                $simplepie_cache_duration = 0;
            }
            if (preg_match('/\bmax-age=(\d+)\b/', $cache_control, $matches)) {
                $max_age = (int) $matches[1];
                $age = $http_headers['age'] ?? '';
                if (is_numeric($age)) {
                    $max_age -= (int) $age;
                }
                return time() + $max_age + $simplepie_cache_duration + 1;
            }
        }
        $expires = $http_headers['expires'] ?? '';
        if ($expires !== '') {
            $expire_date = \SimplePie\Misc::parse_date($expires);
            if ($expire_date !== false) {
                return $expire_date + $simplepie_cache_duration + 1;
            }
        }
        return $simplepie_cache_duration + time();
    }
}
