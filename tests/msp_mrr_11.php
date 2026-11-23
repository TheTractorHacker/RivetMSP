<?php
// MSP-DS-145 (re-review MRR-11): the REAL portal client/post.php edit_contact bumps users.user_session_version when it changes the edited
// contact's sign-in email or login method, so that contact's open portal sessions end (checked with the REAL portal gate). A name-only edit does not.
// Run: SCRATCH_TABLES="settings users clients contacts companies logs" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_11.php
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
$m->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status, user_auth_method) VALUES (20, 'Pat', 'pat@acme.test', '$hash', 2, 1, 'local'), (21, 'Sam', 'sam@acme.test', '$hash', 2, 1, 'local')");
$m->query("INSERT INTO contacts (contact_id, contact_name, contact_email, contact_client_id, contact_user_id, contact_primary) VALUES (30, 'Pat', 'pat@acme.test', 7, 20, 1), (31, 'Sam', 'sam@acme.test', 7, 21, 0)");
$ver = fn (int $u) => (int) $m->query("SELECT user_session_version FROM users WHERE user_id = $u")->fetch_row()[0];
$run = function (array $session, array $post, string $file = 'post.php') {
    $p = proc_open([PHP_BINARY, __DIR__ . '/lib/run_portal_gate.php', json_encode(['session' => $session, 'post' => $post, 'file' => $file])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);

    return preg_match('/::END::(PASS|BLOCKED):sv=([^:]*):(.*)$/m', $out, $x) ? [$x[1], $x[2], $x[3]] : ['ERR', '', $out];
};
$pat = ['client_logged_in' => true, 'client_id' => 7, 'user_id' => 20, 'contact_id' => 30, 'user_type' => 2, 'csrf_token' => 'x'];
secPortalSessionBegin($pat, null, $m, 20);
$sam = ['client_logged_in' => true, 'client_id' => 7, 'user_id' => 21, 'contact_id' => 31, 'user_type' => 2, 'csrf_token' => 'x'];
secPortalSessionBegin($sam, null, $m, 21);
$edit = fn (array $o) => $o + ['edit_contact' => 1, 'contact_id' => 31, 'contact_name' => 'Sam', 'contact_email' => 'sam@acme.test', 'contact_auth_method' => 'local'];
$samGate = function () use (&$run, &$sam) { return $run($sam, [], "includes/check_login.php")[0]; };

$ok('baseline: Sam passes the gate', $samGate() === 'PASS' && $ver(21) === 0);

[$st, , $alert] = $run($pat, $edit(['contact_name' => 'Samuel']));
$ok("name-only edit saved ($alert)", (string) $m->query("SELECT user_name FROM users WHERE user_id = 21")->fetch_row()[0] === 'Samuel');
$ok('name-only edit does not end Sam\'s session', $ver(21) === 0 && $samGate() === 'PASS');

[$st, , $alert] = $run($pat, $edit(['contact_name' => 'Samuel', 'contact_auth_method' => '']));
$ok("login method removed ($alert)", (string) $m->query("SELECT user_auth_method FROM users WHERE user_id = 21")->fetch_row()[0] === '');
$ok('login-method change bumps the version and ends Sam\'s session', $ver(21) === 1 && $samGate() === 'BLOCKED');

$m->query("UPDATE users SET user_auth_method = 'local' WHERE user_id = 21");   // (an empty login method removes portal access altogether)
secPortalSessionBegin($sam, null, $m, 21);
$ok('a fresh sign-in after the change passes', $samGate() === 'PASS' && $ver(21) === 1);
[$st, , $alert] = $run($pat, $edit(['contact_name' => 'Samuel', 'contact_auth_method' => 'local', 'contact_email' => 'sam.new@acme.test']));
$ok("sign-in email changed ($alert)", (string) $m->query("SELECT user_email FROM users WHERE user_id = 21")->fetch_row()[0] === 'sam.new@acme.test');
$ok('email change bumps the version again and ends the session', $ver(21) === 2 && $samGate() === 'BLOCKED');
$ok('the editor\'s own session is untouched', $ver(20) === 0 && $run($pat, [], 'includes/check_login.php')[0] === 'PASS');
exit($fail ? 1 : 0);
