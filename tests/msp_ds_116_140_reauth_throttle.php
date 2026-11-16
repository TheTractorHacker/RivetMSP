<?php
// MSP-DS-116/117/122/140: password re-check is limited (5 / 5 min, atomic), master-key reveal and IR reset use it, vw3 wrap handled,
// IR reset skips client-portal logins.
// Run: SCRATCH_TABLES="users" bash tests/lib/with_scratch_db.sh php tests/msp_ds_116_140_reauth_throttle.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$audits = [];
function secAudit(...$a) { global $audits; $audits[] = $a[0]; }
$src = file_get_contents(__DIR__ . '/../includes/security_policy.php');
preg_match('/    function secReauthPassword\(.*?\n    }\n/s', $src, $m);
eval($m[0]);
$mysqli->query("SET SESSION sql_mode=''");
$h = password_hash('correct horse', PASSWORD_DEFAULT);
$mysqli->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_type,user_status) VALUES (1,'a','a@x',  '" . $mysqli->real_escape_string($h) . "',1,1)");
$ok($mysqli->errno === 0, 'user row inserted ' . $mysqli->error);
$ok(secReauthPassword($mysqli, 1, 'correct horse') === true, 'correct password accepted');
for ($i = 0; $i < 5; $i++) { secReauthPassword($mysqli, 1, "wrong$i"); }
$ok(secReauthPassword($mysqli, 1, 'correct horse') === false, 'after 5 wrong guesses even the right password is refused (locked)');
$ok(in_array('auth.reauth_locked', $audits, true) && in_array('auth.reauth_failed', $audits, true), 'lock and failures are audited');
$mysqli->query("UPDATE users SET user_failed_login_at = UTC_TIMESTAMP() - INTERVAL 6 MINUTE WHERE user_id = 1");
$ok(secReauthPassword($mysqli, 1, 'correct horse') === true, 'window expires after 5 minutes');
$ok((int) $mysqli->query("SELECT user_failed_login_count FROM users WHERE user_id=1")->fetch_row()[0] === 0, 'success resets the counter');
$ok(secReauthPassword($mysqli, 1, '') === false, 'empty password refused');
// source checks
$b = file_get_contents(__DIR__ . '/../admin/post/backup.php');
$u = file_get_contents(__DIR__ . '/../admin/post/users.php');
$p = file_get_contents(__DIR__ . '/../agent/user/post/profile.php');
$pos = strpos($b, "isset(\$_POST['backup_master_key'])"); $blk = substr($b, $pos, 2200);
$ok(strpos($blk, 'secReauthPassword(') !== false && strpos($blk, 'password_verify') === false, '116: master-key reveal uses secReauthPassword');
$ok(strpos($blk, "'vw3:'") !== false && strpos($blk, 'vaultLoginMasterKey(') !== false, '117: vw3 wraps are resolved with the vault v3 helper');
$ok(strpos($blk, "=== ''") !== false && strpos($blk, 'could not retrieve') !== false, '117: an empty key is a failure, not a logged success');
$pos = strpos($u, "isset(\$_POST['ir_reset_user_password'])"); $blk = substr($u, $pos, 2600);
$ok(strpos($blk, 'secReauthPassword(') !== false && strpos($blk, 'password_verify') === false, '116: IR reset uses secReauthPassword');
$ok(strpos($blk, 'user_type = 1') !== false, '122: IR reset selects agents only');
$ok(strpos($blk, 'secSessionsOnPasswordChange(') !== false, '140: IR reset removes passkeys (secSessionsOnPasswordChange revokes them)');
$ok(substr_count($p, 'password_verify(') === 0, '140: profile.php has no unthrottled password_verify');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
