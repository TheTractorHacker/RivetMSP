<?php
defined('FROM_API') || die();

/**
 * Best-effort fixed-window rate limiter backed by Redis (Predis, via
 * getRedisClient() in includes/redis_functions.php).
 *
 * Increments a per-bucket counter and, on the first hit of a window, sets a
 * TTL equal to the window length. Returns true while the caller is at or under
 * $limit for the current window, false once it is exceeded.
 *
 * FAILS OPEN by default: if Redis is unavailable (getRedisClient() returns null)
 * or any Redis call throws, this returns true so the API keeps serving. Rate
 * limiting here is an abuse guard, never a hard dependency. Credential-guessing
 * surfaces (the login endpoint) pass $fail_closed = true so that an attacker
 * cannot lift the throttle by knocking Redis over: with Redis down the call
 * returns false (the caller answers 429) instead of true.
 *
 * @param string $bucket unique key suffix (per token, per IP, ...)
 * @param int    $limit  max requests allowed within the window
 * @param int    $window window length in seconds
 * @param bool   $fail_closed deny (return false) when Redis is unavailable
 * @return bool true = allowed, false = over limit
 */
function api_rate_limit(string $bucket, int $limit, int $window, bool $fail_closed = false): bool {
    $redis = getRedisClient();
    if (!$redis) {
        return !$fail_closed; // fail open (default) / closed — Redis down
    }
    $key = 'api_rl:' . $bucket;
    try {
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, $window);
        }
        return $count <= $limit;
    } catch (\Throwable $e) {
        return !$fail_closed; // fail open (default) / closed — Redis error
    }
}
