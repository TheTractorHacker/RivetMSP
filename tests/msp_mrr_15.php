<?php
// MSP-DS-66 (re-review MRR-15): the REAL automationExecuteAction() (includes/automation_functions.php) goes through ticketCloseBlockReason() for its
// set_status=Resolved/Closed and close_ticket actions. A ticket with an unsigned outtake form or unfinalized outtake worksheet is not closed and the skip is logged;
// a ticket without one is still closed, and non-closing status changes are untouched.
// Run: SCRATCH_TABLES="tickets ticket_outtake_forms ticket_worksheets tasks logs settings sla_policies" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_15.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
$root = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'test';
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$GLOBALS['mysqli'] = $mysqli;
$config_app_name = 'Test'; $config_base_url = 'localhost'; $config_timezone = 'UTC';
$session_name = 'Automation'; $session_user_id = 0; $session_ip = '127.0.0.1'; $session_user_agent = 'test';
require $root . '/functions.php';
require $root . '/includes/automation_functions.php';

$t = fn (int $id, int $status = 2) => $mysqli->query("INSERT INTO tickets (ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_client_id) VALUES ($id, 'T', $id, 's', $status, 1)");
$status = fn (int $id) => (int) $mysqli->query("SELECT ticket_status FROM tickets WHERE ticket_id = $id")->fetch_row()[0];
$run = function (string $act, string $val, int $tid) use ($mysqli) {
    $ctx = ['tid' => $tid, 'client_id' => 1];
    return automationExecuteAction($mysqli, ['action' => $act, 'value' => $val], $ctx, ['rule_name' => 'R', 'rule_id' => 1]);
};
$skips = fn () => (int) $mysqli->query("SELECT COUNT(*) FROM logs WHERE log_type = 'Automation' AND log_action = 'Skipped' AND log_description LIKE '%did not%'")->fetch_row()[0];

// ticket 1: unsigned outtake form
$t(1);
$mysqli->query("INSERT INTO ticket_outtake_forms (outtake_ticket_id) VALUES (1)");
$r = $run('close_ticket', '', 1);
$ok('close_ticket on a ticket with an unsigned outtake form is skipped', $r === null && $status(1) === 2 && $skips() === 1);
$r = $run('set_status', '5', 1);
$ok('set_status=Closed is skipped too', $r === null && $status(1) === 2 && $skips() === 2);
$r = $run('set_status', '4', 1);
$ok('set_status=Resolved is skipped too', $r === null && $status(1) === 2 && $skips() === 3);
$r = $run('set_status', '3', 1);
$ok('a non-closing status change still happens', $status(1) === 3);

// ticket 2: unfinalized outtake worksheet
$t(2);
$mysqli->query("INSERT INTO ticket_worksheets (worksheet_ticket_id, worksheet_is_outtake) VALUES (2, 1)");
$run('close_ticket', '', 2);
$ok('an unfinalized outtake worksheet blocks automation close', $status(2) === 2);

// ticket 3: signed form -> closes
$t(3);
$mysqli->query("INSERT INTO ticket_outtake_forms (outtake_ticket_id, outtake_signed_at) VALUES (3, NOW())");
$run('close_ticket', '', 3);
$ok('a signed outtake form does not block close_ticket', $status(3) === 5);
$t(4);
$run('set_status', '5', 4);
$ok('a plain ticket is still closed by set_status', $status(4) === 5);
exit($fail ? 1 : 0);
