<?php
// Re-review RR12: edit_user with 2fa=disable (or Force MFA switched off) on ANOTHER user needs the acting administrator's password. Runs the real admin/post/users.php handler.
// Run: SCRATCH_TABLES="users user_roles user_settings user_passkeys api_tokens logs user_client_permissions clients user_role_permissions remember_tokens user_sessions recovery_codes" bash tests/lib/with_scratch_db.sh php tests/msp_ds_review_RR12_edit_user_2fa_reauth.php
require __DIR__ . '/lib/handler_run.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$PW = 'Admin-Pass-12345'; $hash = password_hash($PW, PASSWORD_DEFAULT);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Adm',1),(92,'Tech',0),(93,'Adm2',1)");
$ins = fn (int $id, string $n, int $role, string $tok = '') => $m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status,user_token) VALUES ($id,'$n','$n@x.test','$hash',$role,1,1,'$tok')");
$ins(1, 'admin', 91, 'SEED'); $ins(2, 'tech', 92, 'SEEDT'); $ins(3, 'admin2', 93, 'SEED2');
$m->query("INSERT INTO user_settings (user_id, user_config_force_mfa) VALUES (1,0),(2,1),(3,1)");
$reset = function () use ($m) { $m->query("UPDATE users SET user_failed_login_count=0, user_failed_login_at=NULL"); };
$run = function (array $post) use ($reset) { $reset(); return runPostHandler('users.php', 1, $post, [], 'admin')[1]; };
$edit = fn (array $o) => $o + ['edit_user' => 1, 'user_id' => 3, 'name' => 'admin2', 'email' => 'admin2@x.test', 'role' => 93, 'new_password' => '', 'force_mfa' => 1];

$run($edit(['2fa' => 'disable']));
$ok('another ADMIN: 2fa=disable without a password is refused (TOTP kept)', $one("SELECT user_token FROM users WHERE user_id=3") === 'SEED2');
$run($edit(['2fa' => 'disable', 'admin_current_password' => 'wrong']));
$ok('... and with a wrong password', $one("SELECT user_token FROM users WHERE user_id=3") === 'SEED2');
$run($edit(['user_id' => 2, 'name' => 'tech', 'email' => 'tech@x.test', 'role' => 92, '2fa' => 'disable']));
$ok('a technician target: 2fa=disable without a password is refused too', $one("SELECT user_token FROM users WHERE user_id=2") === 'SEEDT');
$run($edit(['force_mfa' => 0]));
$ok('Force MFA 1 -> 0 on another user without a password is refused', (int) $one("SELECT user_config_force_mfa FROM user_settings WHERE user_id=3") === 1);
$run($edit(['2fa' => 'disable', 'admin_current_password' => $PW]));
$ok('2fa=disable with the acting admin\'s password works', in_array($one("SELECT user_token FROM users WHERE user_id=3"), [null, ''], true));
$run($edit(['user_id' => 2, 'name' => 'tech', 'email' => 'tech@x.test', 'role' => 92, 'force_mfa' => 0, 'admin_current_password' => $PW]));
$ok('Force MFA 1 -> 0 with the password works', (int) $one("SELECT user_config_force_mfa FROM user_settings WHERE user_id=2") === 0);
$run($edit(['user_id' => 2, 'name' => 'tech renamed', 'email' => 'tech@x.test', 'role' => 92, 'force_mfa' => 0]));
$ok('an ordinary edit (name only, Force MFA unchanged) still needs no password', $one("SELECT user_name FROM users WHERE user_id=2") === 'tech renamed');
$run($edit(['user_id' => 2, 'name' => 'tech', 'email' => 'tech@x.test', 'role' => 92, 'force_mfa' => 1]));
$ok('turning Force MFA ON (strengthening) needs no password', (int) $one("SELECT user_config_force_mfa FROM user_settings WHERE user_id=2") === 1);
exit($fail ? 1 : 0);
