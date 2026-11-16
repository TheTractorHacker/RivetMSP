<?php
// Review R22/R23: promoting a user to administrator (edit_user, restore_user, role flag), changing another administrator's email/password,
// and removing 2FA / passkeys (now POST + CSRF + password) all need the acting administrator's password. Runs the real admin/post handlers.
// Run: SCRATCH_TABLES="users user_roles user_settings user_passkeys api_tokens logs user_client_permissions clients user_role_permissions remember_tokens user_sessions recovery_codes" bash tests/lib/with_scratch_db.sh php tests/msp_ds_review_R22_R23_admin_reauth.php
require __DIR__ . '/lib/handler_run.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$PW = 'Admin-Pass-12345'; $hash = password_hash($PW, PASSWORD_DEFAULT);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Adm',1),(92,'Tech',0),(93,'Adm2',1)");
$ins = fn (int $id, string $n, int $role, string $tok = '') => $m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status,user_token) VALUES ($id,'$n','$n@x.test','$hash',$role,1,1,'$tok')");
$ins(1, 'admin', 91, 'SEED'); $ins(2, 'tech', 92); $ins(3, 'admin2', 93, 'SEED2');
$m->query("INSERT INTO user_settings (user_id, user_config_force_mfa) VALUES (1,0),(2,0),(3,0)");
$m->query("INSERT INTO user_passkeys (passkey_id, passkey_user_id, passkey_name) VALUES (7,3,'key')");
$run = function (string $h, array $post, array $get = []) { return runPostHandler($h, 1, $post, $get, 'admin')[1]; };
$edit = fn (array $o) => $o + ['edit_user' => 1, 'user_id' => 2, 'name' => 'tech', 'email' => 'tech@x.test', 'role' => 92, 'new_password' => '', 'force_mfa' => 0];

// R22: promote an existing user to admin
$run('users.php', $edit(['role' => 91]));
$ok('R22: edit_user promoting a technician to admin WITHOUT a password is refused', (int) $one("SELECT user_role_id FROM users WHERE user_id=2") === 92);
$run('users.php', $edit(['role' => 91, 'admin_current_password' => 'wrong']));
$ok('R22: ... and with a WRONG password', (int) $one("SELECT user_role_id FROM users WHERE user_id=2") === 92);
$run('users.php', $edit(['role' => 91, 'admin_current_password' => $PW]));
$ok('R22: ... succeeds with the acting admin\'s password', (int) $one("SELECT user_role_id FROM users WHERE user_id=2") === 91);
$m->query("UPDATE users SET user_role_id=92 WHERE user_id=2"); $m->query("UPDATE users SET user_failed_login_count=0, user_failed_login_at=NULL");
$run('users.php', $edit(['name' => 'tech renamed']));
$ok('R22: an ordinary edit (name only, no promotion) still needs no password', $one("SELECT user_name FROM users WHERE user_id=2") === 'tech renamed');
// takeover of another admin's email / password
$run('users.php', $edit(['user_id' => 3, 'name' => 'admin2', 'email' => 'attacker@x.test', 'role' => 93]));
$ok('R22: changing ANOTHER administrator\'s email without a password is refused', $one("SELECT user_email FROM users WHERE user_id=3") === 'admin2@x.test');
$run('users.php', $edit(['user_id' => 3, 'name' => 'admin2', 'email' => 'admin2@x.test', 'role' => 93, 'new_password' => 'Brand-New-Pass-98765']));
$ok('R22: ... and their password', $one("SELECT user_password FROM users WHERE user_id=3") === $hash);
// restore_user
$m->query("UPDATE users SET user_archived_at = NOW(), user_status = 0 WHERE user_id = 2");
$run('users.php', ['restore_user' => 1, 'user_id' => 2, 'role' => 91, 'new_password' => '']);
$ok('R22: restore_user into an admin role without a password is refused', $one("SELECT user_archived_at FROM users WHERE user_id=2") !== null);
$m->query("UPDATE users SET user_failed_login_count=0, user_failed_login_at=NULL");
$run('users.php', ['restore_user' => 1, 'user_id' => 2, 'role' => 92, 'new_password' => '']);
$ok('R22: restore_user into a non-admin role needs no password', $one("SELECT user_archived_at FROM users WHERE user_id=2") === null);
// role flag
$run('roles.php', ['edit_role' => 1, 'role_id' => 92, 'role_name' => 'Tech', 'role_description' => '', 'role_is_admin' => 1]);
$ok('R22: flipping role_is_admin=1 on a role without a password is refused', (int) $one("SELECT role_is_admin FROM user_roles WHERE role_id=92") === 0);
$run('roles.php', ['add_role' => 1, 'role_name' => 'Evil', 'role_description' => '', 'role_is_admin' => 1]);
$ok('R22: add_role as administrator without a password is refused', (int) $one("SELECT COUNT(*) FROM user_roles WHERE role_name='Evil'") === 0);
$m->query("UPDATE users SET user_failed_login_count=0, user_failed_login_at=NULL");
$run('roles.php', ['edit_role' => 1, 'role_id' => 92, 'role_name' => 'Tech', 'role_description' => '', 'role_is_admin' => 1, 'admin_current_password' => $PW]);
$ok('R22: ... works with the password', (int) $one("SELECT role_is_admin FROM user_roles WHERE role_id=92") === 1);
$m->query("UPDATE user_roles SET role_is_admin=0 WHERE role_id=92"); $m->query("UPDATE users SET user_failed_login_count=0, user_failed_login_at=NULL");

// R23: GET links are dead, POST needs the password
$run('users.php', [], ['disable_2fa' => 3]);
$ok('R23: the old GET disable_2fa link no longer does anything', $one("SELECT user_token FROM users WHERE user_id=3") === 'SEED2');
$run('users.php', [], ['delete_passkey' => 7, 'user_id' => 3]);
$ok('R23: the old GET delete_passkey link no longer does anything', (int) $one("SELECT COUNT(*) FROM user_passkeys WHERE passkey_id=7") === 1);
$run('users.php', ['disable_2fa' => 1, 'user_id' => 1]);
$ok('R23: POST disable_2fa for yourself without the password is refused', $one("SELECT user_token FROM users WHERE user_id=1") === 'SEED');
$run('users.php', ['delete_passkey' => 7, 'user_id' => 3]);
$ok('R23: POST delete_passkey without the password is refused', (int) $one("SELECT COUNT(*) FROM user_passkeys WHERE passkey_id=7") === 1);
$run('users.php', ['disable_2fa' => 1, 'user_id' => 1, 'admin_current_password' => $PW]);
$ok('R23: POST disable_2fa with the password works', in_array($one("SELECT user_token FROM users WHERE user_id=1"), [null, ''], true));
$run('users.php', ['delete_passkey' => 7, 'user_id' => 3, 'admin_current_password' => $PW]);
$ok('R23: POST delete_passkey with the password works', (int) $one("SELECT COUNT(*) FROM user_passkeys WHERE passkey_id=7") === 0);
$m->query("UPDATE users SET user_failed_login_count=0, user_failed_login_at=NULL");
$run('users.php', $edit(['user_id' => 1, 'name' => 'admin', 'email' => 'admin@x.test', 'role' => 91, 'force_mfa' => 1]));
$ok('R23: changing your OWN Force MFA setting without the password is refused', (int) $one("SELECT user_config_force_mfa FROM user_settings WHERE user_id=1") === 0);
$ui = file_get_contents(__DIR__ . '/../admin/modals/user/user_edit.php');
$ok('R23: the modal no longer links to the GET handlers', !str_contains($ui, 'post.php?disable_2fa') && !str_contains($ui, 'post.php?delete_passkey') && str_contains($ui, 'name="disable_2fa"') && str_contains($ui, 'name="delete_passkey"'));
exit($fail ? 1 : 0);
