<?php
// MSP-DS-94: an inbound bounce writes a System note only for a ticket whose outbound mail it quotes by Message-ID. Run:
// SCRATCH_TABLES="email_queue tickets ticket_replies" tests/lib/with_scratch_db.sh php tests/msp_ds_94_ndr_message_id.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
require __DIR__ . '/../src/Mail/MessageId.php';
class_alias('RivetMSP\Mail\MessageId', 'MessageId');
function sanitizeInput($v) { global $mysqli; return mysqli_real_escape_string($mysqli, strip_tags(trim((string) $v))); }
$GLOBALS['notified'] = 0; function appNotify() { $GLOBALS['notified']++; }
$src = file_get_contents(__DIR__ . '/../cron/ticket_email_parser.php');
preg_match('/function handleInboundNdr\(.*?\n\}\n/s', $src, $m);
eval($m[0]);
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$mysqli->query("INSERT INTO tickets (ticket_id, ticket_number) VALUES (7, 1007), (8, 1008)");
$mysqli->query("INSERT INTO email_queue (email_id, email_recipient, email_from, email_subject, email_content, email_message_id, email_ticket_id) VALUES (1,'c@x','h@x','[TKT1007] hi','b','real-id@host',7)");
$notes = fn () => (int) $mysqli->query("SELECT COUNT(*) FROM ticket_replies")->fetch_row()[0];
function dsn(string $subject, string $mid): array {
    return [['content_type' => 'message/delivery-status', 'content' => "Final-Recipient: rfc822; c@x\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 no user"],
            ['content_type' => 'message/rfc822', 'content' => "Subject: $subject\n" . ($mid !== '' ? "Message-ID: <$mid>\n" : '') . "To: c@x\n"]];
}
$config_ticket_prefix = 'TKT'; $config_ticket_prefix_re = 'TKT';
// forged: names ticket 8 in the subject, but quotes no Message-ID this server sent
handleInboundNdr(dsn('[TKT1008] forged', ''), 'Undelivered', '', 'postmaster@evil.tld', 1);
$ok('forged bounce naming a ticket by subject writes no note', $notes() === 0);
handleInboundNdr(dsn('[TKT1008] forged', 'attacker-made@evil.tld'), 'Undelivered', '', 'postmaster@evil.tld', 1);
$ok('bounce quoting an unknown Message-ID writes no note', $notes() === 0);
$ok('admins are still notified of the bounce', $GLOBALS['notified'] === 2);
// genuine: quotes the id we queued for ticket 7 (the subject says 1008 on purpose: the queue row decides)
handleInboundNdr(dsn('[TKT1008] x', 'REAL-ID@host'), 'Undelivered', '', 'mailer-daemon@host', 1);
$r = $mysqli->query("SELECT ticket_reply_ticket_id, ticket_reply_type FROM ticket_replies")->fetch_all();
$ok('genuine bounce writes one System note on the ticket of the queued mail (id 7)', count($r) === 1 && (int) $r[0][0] === 7 && $r[0][1] === 'System');
exit($fail ? 1 : 0);
