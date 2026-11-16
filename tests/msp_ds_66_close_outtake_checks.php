<?php
// MSP-DS-66: every close path asks ticketCloseBlockReason(). Run:
//   SCRATCH_TABLES="ticket_outtake_forms ticket_worksheets" bash tests/lib/with_scratch_db.sh php tests/msp_ds_66_close_outtake_checks.php
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/ticket.php'); $a = ds_src('agent/ajax.php');
foreach (['edit_ticket_status' => "isset(\$_POST['edit_ticket_status'])", 'quick_status_ticket' => "isset(\$_POST['quick_status_ticket'])", 'add_ticket_reply' => "isset(\$_POST['add_ticket_reply'])", 'bulk_ticket_reply' => "isset(\$_POST['bulk_ticket_reply'])", 'bulk_resolve_tickets' => "isset(\$_POST['bulk_resolve_tickets'])"] as $n => $m) {
    $i = strpos($s, $m); ds_t("$n calls the helper", $i !== false && strpos(substr($s, $i, 9000), 'ticketCloseBlockReason(') !== false);
}
ds_t('kanban resolve calls the helper', strpos($a, "\$status === \$statuses['Resolved'] && ticketCloseBlockReason(\$ticket_id)") !== false);
$i = strpos($s, "isset(\$_POST['add_ticket_reply'])"); $b = substr($s, $i, 9000);
ds_t('add_ticket_reply checks before any INSERT', strpos($b, 'ticketCloseBlockReason') < strpos($b, 'INSERT INTO'));
if (getenv('RIVETMSP_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $mysqli->query("SET SESSION sql_mode=''"); $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
    $f = ds_src('functions.php'); $i = strpos($f, 'function ticketCloseBlockReason'); eval(substr($f, $i, strpos($f, "\n}\n", $i) + 2));
    ds_t('clean ticket may close', ticketCloseBlockReason(1) === null);
    $mysqli->query("INSERT INTO ticket_outtake_forms (outtake_ticket_id) VALUES (2)");
    ds_t('unsigned outtake form blocks', is_string(ticketCloseBlockReason(2)));
    $mysqli->query("INSERT INTO ticket_outtake_forms (outtake_ticket_id, outtake_signed_at) VALUES (3, NOW())");
    ds_t('signed outtake form does not block', ticketCloseBlockReason(3) === null);
    $mysqli->query("INSERT INTO ticket_worksheets (worksheet_ticket_id, worksheet_is_outtake) VALUES (4, 1)");
    ds_t('unfinalized outtake worksheet blocks', is_string(ticketCloseBlockReason(4)));
}
ds_done();
