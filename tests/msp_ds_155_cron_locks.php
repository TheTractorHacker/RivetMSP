<?php
// MSP-DS-155: cron locks - future mtime / stale / foreign-owner locks are not live. Run: php tests/msp_ds_155_cron_locks.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
require __DIR__ . '/../includes/cron_lock.php';
$d = sys_get_temp_dir() . '/ds155_' . getmypid(); mkdir($d);
$f = "$d/x.lock"; file_put_contents($f, 'Locked');
$ok(rivetCronLockIsLive($f, 600), 'fresh own lock is live');
touch($f, time() - 700); $ok(!rivetCronLockIsLive($f, 600), 'lock older than ttl is stale');
touch($f, time() + 86400 * 365); $ok(!rivetCronLockIsLive($f, 600), 'lock dated in the future is stale (cannot block forever)');
$ok(!rivetCronLockIsLive("$d/none.lock", 600), 'missing lock is not live');
$root = ($uid = function_exists('posix_geteuid') ? posix_geteuid() : 1000) !== 0;
if ($root && @file_exists('/etc/hostname')) { $ok(!rivetCronLockIsLive('/etc/hostname', PHP_INT_MAX), 'a file owned by another user (root) is never live'); }
$dir = rivetCronLockDir(); $ok(is_dir($dir), "lock dir resolves ($dir)");
@unlink($f); @rmdir($d);
foreach (['mail_queue', 'ticket_email_parser', 'outlook_schedule_sync'] as $c) {
    $s = file_get_contents(__DIR__ . "/../cron/$c.php");
    $ok(str_contains($s, 'rivetCronLockDir()') && str_contains($s, 'rivetCronLockIsLive(') && !str_contains($s, 'sys_get_temp_dir()'), "$c uses the guarded private lock");
}
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
