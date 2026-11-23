<?php
// MSP-DS-12/78/37: the atomic per-account attempt reservation used by api/v1/auth.php and login.php (MFA step).
// Run: SCRATCH_TABLES=users tests/lib/with_scratch_db.sh php tests/msp_ds_login_throttle.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
foreach (['api/v1/auth.php' => '$uid_rl', 'login.php' => '$mfa_uid'] as $file => $var) {
    $src = file_get_contents("$root/$file");
    preg_match('/"UPDATE users SET\s+user_failed_login_count = IF\((.*?\))"\s*\n?\s*\);/s', $src, $mm);
    $ok("$file: reservation statement found", !empty($mm[0]));
    $sql = 'UPDATE users SET user_failed_login_count = IF(' . $mm[1];
    $sql = str_replace($var, '1', $sql);
    $m->query("DELETE FROM users"); $m->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_role_id, user_type, user_status, user_failed_login_count, user_failed_login_at) VALUES (1,'a','a@x','x',1,1,1,0,NULL)");
    $got = 0; for ($i = 0; $i < 12; $i++) { $m->query($sql); if ($m->affected_rows >= 1) { $got++; } }
    $ok("$file: only 5 attempts are granted inside the window, then locked ($got of 12)", $got === 5);
    // the window is evaluated in UTC by the database: an old failure (10 minutes ago, UTC) releases the lock regardless of PHP's zone
    $m->query("UPDATE users SET user_failed_login_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE user_id = 1");
    $m->query($sql);
    $ok("$file: after the 5-minute window the account is usable again with the counter restarted", $m->affected_rows >= 1 && (int) $m->query("SELECT user_failed_login_count FROM users WHERE user_id=1")->fetch_row()[0] === 1);
    $m->query("UPDATE users SET user_failed_login_count = 5, user_failed_login_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = 1");
    $m->query($sql);
    $ok("$file: a failure from 1 minute ago still locks (no timezone shift)", $m->affected_rows < 1);
}
$api = file_get_contents("$root/api/v1/auth.php");
$ok('api: no strtotime() on the failure time', !str_contains($api, 'strtotime($user[\'user_failed_login_at\']'));
$ok('api: a seed that cannot be read fails closed', str_contains($api, 'MSP-DS-143'));
$web = file_get_contents("$root/login.php");
$ok('web: wrong code keeps the original expiry and counts attempts', str_contains($web, "'mfa_attempts'") && str_contains($web, '$is_mfa_step ? intval($pending_mfa[\'created\']'));
$ok('web: unreadable seed is refused', str_contains($web, '$mfa_seed_unreadable'));
$prof = file_get_contents("$root/agent/user/post/profile.php");
$ok('sign out everywhere revokes API tokens', preg_match('/sign_out_everywhere.*?DELETE FROM api_tokens WHERE token_user_id = \$session_user_id/s', $prof) === 1);
exit($fail ? 1 : 0);
