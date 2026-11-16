<?php
// MSP-DS-148: portal password-reset requests are throttled per account (3/hour, from the logs table) and per IP (Redis when configured).
// Run: SCRATCH_TABLES="logs" bash tests/lib/with_scratch_db.sh php tests/msp_ds_148_portal_reset_throttle.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$s = file_get_contents(__DIR__ . '/../client/login_reset.php');
preg_match('/mysqli_query\(\$mysqli, "(SELECT COUNT\(\*\) FROM logs WHERE log_type = .Contact. AND log_description = [^"]+)"\)\);/', $s, $m);
$ok(!empty($m[1]), 'per-account count query present');
$email = 'a@b.test';
$q = str_replace('$email', $email, $m[1]);
$count = fn() => (int) $mysqli->query($q)->fetch_row()[0];
$ins = fn($ago) => $mysqli->query("INSERT INTO logs SET log_type='Contact', log_action='Modify', log_description='Sent a portal password reset e-mail for $email.', log_created_at = NOW() - INTERVAL $ago MINUTE");
$ins(5); $ins(10); $ok($count() === 2, 'two recent requests are counted');
$ins(90); $ok($count() === 2, 'a request older than an hour is not');
$ins(1); $ok($count() >= 3, 'the third recent request reaches the limit');
$ok(strpos($s, "rivetRateLimit('portal-reset:ip:'") !== false && strpos($s, '$reset_throttled') !== false, 'per-IP bucket and a single throttled flag gate the send');
$ok(substr_count($s, '!$reset_throttled') === 2 && strpos($s, "UPDATE users SET user_password_reset_token") > strpos($s, '$reset_throttled'), 'token is not rotated when throttled');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
