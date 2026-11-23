<?php
// Tiny helper for behavioural tests: run one agent/post handler through run_post_handler.php, return [captured output, "alert_type:message"].
function runPostHandler(string $handler, int $userId, array $post = [], array $get = [], string $base = 'agent'): array
{
    $cmd = [PHP_BINARY, __DIR__ . '/run_post_handler.php', json_encode(['handler' => $handler, 'base' => $base, 'user_id' => $userId, 'post' => $post, 'get' => $get])];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); proc_close($p);
    $alert = preg_match('/::END::(.*)$/m', $out, $m) ? $m[1] : '';

    return [$out . $err, $alert];
}
