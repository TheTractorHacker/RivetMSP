<?php
// MSP-DS-140 (complete): registered passkeys do not survive "sign out everywhere", a password change (self or administrator-set) or the IR mass
// reset; API tokens go too; the passkey enrolment finish demands a CSRF token and a throttled fresh password check and emails the owner.
// Runs the REAL handlers (agent/user/post/profile.php, admin/post/users.php) against a scratch DB.
// Run: SCRATCH_TABLES="users user_roles user_settings logs user_client_permissions api_tokens remember_tokens user_sessions clients user_passkeys" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_140.php
require __DIR__ . '/lib/handler_run.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$n = fn (string $s) => (int) (($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : -1);
$hash = password_hash('Admin-Pass-123456', PASSWORD_DEFAULT);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Admin',1),(92,'Tech',0)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES
  (1,'Admin One','admin@x.test','$hash',91,1,1),(2,'Tech Two','tech@x.test','$hash',92,1,1),(3,'Tech Three','t3@x.test','$hash',92,1,1)");
$m->query("INSERT INTO user_settings (user_id) VALUES (1),(2),(3)");
$seed = function () use ($m) {
    $m->query("DELETE FROM user_passkeys"); $m->query("DELETE FROM api_tokens");
    foreach ([1, 2, 3] as $u) {
        $m->query("INSERT INTO user_passkeys (passkey_user_id, passkey_name, passkey_credential_id, passkey_public_key, passkey_sign_count, passkey_aaguid) VALUES ($u, 'pk', 'cred$u', 'pem', 1, '')");
        $m->query("INSERT INTO api_tokens (token_user_id, token_hash) VALUES ($u, SHA2('t$u',256))");
    }
};
$pk = fn (int $u) => $n("SELECT COUNT(*) FROM user_passkeys WHERE passkey_user_id = $u");
$tk = fn (int $u) => $n("SELECT COUNT(*) FROM api_tokens WHERE token_user_id = $u");

$seed();
[$o, $a] = runPostHandler('../user/post/profile.php', 2, ['sign_out_everywhere' => 1]);
$ok("sign out everywhere (self): own passkeys removed ($a)", $pk(2) === 0);
$ok('sign out everywhere: own API tokens removed', $tk(2) === 0);
$ok("sign out everywhere: other users' passkeys and tokens untouched", $pk(1) === 1 && $pk(3) === 1 && $tk(3) === 1);

$seed();
[$o, $a] = runPostHandler('users.php', 1, ['edit_user' => 1, 'user_id' => 2, 'name' => 'Tech Two', 'email' => 'tech@x.test', 'role' => 92, 'new_password' => 'Brand-New-Pass-9876', 'admin_current_password' => 'Admin-Pass-123456'], [], 'admin');
$ok("administrator sets a user's password: that user's passkeys and tokens are gone ($a)", $pk(2) === 0 && $tk(2) === 0);
$ok('...and nobody else is touched', $pk(1) === 1 && $pk(3) === 1 && $tk(3) === 1);

$seed();
[$o, $a] = runPostHandler('users.php', 1, ['edit_user' => 1, 'user_id' => 2, 'name' => 'Tech Two B', 'email' => 'tech@x.test', 'role' => 92, 'new_password' => ''], [], 'admin');
$ok('an edit that does not change the password keeps passkeys', $pk(2) === 1 && $tk(2) === 1);

$seed();
[$o, $a] = runPostHandler('users.php', 1, ['ir_reset_user_password' => 1, 'admin_password' => 'wrong'], [], 'admin');
$ok('IR reset with a wrong admin password changes nothing', $pk(2) === 1 && $pk(3) === 1);
[$o, $a] = runPostHandler('users.php', 1, ['ir_reset_user_password' => 1, 'admin_password' => 'Admin-Pass-123456'], [], 'admin');
$ok("IR reset removes every other agent's passkeys and tokens ($a)", $pk(2) === 0 && $pk(3) === 0 && $tk(2) === 0 && $tk(3) === 0);
$ok('IR reset keeps the acting admin\'s own passkey', $pk(1) === 1);

$f = file_get_contents(__DIR__ . '/../agent/user/passkey_enroll_finish.php');
$ok('enrolment finish: CSRF token required', str_contains($f, "hash_equals((string) \$_SESSION['csrf_token']") && strpos($f, 'csrf_token') < strpos($f, 'secReauthPassword'));
$ok('enrolment finish: throttled fresh password check before the challenge', strpos($f, 'secReauthPassword') < strpos($f, 'passkey_register_challenge\'] ?? null'));
$ok('enrolment finish: owner is emailed', str_contains($f, 'secNotifyAccountChange('));
$ok('the form sends the CSRF token', str_contains(file_get_contents(__DIR__ . '/../agent/user/user_security.php'), "csrf_token: '<?= \$_SESSION[\"csrf_token\"] ?>', current_password"));
exit($fail ? 1 : 0);
