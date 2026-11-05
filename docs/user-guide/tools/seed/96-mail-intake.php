<?php
/*
 * Demo data for the mail pages of the integrations guide (30-mail-intake-and-sending.md), Lakeshore Managed IT.
 * EXAMPLE DATA, NOT A LIVE CONNECTION: no mailbox is polled in the sandbox, so this script writes the rows the poller would have
 * written: Email Log entries (one per outcome) and one quarantined message on the Mailboxes page.
 *
 *   RIVETIT_APP_DIR=/tmp/rivetmsp-user-guide-demo php docs/user-guide/tools/seed/96-mail-intake.php
 *
 * Depends on: seed 30 (the "Support inbox" mailbox), 31 (tickets), 32 (mail requests). Everything is looked up by name or subject and
 * guarded, so it can be re-run. Table mail_log / mail_intake_state: see docs/MAIL_INTAKE.md.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "96-mail-intake: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once $app . '/includes/inc_set_timezone.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "96-mail-intake: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($v) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $v); }

$mb = (int) one("SELECT mailbox_id FROM mailboxes ORDER BY mailbox_id LIMIT 1");
if (!$mb) { echo "96-mail-intake: no mailbox yet (seed 30), nothing to do\n"; exit(0); }

function ticket_by_subject($like) { return (int) one("SELECT ticket_id FROM tickets WHERE ticket_subject LIKE '" . e($like) . "' ORDER BY ticket_id LIMIT 1"); }
function request_by_subject($like) { return (int) one("SELECT mail_request_id FROM mail_requests WHERE mail_request_subject LIKE '" . e($like) . "' ORDER BY mail_request_id LIMIT 1"); }

// from email, from name, subject, outcome, detail, ticket subject LIKE (or ''), request subject LIKE (or ''), minutes ago
$rows = [
    ['hannah.brooks@northshoredental.example', 'Hannah Brooks', 'Front desk printer offline again', 'ticket_created', 'New ticket from a known contact', 'Front desk printer offline again%', '', 55],
    ['theo.lindqvist@birchwoodlaw.example', 'Theo Lindqvist', 'Re: [TCK-48] Backup job failed overnight', 'reply_added', 'Threaded by the ticket number in the subject', 'Backup job failed overnight%', '', 120],
    ['jenna@evergreenhome.example', 'Jenna Kowalczyk', 'Do you support home-health clinics?', 'mail_request', 'Unknown sender: held for review', '', 'Do you support home-health clinics?%', 190],
    ['robert.kowalski@granitepeakmfg.example', 'Robert Kowalski', 'Automatic reply: Out of office until Monday', 'suppressed', 'Auto-Submitted: auto-replied', '', '', 260],
    ['mailer-daemon@mailhost.example', 'Mail Delivery System', 'Undeliverable: Your ticket update', 'ndr', 'Delivery status notification (bounce)', '', '', 330],
    ['frank.delaney@ironwoodlogistics.example', 'Frank Delaney', 'Dispatch office Wi-Fi keeps dropping', 'duplicate', 'Message-ID already imported', '', '', 400],
    ['alerts@scan-relay.example', 'Scan relay', 'Scan from device 4', 'rate_limited', 'Over 20 messages from this sender in the last hour', '', '', 480],
    ['newsletter@vendor-news.example', 'Vendor News', 'Your weekly product digest', 'ignored', 'No matching contact or domain; mailbox does not queue unknown senders', '', '', 560],
];
foreach ($rows as [$from, $name, $subject, $outcome, $detail, $tsub, $rsub, $mins]) {
    if (one("SELECT mail_log_id FROM mail_log WHERE mail_log_from_email = '" . e($from) . "' AND mail_log_subject = '" . e($subject) . "' LIMIT 1")) { continue; }
    $tid = $tsub ? ticket_by_subject($tsub) : 0;
    $rid = $rsub ? request_by_subject($rsub) : 0;
    q("INSERT INTO mail_log SET mail_log_mailbox_id = $mb, mail_log_from_email = '" . e($from) . "', mail_log_from_name = '" . e($name) . "',
        mail_log_subject = '" . e($subject) . "', mail_log_outcome = '" . e($outcome) . "', mail_log_detail = '" . e($detail) . "',
        mail_log_ticket_id = " . ($tid ?: 'NULL') . ", mail_log_mail_request_id = " . ($rid ?: 'NULL') . ",
        mail_log_created_at = NOW() - INTERVAL $mins MINUTE");
}

// One message the poller set aside after repeated failures (Mailboxes > Quarantined mail).
$key = sha1('lakeshore-demo-quarantine-1');
if (!one("SELECT intake_id FROM mail_intake_state WHERE intake_key = '$key'")) {
    q("INSERT INTO mail_intake_state SET intake_mailbox_id = $mb, intake_key = '$key', intake_message_id = '<demo-corrupt-1@mailhost.example>',
        intake_from_email = 'scans@copier-relay.example', intake_subject = 'Scan to e-mail from the copier', intake_attempts = 3,
        intake_last_error = 'Could not parse the message body (invalid MIME boundary)', intake_state = 'quarantined',
        intake_first_seen_at = NOW() - INTERVAL 1 DAY, intake_last_attempt_at = NOW() - INTERVAL 20 HOUR, intake_quarantined_at = NOW() - INTERVAL 20 HOUR");
}
echo "96-mail-intake: done\n";
