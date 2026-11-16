<?php
// MSP-DS-105: Comet, automation, mail-failure and NDR notifications carry the client id (or no client data). Run:
// SCRATCH_TABLES="email_queue tickets ticket_replies" tests/lib/with_scratch_db.sh php tests/msp_ds_105_notification_scope.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
require __DIR__ . '/../src/Mail/MessageId.php';
class_alias('RivetMSP\Mail\MessageId', 'MessageId');
function sanitizeInput($v) { global $mysqli; return mysqli_real_escape_string($mysqli, strip_tags(trim((string) $v))); }
$GLOBALS['calls'] = []; function appNotify($type, $details, $action = null, $client_id = 0) { $GLOBALS['calls'][] = [$details, (int) $client_id]; }
$root = dirname(__DIR__);
preg_match('/function handleInboundNdr\(.*?\n\}\n/s', file_get_contents("$root/cron/ticket_email_parser.php"), $m);
eval($m[0]);
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$mysqli->query("INSERT INTO tickets (ticket_id, ticket_number, ticket_client_id) VALUES (7, 1007, 5)");
$mysqli->query("INSERT INTO email_queue (email_id, email_recipient, email_from, email_subject, email_content, email_message_id, email_ticket_id) VALUES (1,'c@x','h@x','s','b','real-id@host',7)");
$dsn = fn (string $mid) => [['content_type' => 'message/delivery-status', 'content' => "Final-Recipient: rfc822; secret-contact@clientb.example\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550"],
    ['content_type' => 'message/rfc822', 'content' => "Subject: Invoice for ClientB\n" . ($mid ? "Message-ID: <$mid>\n" : '')]];
$config_ticket_prefix = 'T'; $config_ticket_prefix_re = 'T';
handleInboundNdr($dsn('real-id@host'), 's', '', 'mailer-daemon@h', 1);
$ok('matched bounce: alert is scoped to the ticket\'s client (5) and may name the recipient', $calls[0][1] === 5 && str_contains($calls[0][0], 'secret-contact@clientb.example'));
handleInboundNdr($dsn(''), 's', '', 'mailer-daemon@h', 1);
$ok('unmatched bounce: alert carries no recipient or subject', !str_contains($calls[1][0], 'secret-contact') && !str_contains($calls[1][0], 'ClientB'));
foreach (['includes/comet.php' => 2, 'cron/cron.php' => 2, 'includes/event_bus.php' => 1, 'cron/mail_queue.php' => 3] as $f => $n) {
    $s = file_get_contents("$root/$f");
    $ok("$f: $n MSP-DS-105 call sites pass a client id", substr_count($s, 'MSP-DS-105') >= 1 && ($f !== 'cron/mail_queue.php' || substr_count($s, '$mail_client_id);') >= 3));
}
$c = file_get_contents("$root/includes/comet.php");
$ok('comet: both notifications pass $client_id', substr_count($c, '(int) $client_id);   // MSP-DS-105') === 2);
$e = file_get_contents("$root/includes/event_bus.php");
$ok("automation notify_user passes the event's client_id", str_contains($e, "(int) (\$ctx['client_id'] ?? 0), 0, true)"));
$cr = file_get_contents("$root/cron/cron.php");
$ok('cron.php mail failures pass the invoice client', substr_count($cr, 'null, (int) $client_id);   // MSP-DS-105') === 2);
exit($fail ? 1 : 0);
