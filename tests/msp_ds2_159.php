<?php
// MSP-DS-159: inbound mail is escaped exactly once. Runs the REAL processInboundMessage (extracted from the cron script), addTicket,
// addReply, createMailRequestFromInbound, convertMailRequestToTicket and logMailEvent against a scratch DB with apostrophes,
// backslashes and quotes in every field.
// Run: SCRATCH_TABLES="companies clients domains contacts settings mailboxes tickets ticket_replies ticket_attachments ticket_watchers mail_log mail_requests mail_request_attachments email_queue logs notifications ticket_statuses categories users user_roles user_client_permissions user_settings ticket_history ticket_automation_rules ticket_automation_runs ticket_sla_events sla_policies sla_business_hours sla_business_hours_periods sla_holidays ticket_techs mail_templates mail_intake_settings mail_intake_state mail_alerts audit_events audit_chain_head platform_settings webhooks webhook_queue webhook_deliveries custom_values ticket_charges ticket_assets app_logs api_tokens ticket_metrics_daily user_role_permissions" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_159.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$root = dirname(__DIR__);
chdir($root);
$config_app_name = 'RivetMSP'; $config_base_url = 'x.test'; $config_ticket_prefix = "T'K\\T"; $config_ticket_from_email = 'help@x.test'; $config_ticket_from_name = "Help O'Desk";
$config_ticket_client_general_notifications = 0; $config_ticket_new_ticket_notification_email = 'staff@x.test'; $config_ticket_default_billable = 0;
$session_user_id = 0; $session_ip = '127.0.0.1'; $session_user_agent = 'test'; $session_name = 'Tester';
require_once $root . '/vendor/autoload.php';
require_once $root . '/functions.php';
require_once $root . '/includes/inbound_routing.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$one = fn (string $s) => ($r = $mysqli->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$src = file_get_contents($root . '/cron/ticket_email_parser.php');
$code = [];
foreach (['processInboundMessage', 'intakeHandleNormalized'] as $fn) { preg_match('/\nfunction ' . $fn . '\(.*?\n\}\n/s', $src, $m); $code[$fn] = $m[0] ?? ''; }
$ok('extracted both parser functions', $code['processInboundMessage'] !== '' && $code['intakeHandleNormalized'] !== '');
foreach (['MessageId', 'InboundPreparer', 'InboundRouter', 'IntakeStore', 'QuotedTextStripper', 'MailSettings', 'MailHealth'] as $c) { class_alias('RivetMSP\\Mail\\' . $c, $c); }
function mailIntakeLimits(): array { return ['max_file_bytes' => 10485760, 'max_message_bytes' => 52428800, 'inline_max_bytes' => 1048576, 'scan' => null]; }
eval($code['processInboundMessage']);
eval($code['intakeHandleNormalized']);
$ok('intakeHandleNormalized builds its values with the raw sanitiser only', str_contains($code['intakeHandleNormalized'], "sanitizeInputRaw(\$norm['subject'])") && !str_contains($code['intakeHandleNormalized'], 'sanitizeInput('));
$ok('parser header values use the raw sanitiser', str_contains($src, '$config_ticket_prefix = sanitizeInputRaw(') && str_contains($src, '$config_ticket_from_name = sanitizeInputRaw('));
$config_ticket_prefix_re = preg_quote($config_ticket_prefix, '/');

$mysqli->query("INSERT INTO companies (company_id, company_name, company_phone) VALUES (1, 'Acme', '5551234567')");
$mysqli->query("INSERT INTO clients (client_id, client_name) VALUES (5, 'O\\'Neil \\\\ Co')");
$mysqli->query("INSERT INTO domains (domain_name, domain_client_id) VALUES ('owned.test', 5)");
$mysqli->query("INSERT INTO contacts (contact_id, contact_name, contact_email, contact_client_id) VALUES (9, 'Pat O\\'Brien', 'pat.o\\'brien@known.test', 5)");
$mysqli->query("INSERT INTO settings (company_id, config_ticket_next_number) VALUES (1, 100) ON DUPLICATE KEY UPDATE config_ticket_next_number = 100");
$mysqli->query("INSERT INTO mailboxes (mailbox_id, mailbox_email, mailbox_from_name) VALUES (1, 'help@x.test', 'Help')");

$subject = "Can't print: \"queue\" C:\\temp\\x";
$body = "<p>It's broken, path C:\\temp and 'quotes'</p>";
$addr = "pat.o'brien@known.test";
// 1. known contact: an address with an apostrophe must MATCH, the stored subject must be the original text
$r = processInboundMessage(1, 0, 1, $addr, "Pat O'Brien", $subject, [], '2026-11-16 10:00:00', $body, $body, [], [], '', []);
$tid = (int) $one("SELECT MAX(ticket_id) FROM tickets");
$ok('known contact with an apostrophe in the address was matched, ticket created', $r && $tid > 0 && (int) $one("SELECT ticket_contact_id FROM tickets WHERE ticket_id=$tid") === 9);
$ok('ticket subject stored exactly as sent', $one("SELECT ticket_subject FROM tickets WHERE ticket_id=$tid") === $subject);
$det = (string) $one("SELECT ticket_details FROM tickets WHERE ticket_id=$tid");
$ok('ticket body shows the contact name without backslashes', str_contains($det, "Pat O&#039;Brien") || str_contains($det, "Pat O'Brien"));
$ok('ticket body has no backslash-quote artefacts', !str_contains($det, "\\'") && !str_contains($det, 'O\\'));
$ok('ticket body keeps the sender\'s own backslash path', str_contains($det, 'C:\\temp'));
$ok('prefix with quote and backslash stored once', $one("SELECT ticket_prefix FROM tickets WHERE ticket_id=$tid") === $config_ticket_prefix);
$ok('mail log stores subject and sender exactly', $one("SELECT mail_log_subject FROM mail_log ORDER BY mail_log_id DESC LIMIT 1") === $subject && $one("SELECT mail_log_from_email FROM mail_log ORDER BY mail_log_id DESC LIMIT 1") === $addr);
$mails = ($q = $mysqli->query("SELECT email_subject, email_content FROM email_queue ORDER BY email_id")) ? $q->fetch_all(MYSQLI_ASSOC) : [];
$ok('a staff notification was queued', count($mails) >= 1);
foreach ($mails as $i => $mm) { $ok("notification #$i has no backslash artefacts", !str_contains($mm['email_subject'], "\\'") && !str_contains($mm['email_content'], "\\'") && !str_contains($mm['email_content'], 'O\\')); }
$ok('notification subject carries the original subject', str_contains($mails[0]['email_subject'] ?? '', $subject));

// 2. reply (subject tag) from the same address
$tn = (int) $one("SELECT ticket_number FROM tickets WHERE ticket_id=$tid");
$subj2 = "Re: [" . $config_ticket_prefix . $tn . "] it's still broken";
$r = processInboundMessage(1, 0, 1, $addr, "Pat O'Brien", $subj2, [], '2026-11-16 11:00:00', "<p>Still can't</p>", "Still can't", [], [], '', []);
$rep = $one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id=$tid AND ticket_reply_type='Client' ORDER BY ticket_reply_id DESC LIMIT 1");
$ok('reply from an address with an apostrophe was added (not queued as a stranger)', $r && $rep !== null && (int) $one("SELECT COUNT(*) FROM mail_requests") === 0);
$ok('reply text has no backslash artefacts', $rep !== null && !str_contains($rep, "\\'") && str_contains($rep, "can't"));

// 3. known domain: new contact stored with the original name and address
processInboundMessage(1, 0, 1, "d'arcy@owned.test", "D'Arcy \\ Lee", "Hi 'there'", [], '2026-11-16 12:00:00', '<p>x</p>', 'x', [], [], '', []);
$ok('new contact stored with original name', $one("SELECT contact_name FROM contacts WHERE contact_email='d\\'arcy@owned.test'") === "D'Arcy \\ Lee");

// 4. unknown sender: mail request stored once-escaped
processInboundMessage(1, 0, 1, "q'x@stranger.test", "Q'X", "Stranger's mail", [], '2026-11-16 13:00:00', '<p>x</p>', 'x', [], [], '', []);
$ok('mail request stores subject, name and address exactly', $one("SELECT CONCAT_WS('|', mail_request_subject, mail_request_from_name, mail_request_from_email) FROM mail_requests ORDER BY mail_request_id DESC LIMIT 1") === "Stranger's mail|Q'X|q'x@stranger.test");
// 5. converting the request: exact text again
$mid = (int) $one("SELECT MAX(mail_request_id) FROM mail_requests");
$tid2 = convertMailRequestToTicket($mid, 0);
$ok('converted ticket subject exact', $tid2 && $one("SELECT ticket_subject FROM tickets WHERE ticket_id=" . (int) $tid2) === "Stranger's mail");

// 6. Hostile text through the REAL intakeHandleNormalized -> processInboundMessage -> addTicket: stored exactly, nothing injected.
$evilSubject = "x' OR 1=1 -- \\ '; DROP TABLE contacts; -- \"q\"";
$evilFrom = "a'b\\c@known.test";
$evilName = "N'; UPDATE contacts SET contact_name='pwn' WHERE 1=1; -- \\";
$mysqli->query("INSERT INTO contacts (contact_id, contact_name, contact_email, contact_client_id) VALUES (20, 'Evil Contact', '" . $mysqli->real_escape_string($evilFrom) . "', 5)");
$rowsBefore = [(int) $one("SELECT COUNT(*) FROM contacts"), (int) $one("SELECT COUNT(*) FROM tickets")];
$norm = ['from_email' => $evilFrom, 'from_name' => $evilName, 'subject' => $evilSubject, 'ccs' => ["c'c@known.test"], 'date' => '2026-11-16 14:00:00',
         'html' => "<p>body ' OR 1=1 -- \\</p>", 'text' => '', 'headers' => [], 'message_id' => 'evil-1@test', 'in_reply_to' => '', 'references' => '',
         'attachments' => [['name' => "inv'oice\\.pdf", 'content' => 'x', 'mime' => 'application/pdf', 'content_type' => 'application/pdf', 'disposition' => 'attachment', 'cid' => null]]];
$handled = intakeHandleNormalized(1, 0, 1, $norm, '');
$t = $mysqli->query("SELECT ticket_id, ticket_subject, ticket_contact_id FROM tickets ORDER BY ticket_id DESC LIMIT 1")->fetch_assoc();
$ok('hostile message handled and became a ticket for the matched contact', $handled && (int) ($t['ticket_contact_id'] ?? 0) === 20);
$ok('stored subject equals the input exactly', ($t['ticket_subject'] ?? null) === $evilSubject);
$ok('exactly one new ticket, no new contact, contacts table intact', (int) $one("SELECT COUNT(*) FROM tickets") === $rowsBefore[1] + 1 && (int) $one("SELECT COUNT(*) FROM contacts") === $rowsBefore[0]);
$ok('no contact was rewritten by the injected UPDATE', (int) $one("SELECT COUNT(*) FROM contacts WHERE contact_name = 'pwn'") === 0);
$ok('mail log subject and sender equal the input', $one("SELECT mail_log_subject FROM mail_log ORDER BY mail_log_id DESC LIMIT 1") === $evilSubject && $one("SELECT mail_log_from_email FROM mail_log ORDER BY mail_log_id DESC LIMIT 1") === $evilFrom);
$ok('attachment name stored exactly', $one("SELECT ticket_attachment_name FROM ticket_attachments WHERE ticket_attachment_ticket_id = " . (int) $t['ticket_id'] . " AND ticket_attachment_name LIKE 'inv%'") === "inv'oice\\.pdf");
$ok('cc watcher stored exactly', (int) $one("SELECT COUNT(*) FROM ticket_watchers WHERE watcher_email = 'c\\'c@known.test' AND watcher_ticket_id = " . (int) $t['ticket_id']) === 1);
$ok('queued mail subject carries the plain subject (bound once)', str_contains((string) $one("SELECT email_subject FROM email_queue ORDER BY email_id DESC LIMIT 1"), $evilSubject));
// the same hostile text from an unknown sender: the mail request stores it exactly
$norm2 = $norm; $norm2['from_email'] = "z'z\\@nobody.test"; $norm2['message_id'] = 'evil-2@test'; $norm2['attachments'] = [];
intakeHandleNormalized(1, 0, 1, $norm2, '');
$ok('unknown hostile sender queued with exact text', $one("SELECT CONCAT_WS('|', mail_request_from_email, mail_request_subject) FROM mail_requests ORDER BY mail_request_id DESC LIMIT 1") === "z'z\\@nobody.test|" . $evilSubject);
exit($fail ? 1 : 0);
