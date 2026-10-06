<?php
defined('FROM_API') || die();

require_once __DIR__ . '/../../../includes/redis_guards.php';

/**
 * REST API rate limiting, one limiter for everything: RivetCore's fixed-window RateLimiter through rivetRateLimit()
 * (includes/redis_guards.php), keys "<prefix>rl:api:<bucket>".
 *
 * FAILS OPEN by default: if Redis is unavailable or any Redis call throws the request is allowed, so rate limiting is an abuse
 * guard and never a hard dependency. Credential-guessing surfaces (the login endpoint) pass $fail_closed = true so that an
 * attacker cannot lift the throttle by knocking Redis over: with Redis down the call is denied (the caller answers 429).
 *
 * If the Redis module is switched off (Core module flag) the same counters run straight on the app's Redis client, as before.
 *
 * @return array{allowed:bool, remaining:int, retry_after:int}
 */
function api_rate_limit_hit(string $bucket, int $limit, int $window, bool $fail_closed = false): array {
    $window = max(1, $window);
    if ($fail_closed && (!function_exists('getRedisClient') || getRedisClient() === null)) {
        return ['allowed' => false, 'remaining' => 0, 'retry_after' => $window]; // fail closed: Redis down
    }
    if (rivetRedisGuardsOn()) {
        return rivetRateLimit('api:' . $bucket, $limit, $window);
    }
    $open = ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
    $redis = function_exists('getRedisClient') ? getRedisClient() : null;
    if (!$redis) {
        return $open;
    }
    $key = 'api_rl:' . $bucket;
    try {
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, $window);
        }
        return ['allowed' => $count <= $limit, 'remaining' => max(0, $limit - $count), 'retry_after' => $count <= $limit ? 0 : $window];
    } catch (\Throwable $e) {
        return $open;
    }
}

/**
 * @param string $bucket unique key suffix (per token, per IP, ...)
 * @param int    $limit  max requests allowed within the window
 * @param int    $window window length in seconds
 * @param bool   $fail_closed deny (return false) when Redis is unavailable
 * @return bool true = allowed, false = over limit
 */
function api_rate_limit(string $bucket, int $limit, int $window, bool $fail_closed = false): bool {
    return api_rate_limit_hit($bucket, $limit, $window, $fail_closed)['allowed'];
}

/** Requests per minute per API key or token (Administration > API Keys), 0 = no limit. Per address the limit is three times this. */
function api_configured_limit(): int {
    $v = $GLOBALS['config_api_rate_limit_per_minute'] ?? 120;
    return max(0, min(100000, (int) $v));
}

/**
 * The limit for authenticated calls: per key (or token or user) and per address. Answers HTTP 429 with a Retry-After header and a
 * JSON body when exceeded, and records one api.rate_limited audit event per key and window. Does nothing when the limit is 0.
 *
 * @param string $bucket  per-caller bucket ("tok:...", "key:...", "usr:N")
 * @param string $label   non-secret caller id for the audit trail ("token:12", "api_key:3", "user:5")
 * @param ?int   $actor   user id, if known
 */
function api_enforce_rate_limit(string $bucket, string $label, ?int $actor, string $ip): void {
    $per_minute = api_configured_limit();
    if ($per_minute <= 0) {
        return;
    }
    $scope = 'key';
    $hit = api_rate_limit_hit($bucket, $per_minute, 60);
    if ($hit['allowed']) {
        $scope = 'address';
        $hit = api_rate_limit_hit('ip:' . $ip, $per_minute * 3, 60);
    }
    if ($hit['allowed']) {
        return;
    }
    $retry = max(1, (int) $hit['retry_after']);
    $audit_key = $scope === 'key' ? $bucket : 'ip:' . $ip;
    if (api_rate_limit_hit('audit:' . $audit_key, 1, 60)['allowed'] && function_exists('rivetAudit')) {
        rivetAudit('api.rate_limited', $actor, 'api', $scope === 'key' ? $label : $ip, 'rate_limit', 'API rate limit exceeded (' . $scope . ')', [
            'scope' => $scope, 'caller' => $label, 'ip' => $ip, 'limit_per_minute' => $scope === 'key' ? $per_minute : $per_minute * 3, 'retry_after' => $retry,
            'path' => substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), 0, 200),
        ]);
    }
    header('Retry-After: ' . $retry);
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded', 'retry_after' => $retry]);
    exit;
}
