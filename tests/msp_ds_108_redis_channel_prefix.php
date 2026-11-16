<?php
// MSP-DS-108: Redis pub/sub channels carry a per-install prefix; publishers and the three SSE subscribers build channel names through the same helpers. Run:
// php tests/msp_ds_108_redis_channel_prefix.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
require $root . '/includes/redis_functions.php';
$a = redisInstallPrefixFor('localhost', 'itflow_prod');
$b = redisInstallPrefixFor('localhost', 'itflow_beta');
$ok($a !== $b, 'two databases on one host get different prefixes');
$ok($a !== redisInstallPrefixFor('db2.example', 'itflow_prod'), 'same database name on another host differs');
$ok($a === redisInstallPrefixFor('localhost', 'itflow_prod'), 'stable for one install');
$ok(preg_match('/^rivetmsp:[0-9a-f]{12}:$/', $a) === 1, 'format ' . $a);
$mysqli = null;   // no connection: helper still returns a usable (uncached) channel
$ok(str_ends_with(redisTicketChannel(42), 'ticket.42') && str_ends_with(redisUserNotificationChannel(3), 'user.3.notifications'), 'channel suffixes unchanged');
$ok(str_starts_with(redisTicketChannel(42), 'rivetmsp:'), 'channels are prefixed');
$r = file_get_contents("$root/includes/redis_functions.php");
$ok(!preg_match('/publish\("(ticket|user)\./', $r) && str_contains($r, 'publish(redisTicketChannel($ticket_id)') && str_contains($r, 'publish(redisUserNotificationChannel($user_id)'), 'publishers use the helpers');
foreach (['includes/sse_ticket_stream.php', 'includes/sse_ticket_chat_stream.php', 'includes/sse_notifications_stream.php'] as $f) {
    $s = file_get_contents("$root/$f");
    $ok(preg_match('/\$channel = redis(Ticket|UserNotification)Channel\(/', $s) === 1 && !preg_match('/\$channel = "(ticket|user)\./', $s), "$f subscribes through the helper");
}
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
