<?php
// MSP-DS-150 (complete): the REAL portal client/post.php edit_profile needs the current password, the check is throttled (5 wrong guesses per
// 5 minutes per account, atomic, the same counter as sign-in), and a successful change ends the account's other portal sessions.
// Run: SCRATCH_TABLES="settings users clients contacts companies logs" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_150.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
require_once $root . '/includes/security_sessions.php';
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$m->query("INSERT INTO settings (company_id, config_client_portal_enable) VALUES (1, 1)");
$m->query("INSERT INTO companies (company_id, company_name, company_locale, company_currency, company_country) VALUES (1, 'MSP', 'en_US', 'USD', 'US')");
$m->query("INSERT INTO clients (client_id, client_name) VALUES (7, 'Acme')");
$hash = password_hash('portal-pass-1', PASSWORD_DEFAULT);
$m->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status) VALUES (20, 'Pat', 'pat@acme.test', '$hash', 2, 1)");
$m->query("INSERT INTO contacts (contact_id, contact_name, contact_email, contact_client_id, contact_user_id) VALUES (30, 'Pat', 'pat@acme.test', 7, 20)");
$pwd = fn () => (string) $m->query("SELECT user_password FROM users WHERE user_id = 20")->fetch_row()[0];
$run = function (array $session, array $post, string $file = 'post.php') {
    $p = proc_open([PHP_BINARY, __DIR__ . '/lib/run_portal_gate.php', json_encode(['session' => $session, 'post' => $post, 'file' => $file])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);

    return preg_match('/::END::(PASS|BLOCKED):sv=([^:]*):(.*)$/m', $out, $x) ? [$x[1], $x[2], $x[3]] : ['ERR', '', $out];
};
$s = ['client_logged_in' => true, 'client_id' => 7, 'user_id' => 20, 'contact_id' => 30, 'user_type' => 2, 'csrf_token' => 'x'];
secPortalSessionBegin($s, null, $m, 20);
$other = $s;   // a second browser of the same account
$edit = ['edit_profile' => 1, 'new_password' => 'Another-Portal-Pass-77'];

[$st, $sv, $alert] = $run($s, $edit + ['current_password' => '']);
$ok("no current password: refused ($alert)", str_contains($alert, 'Current password is incorrect') && $pwd() === $hash);
[$st, $sv, $alert] = $run($s, $edit + ['current_password' => 'wrong-1']);
$ok('wrong current password: refused, password unchanged', str_contains($alert, 'Current password is incorrect') && $pwd() === $hash);
for ($i = 2; $i <= 5; $i++) { $run($s, $edit + ['current_password' => "wrong-$i"]); }
[$st, $sv, $alert] = $run($s, $edit + ['current_password' => 'portal-pass-1']);
$ok("after 5 wrong guesses even the RIGHT password is refused (throttled) ($alert)", str_contains($alert, 'Current password is incorrect') && $pwd() === $hash);
$ok('the counter is the account\'s sign-in counter', (int) $m->query("SELECT user_failed_login_count FROM users WHERE user_id = 20")->fetch_row()[0] >= 5);

$m->query("UPDATE users SET user_failed_login_at = UTC_TIMESTAMP() - INTERVAL 6 MINUTE WHERE user_id = 20");
[$st, $sv, $alert] = $run($s, $edit + ['current_password' => 'portal-pass-1']);
$ok("window over, right current password: password changed", $pwd() !== $hash && password_verify('Another-Portal-Pass-77', $pwd()));
$ok('this browser stays signed in with the new version', $sv === '1');
$ok('success resets the counter', (int) $m->query("SELECT user_failed_login_count FROM users WHERE user_id = 20")->fetch_row()[0] === 0);
$g = $run($other, [], 'includes/check_login.php');
$ok('the other browser of the account is signed out', $g[0] === 'BLOCKED');
$mine = $s; $mine['portal_sv'] = 1; $mine['portal_pw_fp'] = hash('sha256', $pwd());
$ok('this browser still passes the gate', $run($mine, [], 'includes/check_login.php')[0] === 'PASS');

// the shared check keeps its agent behaviour and does not cross user types
require_once $root . '/includes/security_policy.php';
$m->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status) VALUES (5, 'Agent', 'a@x.test', '$hash', 1, 1)");
$ok('agent re-auth unchanged (default type 1)', secReauthPassword($m, 5, 'portal-pass-1') === true);
$ok('a portal account cannot be re-authed as an agent, nor an agent as a portal login', secReauthPassword($m, 20, 'Another-Portal-Pass-77') === false && secReauthPassword($m, 5, 'portal-pass-1', 2) === false);
exit($fail ? 1 : 0);
