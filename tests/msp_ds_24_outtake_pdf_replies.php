<?php
// MSP-DS-24: the signed outtake PDF lists only Public/Client replies. Run: SCRATCH_TABLES="ticket_replies users" tests/lib/with_scratch_db.sh php tests/msp_ds_24_outtake_pdf_replies.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../includes/outtake_functions.php');
preg_match('/mysqli_query\(\$mysqli, "(SELECT tr\.ticket_reply,.*?)"\);/s', $src, $q);
$ok('reply query found', !empty($q[1]));
$sql = str_replace('$ticket_id', '77', $q[1]);
foreach (['Public', 'Client', 'Internal', 'System', 'Labor', 'AI', 'reply'] as $i => $t) {
    $m->query("INSERT INTO ticket_replies SET ticket_reply = 'body $t', ticket_reply_type = '$t', ticket_reply_by = 0, ticket_reply_ticket_id = 77");
}
$types = [];
$r = $m->query($sql);
while ($row = $r->fetch_assoc()) { $types[] = $row['ticket_reply_type']; }
sort($types);
$ok('only Public and Client replies reach the PDF: ' . implode(',', $types), $types === ['Client', 'Public']);
$ok('signing is claimed atomically (UPDATE ... outtake_signed_at IS NULL, affected rows checked)', str_contains($src, 'outtake_id = $outtake_id AND outtake_signed_at IS NULL"') && str_contains($src, 'mysqli_affected_rows($mysqli) !== 1'));
exit($fail ? 1 : 0);
