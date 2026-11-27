<?php
// MSP-DS-121 (complete): the REAL admin/post/users.php edit_user handler needs the acting admin's password for a new password, a changed sign-in
// email or a role change on ANY account (not only administrators), restore_user with a new password too; the throttled secReauthPassword is
// the gate; and the owner is told at the old address (secNotifyAccountChange).
// Run: SCRATCH_TABLES="users user_roles user_settings logs user_client_permissions api_tokens remember_tokens user_sessions clients" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_121.php
require __DIR__ . '/lib/handler_run.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$hash = password_hash('Admin-Pass-123456', PASSWORD_DEFAULT);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Admin',1),(92,'Tech',0),(93,'Tech2',0)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES
  (1,'Admin One','admin@x.test','$hash',91,1,1),(2,'Tech Two','tech@x.test','$hash',92,1,1)");
$m->query("INSERT INTO user_settings (user_id) VALUES (1),(2)");
$base = ['edit_user' => 1, 'user_id' => 2, 'name' => 'Tech Two', 'email' => 'tech@x.test', 'role' => 92, 'new_password' => ''];
$pw = $one("SELECT user_password FROM users WHERE user_id = 2");
$run = fn (array $over, string $adminPw = '') => runPostHandler('users.php', 1, array_merge($base, $over, $adminPw !== '' ? ['admin_current_password' => $adminPw] : []), [], 'admin');

[$o, $a] = $run(['name' => 'Tech Two B']);
$ok("a harmless edit (name only) needs no password ($a)", $one("SELECT user_name FROM users WHERE user_id=2") === 'Tech Two B');

[$o, $a] = $run(['new_password' => 'Brand-New-Pass-9876']);
$ok("new password for a NON-admin without the admin password is refused ($a)", str_starts_with($a, 'error') && $one("SELECT user_password FROM users WHERE user_id=2") === $pw);
[$o, $a] = $run(['email' => 'evil@x.test']);
$ok("sign-in email change for a non-admin without the password is refused", $one("SELECT user_email FROM users WHERE user_id=2") === 'tech@x.test');
[$o, $a] = $run(['role' => 93]);
$ok("role change (non-admin to non-admin) without the password is refused", (int) $one("SELECT user_role_id FROM users WHERE user_id=2") === 92);
[$o, $a] = $run(['email' => 'evil@x.test'], 'wrong-password');
$ok("wrong admin password is refused", $one("SELECT user_email FROM users WHERE user_id=2") === 'tech@x.test');
[$o, $a] = $run(['email' => 'new@x.test'], 'Admin-Pass-123456');
$ok("correct admin password: email change applied ($a)", $one("SELECT user_email FROM users WHERE user_id=2") === 'new@x.test');
[$o, $a] = $run(['email' => 'new@x.test', 'role' => 93], 'Admin-Pass-123456');
$ok("correct admin password: role change applied", (int) $one("SELECT user_role_id FROM users WHERE user_id=2") === 93);
[$o, $a] = $run(['email' => 'new@x.test', 'role' => 93, 'new_password' => 'Brand-New-Pass-9876'], 'Admin-Pass-123456');
$ok("correct admin password: password change applied ($a)", $one("SELECT user_password FROM users WHERE user_id=2") !== $pw);

// throttle: five wrong guesses lock the re-auth, the right password is then refused too
for ($i = 0; $i < 5; $i++) { $run(['email' => 'zz@x.test'], "bad$i"); }
[$o, $a] = $run(['email' => 'zz@x.test'], 'Admin-Pass-123456');
$ok("re-auth is throttled: right password refused after repeated wrong ones", $one("SELECT user_email FROM users WHERE user_id=2") === 'new@x.test');

// the notice helper (real function from security_policy.php, mail queue stubbed)
$src = file_get_contents(__DIR__ . '/../includes/security_policy.php');
preg_match('/    function secNotifyAccountChange\(.*?\n    }\n/s', $src, $mm);
$queued = [];
function addToMailQueue(array $d) { global $queued; $queued[] = $d; }
eval($mm[0]);   // the real helper, extracted from a trusted repo file
$config_smtp_host = ''; $config_mail_from_email = 'f@x.test'; $config_mail_from_name = 'F'; $config_app_name = 'RivetMSP';
$ok('no SMTP configured: nothing queued', secNotifyAccountChange('tech@x.test', 'Tech', ['Your password was changed.'], 'Admin') === false && $queued === []);
$config_smtp_host = 'smtp.x.test';
$ok('queued to the OLD address with the change and the acting admin', secNotifyAccountChange('tech@x.test', 'Tech <b>', ['Your sign-in email was changed.'], 'Admin One') === true
    && $queued[0][0]['recipient'] === 'tech@x.test' && str_contains($queued[0][0]['body'], 'sign-in email was changed') && str_contains($queued[0][0]['body'], 'Admin One') && !str_contains($queued[0][0]['body'], '<b>'));
$ok('no change, no mail', secNotifyAccountChange('tech@x.test', 'Tech', [], 'Admin') === false);
$u = file_get_contents(__DIR__ . '/../admin/post/users.php');
$ok('edit_user calls the notice with the pre-change address', str_contains($u, "secNotifyAccountChange((string) \$target_now['user_email']"));
exit($fail ? 1 : 0);
