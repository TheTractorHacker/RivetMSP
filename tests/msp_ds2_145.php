<?php
// MSP-DS-145 (complete): the REAL portal gate (client/includes/check_login.php) ends a portal session when the portal is off, the client is archived,
// the idle/absolute limit passes, OR the account's session version (users.user_session_version) was bumped by a password change/reset/set-by-staff.
// Run: SCRATCH_TABLES="settings users clients contacts companies" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_145.php
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
$ok('users.user_session_version exists in db.sql', ($m->query("SHOW COLUMNS FROM users LIKE 'user_session_version'")->num_rows ?? 0) === 1);

$gate = function (array $session) {
    $p = proc_open([PHP_BINARY, __DIR__ . '/lib/run_portal_gate.php', json_encode(['session' => $session])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);

    return preg_match('/::END::(PASS|BLOCKED):sv=([^:]*):/', $out, $x) ? [$x[1], $x[2]] : ['ERR', $out];
};
$new = function () use ($m) {   // a portal sign-in exactly as login.php does it
    $s = ['client_logged_in' => true, 'client_id' => 7, 'user_id' => 20, 'contact_id' => 30, 'user_type' => 2, 'csrf_token' => 'x'];
    secPortalSessionBegin($s, null, $m, 20);

    return $s;
};

$s = $new();
$ok('sign-in stores the account version', $s['portal_sv'] === 0);
$ok('a fresh portal session passes the gate', $gate($s)[0] === 'PASS');
$ok('a session that predates the column (no version) is accepted and adopts the current one', $gate(array_diff_key($s, ['portal_sv' => 1])) === ['PASS', '0']);

// revocation by version (portal password change / reset / staff-set password)
$v = secPortalSessionsRevoke($m, 20);
$ok('revoke bumps the version', $v === 1 && (int) $m->query("SELECT user_session_version FROM users WHERE user_id = 20")->fetch_row()[0] === 1);
$ok('the older session is now ended by the gate', $gate($s)[0] === 'BLOCKED');
$s2 = $new();
$ok('a new sign-in after the bump carries the new version and passes', $s2['portal_sv'] === 1 && $gate($s2)[0] === 'PASS');
$m->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status) VALUES (5, 'Agent', 'a@x.test', '$hash', 1, 1)");
$ok('revoke only touches portal (type 2) accounts', secPortalSessionsRevoke($m, 5) === 0 && (int) $m->query("SELECT user_session_version FROM users WHERE user_id = 5")->fetch_row()[0] === 0);

// the earlier gates still hold
$old = $s2; $old['portal_started_at'] = time() - 90000;
$ok('absolute 24h limit ends it', $gate($old)[0] === 'BLOCKED');
$old = $s2; $old['portal_last_activity'] = time() - 30000;
$ok('idle 8h limit ends it', $gate($old)[0] === 'BLOCKED');
$m->query("UPDATE clients SET client_archived_at = NOW() WHERE client_id = 7");
$ok('archiving the client ends it', $gate($s2)[0] === 'BLOCKED');
$m->query("UPDATE clients SET client_archived_at = NULL");
$ok('...and restoring it lets a session through again', $gate($s2)[0] === 'PASS');
$m->query("UPDATE settings SET config_client_portal_enable = 0 WHERE company_id = 1");
$ok('switching the portal off ends it', $gate($s2)[0] === 'BLOCKED');

// wiring: every place that changes a portal password revokes
$w = fn (string $f) => file_get_contents("$root/$f");
$ok('portal self-service password change revokes the others and keeps this browser', str_contains($w('client/post.php'), "\$_SESSION['portal_sv'] = secPortalSessionsRevoke("));
$ok('portal password reset by e-mail link revokes', str_contains($w('client/login_reset.php'), 'secPortalSessionsRevoke($mysqli, $user_id)'));
$ok('staff setting a contact password / login method / email revokes', str_contains($w('agent/post/contact.php'), 'secPortalSessionsRevoke($mysqli, $contact_user_id)'));
foreach (['login.php', 'client/login_oidc.php', 'client/login_microsoft.php'] as $f) {
    $ok("$f: sign-in records the version", str_contains($w($f), 'secPortalSessionBegin($_SESSION, null, $mysqli,'));
}
exit($fail ? 1 : 0);
