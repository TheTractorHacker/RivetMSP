<?php
// MSP-DS-91: the portal add/edit contact forms log every address refusal and stop answering after 5 refusals an hour per client, so they
// cannot be walked as a user-existence oracle. Runs the real portalContactProbeLimited() on a scratch DB.
// Run: SCRATCH_TABLES="logs" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_91.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$root = dirname(__DIR__);
require $root . '/includes/inbound_routing.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$log = fn (int $client, string $age = 'NOW()', string $action = 'Contact Address Refused') => $mysqli->query("INSERT INTO logs SET log_type='Client Portal', log_action='$action', log_description='x', log_client_id=$client, log_created_at=$age");
$ok('no refusals: not limited', portalContactProbeLimited($mysqli, 7) === false);
for ($i = 0; $i < 4; $i++) { $log(7); }
$ok('4 refusals: still allowed', portalContactProbeLimited($mysqli, 7) === false);
$log(7);
$ok('5 refusals within the hour: limited', portalContactProbeLimited($mysqli, 7) === true);
$ok('another client is unaffected', portalContactProbeLimited($mysqli, 8) === false);
for ($i = 0; $i < 6; $i++) { $log(9, 'NOW() - INTERVAL 2 HOUR'); }
$ok('refusals older than an hour do not count', portalContactProbeLimited($mysqli, 9) === false);
for ($i = 0; $i < 6; $i++) { $log(10, 'NOW()', 'Something Else'); }
$ok('other log actions do not count', portalContactProbeLimited($mysqli, 10) === false);

$p = file_get_contents($root . '/client/post.php');
$add = substr($p, strpos($p, "isset(\$_POST['add_contact'])"), 4000);
$edit = substr($p, strpos($p, "isset(\$_POST['edit_contact'])"), 5000);
foreach (['add' => $add, 'edit' => $edit] as $k => $blk) {
    $a = strpos($blk, 'portalContactProbeLimited('); $b = strpos($blk, 'FROM users WHERE user_email');
    $ok("$k: the limit is checked before the users-table lookup", $a !== false && $b !== false && $a < $b);
    $ok("$k: a login-table conflict is logged as a refusal", (bool) preg_match("/FROM users WHERE user_email.*?logAction\('Client Portal', 'Contact Address Refused'/s", $blk));
    $ok("$k: the limited answer is the same generic message", substr_count($blk, 'This email address cannot be used for a portal contact. Please contact support if you need help.') >= 3);
}
exit($fail ? 1 : 0);
