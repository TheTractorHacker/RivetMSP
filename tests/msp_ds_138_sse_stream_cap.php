<?php
// MSP-DS-138: a person may hold only a few live-ticket SSE streams at once (slot counter in Redis); over the cap the answer is 429.
// Starts a private throwaway redis-server on a high port (no data, no persistence). Run: php tests/msp_ds_138_sse_stream_cap.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
require __DIR__ . '/../vendor/autoload.php';
$src = file_get_contents(__DIR__ . '/../includes/sse_ticket_stream.php');
$ok(strpos($src, 'MSP-DS-138') !== false, 'include has the cap');
preg_match('#(\$sse_owner = .*?)\n\$channel#s', $src, $m);
$snippet = str_replace(["exit;", "http_response_code(429);", "header('Retry-After: 60');", 'register_shutdown_function('], ['return "BLOCKED";', '', '', '$GLOBALS["__sd"][] = ('], $m[1]);
$snippet = preg_replace('/\$GLOBALS\["__sd"\]\[\] = \(static function/', '$GLOBALS["__sd"][] = (static function', $snippet);
$port = 6391 + (getmypid() % 500);
$proc = proc_open(['redis-server', '--port', (string) $port, '--save', '', '--appendonly', 'no', '--bind', '127.0.0.1'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(600000);
try {
    $redis = new Predis\Client(['host' => '127.0.0.1', 'port' => $port]);
    $redis->ping();
    $try = function (string $owner, int $cap) use ($redis, $snippet) {
        $sse_stream_owner = $owner; $sse_stream_cap = $cap;
        $GLOBALS['__sd'] = [];
        $r = (function () use ($redis, $snippet, $sse_stream_owner, $sse_stream_cap) { return eval($snippet . "\nreturn 'OPEN';"); })();
        return $r;
    };
    // the register_shutdown_function(...) replacement above leaves a call statement; evaluate carefully
    $res = [];
    for ($i = 0; $i < 4; $i++) { $res[] = $try('agent:1', 3); }
    $ok($res === ['OPEN', 'OPEN', 'OPEN', 'BLOCKED'], 'fourth parallel stream of the same user is refused');
    $ok($try('agent:2', 3) === 'OPEN', 'another user is not affected');
    $ok((int) $redis->get('sse_streams:agent:1') === 3, 'refused attempt does not keep a slot');
    $ok($redis->ttl('sse_streams:agent:1') > 0, 'slot counter expires on its own');
} catch (\Throwable $e) {
    $ok(false, 'redis test could not run: ' . $e->getMessage());
}
proc_terminate($proc);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
