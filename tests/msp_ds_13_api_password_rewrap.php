<?php
// MSP-DS-13: PUT /api/v1/me password change re-wraps the vault key. Run: SCRATCH_TABLES="users" tests/lib/with_scratch_db.sh php tests/msp_ds_13_api_password_rewrap.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../functions.php');
foreach (['randomString', 'decryptUserSpecificKey', 'rewrapUserKeyForPasswordChange'] as $fn) { preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $src, $m); eval($m[0]); }
$me = file_get_contents(__DIR__ . '/../api/v1/me.php');
$ok('me.php calls the re-wrap helper on a password change', str_contains($me, 'rewrapUserKeyForPasswordChange(') && str_contains($me, 'user_specific_encryption_ciphertext ='));
$master = 'FICTIONALMASTER1'; $old = 'OldPass!2026x'; $new = 'NewPass!2026y';
$salt = randomString(); $iv = randomString();
$wrap = 'V2:' . $salt . $iv . openssl_encrypt($master, 'aes-128-cbc', hash_pbkdf2('sha256', $old, $salt, 100000, 16, true), 0, $iv);
$mysqli->query("INSERT INTO users SET user_id = 2, user_name = 't', user_email = 't@example.test', user_password = 'x', user_specific_encryption_ciphertext = '" . $mysqli->real_escape_string($wrap) . "'");
$ok('before: old password opens the wrap', decryptUserSpecificKey($wrap, $old) === $master);
$ok('wrong current password gives no re-wrap', rewrapUserKeyForPasswordChange($mysqli, 2, 'Wrong!', $new) === null);
$w2 = rewrapUserKeyForPasswordChange($mysqli, 2, $old, $new);
$ok('re-wrap produced', is_string($w2) && str_starts_with($w2, 'V2:'));
$ok('new password opens the master key', decryptUserSpecificKey($w2, $new) === $master);
$ok('old password no longer opens it', decryptUserSpecificKey($w2, $old) !== $master);
$mysqli->query("UPDATE users SET user_specific_encryption_ciphertext = '' WHERE user_id = 2");
$ok('user without a vault wrap: nothing to re-wrap', rewrapUserKeyForPasswordChange($mysqli, 2, $old, $new) === null);
exit($fail ? 1 : 0);
