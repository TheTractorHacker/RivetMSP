<?php
// POST /api/v1/agent_build_host/{poll|status|upload|complete}   (Authorization: Bearer rbh_<selector>_<secret>)   JSON in, JSON out.
// The SERVER half of "Build on my build host" (rivet-core docs/rmm/BUILD_HOST_PROTOCOL.md). Authenticated ONLY by a build host token (includes/rmm_agent_localbuild.php); a device
// credential, a user token or an API key is not accepted here and a build host token is accepted nowhere else. Dispatched from index.php above the Bearer parsing, like the device endpoints.
defined('FROM_API') || die();
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_agent_localbuild.php';

(static function (): void {
    global $mysqli, $session_user_id, $session_ip, $session_user_agent;
    $session_user_id = 0;   // audit rows made here have no session user
    $session_ip = getIP();
    $session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    mysqli_report(MYSQLI_REPORT_OFF);
    $out = static function (int $code, array $body, array $headers = []): void {
        http_response_code($code);
        header('Cache-Control: no-store');
        foreach ($headers as $h) {
            header($h);
        }
        echo json_encode($body);
        exit;
    };
    $sub = $GLOBALS['sub'] ?? null;
    if (!is_string($sub) || !in_array($sub, ['poll', 'status', 'upload', 'complete'], true)) {
        $out(404, ['error' => 'Not found.', 'code' => 'not_found']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $out(405, ['error' => 'POST only.', 'code' => 'method'], ['Allow: POST']);
    }
    $ip = getIP();
    $mult = defined('EA_BUILD_HOST_RATE_MULT') ? max(1, min(100, (int) EA_BUILD_HOST_RATE_MULT)) : 1;   // tests only (a busy mock host); production leaves the limits as written
    // before any database work: a flood from one address is turned away
    if (!api_rate_limit('bhost_ip:' . $ip, 300 * $mult, 60)) {
        $out(429, ['error' => 'Too many requests.', 'code' => 'rate_limited'], ['Retry-After: 60']);
    }
    if (!rivetRmmEnabled($mysqli)) {
        $out(503, ['error' => 'The RMM module is switched off.', 'code' => 'module_disabled'], ['Retry-After: 3600']);
    }
    $lb = new \RivetMsp\AgentFeed\LocalBuild($mysqli, defined('EA_BUILD_DIR') ? (string) EA_BUILD_DIR : dirname(__DIR__, 2) . '/backups/endpoint-build', rivetRmmModule($mysqli)->binaryStore()->maxBytes(), static function (string $a, string $t): void {
        logAction('Endpoint Agent', $a, $t);
    });
    if (!$lb->ready()) {
        $out(503, ['error' => 'Run the database update first.', 'code' => 'not_ready']);
    }
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0) {
                $auth = (string) $v;
            }
        }
    }
    $host = $lb->authenticate($auth);
    if ($host === null) {
        // failed attempts are limited harder than good ones, and audited (once per address and minute, so a flood cannot fill the log)
        if (!api_rate_limit('bhost_fail:' . $ip, 20 * $mult, 60)) {
            $out(429, ['error' => 'Too many requests.', 'code' => 'rate_limited'], ['Retry-After: 60']);
        }
        if (api_rate_limit('bhost_fail_log:' . $ip, 1, 60)) {
            logAction('Endpoint Agent', 'Agent Build Host Refused', 'a build host call to ' . $sub . ' from ' . $ip . ' was refused (unknown, disabled or revoked token)');
        }
        $out(401, ['error' => 'Invalid token.', 'code' => 'invalid_token'], ['WWW-Authenticate: Bearer']);
    }
    if (!api_rate_limit('bhost:' . (int) $host['host_id'], ($sub === 'upload' ? 600 : 120) * $mult, 60)) {
        $out(429, ['error' => 'Too many requests.', 'code' => 'rate_limited'], ['Retry-After: 30']);
    }
    // the body: bounded before it is read, JSON object only
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > \RivetMsp\AgentFeed\LocalBuild::BODY_MAX) {
        $out(413, ['error' => 'The request is too large.', 'code' => 'too_large']);
    }
    $raw = (string) stream_get_contents(fopen('php://input', 'rb'), \RivetMsp\AgentFeed\LocalBuild::BODY_MAX + 1);
    if (strlen($raw) > \RivetMsp\AgentFeed\LocalBuild::BODY_MAX) {
        $out(413, ['error' => 'The request is too large.', 'code' => 'too_large']);
    }
    $in = json_decode($raw, true, 8);
    if (!is_array($in) || array_is_list($in) && $in !== []) {
        $out(400, ['error' => 'The body must be a JSON object.', 'code' => 'bad_json']);
    }
    try {
        [$code, $body] = match ($sub) {
            'poll' => $lb->poll($host, $in, $ip),
            'status' => $lb->status($host, $in, $ip),
            'upload' => $lb->upload($host, $in, $ip),
            default => (static function () use ($lb, $host, $in, $ip): array {
                $rmm = rivetRmmModule();

                return $lb->complete($host, $in, static fn (int $uid, string $name): callable => \RivetMsp\AgentFeed\rivetLocalBuildPublisher($rmm, rivetRmmPrincipal($uid, $name), $uid), $ip);
            })(),
        };
    } catch (\Throwable $e) {
        error_log('build host: ' . get_class($e) . ': ' . $e->getMessage());
        $out(500, ['error' => 'Internal error.', 'code' => 'internal']);
    }
    $out($code, $body);
})();
exit;
