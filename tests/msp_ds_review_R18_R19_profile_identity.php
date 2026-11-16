<?php
// Review R18 (an unchanged display name that another agent shares must not block a profile save) and R19 (validation runs BEFORE the
// email-change notice and the avatar replacement). Runs the real agent/user/post/profile.php handler against a scratch DB.
// Run: SCRATCH_TABLES="users user_roles user_settings logs" bash tests/lib/with_scratch_db.sh php tests/msp_ds_review_R18_R19_profile_identity.php
require __DIR__ . '/lib/handler_run.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$hash = password_hash('Correct-Pass-1', PASSWORD_DEFAULT);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (92,'Tech',0)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES
  (1,'John Smith','john1@x.test','$hash',92,1,1),(2,'John Smith','john2@x.test','$hash',92,1,1),(3,'Bob','bob@x.test','$hash',92,1,1)");
$m->query("INSERT INTO user_settings (user_id, user_config_signature) VALUES (1,'')");
$base = ['edit_your_user_details' => 1, 'name' => 'John Smith', 'email' => 'john1@x.test', 'title' => '', 'phone' => '', 'signature' => 'sig', 'current_password' => 'Correct-Pass-1'];

[$o, $alert] = runPostHandler('../user/post/profile.php', 1, ['phone' => '555-0100'] + $base);
$ok("R18: unchanged shared display name + new phone saves ($alert)", $one("SELECT user_phone FROM users WHERE user_id=1") === '555-0100' && str_starts_with($alert, 'success'));
[$o, $alert] = runPostHandler('../user/post/profile.php', 1, ['name' => 'john smith'] + $base);
$ok('R18: CHANGING the name onto another agent\'s name is still refused', str_contains($alert, 'already used') && $one("SELECT user_name FROM users WHERE user_id=1") === 'John Smith');
[$o, $alert] = runPostHandler('../user/post/profile.php', 1, ['name' => 'bob@x.test'] + $base);
$ok('R18: name = another agent\'s email is still refused', str_contains($alert, 'already used'));
[$o, $alert] = runPostHandler('../user/post/profile.php', 1, ['email' => 'john2@x.test'] + $base);
$ok('R18: taking another agent\'s email is still refused', str_contains($alert, 'already used') && $one("SELECT user_email FROM users WHERE user_id=1") === 'john1@x.test');
// R19: a refused save leaves nothing behind (the email-change notice needs SMTP configured, so the order is also checked in source)
[$o, $alert] = runPostHandler('../user/post/profile.php', 1, ['email' => 'not-an-email'] + $base);
$ok('R19: invalid email is refused with a message', str_contains($alert, 'valid email'));
$src = file_get_contents(__DIR__ . '/../agent/user/post/profile.php');
$v = strpos($src, 'already used by another user'); $mail = strpos($src, 'addToMailQueue($data)'); $av = strpos($src, "checkFileUpload(\$_FILES['avatar']");
$ok('R19: validation precedes the email-change notice and the avatar replacement', $v !== false && $v < $mail && $v < $av);
exit($fail ? 1 : 0);
