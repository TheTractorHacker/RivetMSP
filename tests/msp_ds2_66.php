<?php
// MSP-DS-66: every close/status path asks the ONE helper ticketCloseBlockReason(). Run:
//   SCRATCH_TABLES="ticket_outtake_forms ticket_worksheets tasks" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_66.php
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/ticket.php'); $a = ds_src('agent/ajax.php'); $api = ds_src('api/v1/tickets.php');
function blk($src, $marker, $len = 14000) { $i = strpos($src, $marker); return $i === false ? '' : substr($src, $i, $len); }
$paths = ['edit_ticket_status', 'quick_status_ticket', 'add_ticket_reply', 'bulk_ticket_reply', 'bulk_resolve_tickets', 'merge_ticket', 'bulk_merge_tickets'];
foreach ($paths as $n) {
    $b = blk($s, "isset(\$_POST['$n'])", 9000);
    ds_t("$n calls the helper", strpos($b, 'ticketCloseBlockReason(') !== false);
}
foreach (['resolve_ticket' => "isset(\$_GET['resolve_ticket'])", 'close_ticket' => "isset(\$_GET['close_ticket'])"] as $n => $m) {
    $b = blk($s, $m, 3000);
    ds_t("$n uses the helper (with tasks)", strpos($b, 'ticketCloseBlockReason($ticket_id, true)') !== false);
}
ds_t('no private outtake SQL left outside the helper in ticket.php', substr_count($s, 'outtake_signed_at IS NULL') === 0);
ds_t('kanban resolve calls the helper', strpos($a, "\$status === \$statuses['Resolved'] && ticketCloseBlockReason(\$ticket_id)") !== false);
$b = blk($s, "isset(\$_POST['add_ticket_reply'])", 9000);
ds_t('add_ticket_reply checks before any INSERT', strpos($b, 'ticketCloseBlockReason') < strpos($b, 'INSERT INTO'));
$b = blk($s, "isset(\$_POST['merge_ticket'])", 9000);
ds_t('merge checks before replies are moved', strpos($b, 'ticketCloseBlockReason') < strpos($b, 'UPDATE ticket_replies'));
// api: reply path checks before the reply INSERT, status path before the UPDATE; refusal uses the existing {error} shape (api_error)
$i = strpos($api, 'ticketCloseBlockReason($id)');
ds_t('api reply path calls the helper before the INSERT', $i !== false && $i < strpos($api, 'INSERT INTO ticket_replies'));
$j = strpos($api, 'ticketCloseBlockReason($id)', $i + 10);
ds_t('api status path calls the helper before closing', $j !== false && $j < strpos($api, "UPDATE tickets SET ticket_status = \$status, ticket_updated_at = NOW(), ticket_resolved_at = NOW()", $j));
ds_t('api refusals use api_error', substr_count($api, 'api_error(400, "Cannot close ticket - $close_block")') === 2);
ds_t('legacy api resolve.php gated', strpos(ds_src('api/v1/tickets/resolve.php'), 'ticketCloseBlockReason') !== false);
if (getenv('RIVETMSP_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $mysqli->query("SET SESSION sql_mode=''"); $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
    $f = ds_src('functions.php'); $i = strpos($f, 'function ticketCloseBlockReason'); eval(substr($f, $i, strpos($f, "\n}\n", $i) + 2));
    ds_t('clean ticket may close', ticketCloseBlockReason(1) === null && ticketCloseBlockReason(1, true) === null);
    $mysqli->query("INSERT INTO ticket_outtake_forms (outtake_ticket_id) VALUES (2)");
    ds_t('unsigned outtake form blocks', is_string(ticketCloseBlockReason(2)));
    $mysqli->query("INSERT INTO ticket_outtake_forms (outtake_ticket_id, outtake_signed_at) VALUES (3, NOW())");
    ds_t('signed outtake form does not block', ticketCloseBlockReason(3) === null);
    $mysqli->query("INSERT INTO ticket_worksheets (worksheet_ticket_id, worksheet_is_outtake) VALUES (4, 1)");
    ds_t('unfinalized outtake worksheet blocks', is_string(ticketCloseBlockReason(4)));
    $mysqli->query("INSERT INTO tasks (task_name, task_ticket_id) VALUES ('t', 5)");
    ds_t('open task blocks only with $with_tasks', ticketCloseBlockReason(5) === null && is_string(ticketCloseBlockReason(5, true)));
    $mysqli->query("UPDATE tasks SET task_completed_at = NOW() WHERE task_ticket_id = 5");
    ds_t('completed task does not block', ticketCloseBlockReason(5, true) === null);
}
ds_done();
