<?php
/*
 * Recurring tickets and e-mailed requests for the RivetMSP user-guide demo (group G2).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/32-recurring-and-requests.php
 *
 *   1. Eight recurring ticket templates (weekly to annual) across the clients, with next-run dates spread over the
 *      coming months; two tickets they already raised (source Recurring, linked to the template).
 *   2. Five unmatched e-mails waiting on Service Desk > Requests (mail_requests). These rows are what the mail
 *      intake would write; no mailbox is polled in the sandbox.
 *
 * Needs seeds 00, 01 and 30 (categories, example support mailbox). Idempotent: templates are matched on
 * client + subject, tickets on client + subject, requests on sender + subject.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "32-recurring: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/sla_functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "32-recurring: " . mysqli_error($mysqli) . "\n  " . substr($sql, 0, 300) . "\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, $s); }

$tzName = one('SELECT config_timezone FROM settings WHERE company_id = 1');
if ($tzName) { date_default_timezone_set($tzName); }
$NOW = time();

$tech = [
    'D' => intval(one("SELECT user_id FROM users WHERE user_email = 'devon.park@lakeshoremsp.example'")),
    'S' => intval(one("SELECT user_id FROM users WHERE user_email = 'sam.ortiz@lakeshoremsp.example'")),
    'M' => intval(one("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'")),
];
$CL = [
    'NSD' => 'Northshore Dental Group', 'BWL' => 'Birchwood Law Partners', 'GPM' => 'Granite Peak Manufacturing',
    'LFCU' => 'Lakeview Family Credit Union', 'CPA' => 'Cedar & Pine Architects', 'MSVC' => 'Maple Street Veterinary Clinic',
    'IWL' => 'Ironwood Logistics', 'BEL' => 'Bluebird Early Learning', 'KPM' => 'Kestrel Property Management',
];
$clientId = function ($n) { return intval(one("SELECT client_id FROM clients WHERE client_name = '" . e($n) . "'")); };
$contactId = function ($cid, $n) { return intval(one("SELECT contact_id FROM contacts WHERE contact_client_id = $cid AND contact_name = '" . e($n) . "'")); };
$catId = function ($n) { return intval(one("SELECT category_id FROM categories WHERE category_type = 'Ticket' AND category_name = '" . e($n) . "' AND category_archived_at IS NULL ORDER BY category_parent DESC LIMIT 1")); };
if (!$catId('Maintenance')) { fwrite(STDERR, "32-recurring: run seed 30 first.\n"); exit(1); }

// ---- 1. recurring ticket templates --------------------------------------------------------------
// [client, contact, subject, details, priority, category, frequency, billable, assignee, start (days ago), next run (days from now)]
$R = [
    ['NSD', 'Hannah Brooks', 'Monthly patch review', 'Review last month\'s Windows and application updates, confirm every workstation is current and note anything that failed.', 'Medium', 'Maintenance', 'Monthly', 0, 'D', 240, 9],
    ['LFCU', 'Gordon Pike', 'Quarterly backup restore test', 'Restore three random files and one server volume from backup and record the result for the compliance file.', 'Medium', 'Maintenance', 'Quarterly', 0, 'M', 365, 24],
    ['MSVC', 'Beth Nakamura', 'Weekly server health check', 'Check disk space, backup status and event log warnings on the practice server.', 'Low', 'Maintenance', 'Weekly', 0, 'D', 120, 2],
    ['GPM', 'Marcus Webb', 'Monthly UPS and server closet inspection', 'Visit the plant, check UPS battery status, temperature and cabling in the server closet.', 'Medium', 'On-Site', 'Monthly', 1, 'S', 180, 17],
    ['BWL', 'Theo Lindqvist', 'Biweekly document server check', 'Verify the document management service, shadow copies and free space.', 'Low', 'Remote', 'Biweekly', 1, 'D', 90, 5],
    ['IWL', 'Tara Okonkwo', 'Quarterly handheld scanner inventory', 'Count handheld scanners, check batteries and re-image any that fail the sync test.', 'Medium', 'On-Site', 'Quarterly', 1, 'S', 300, 40],
    ['BEL', 'Sunita Patel', 'Annual device audit', 'Inventory every classroom and office device and update the asset list before the grant report.', 'Low', 'Remote', 'Annually', 0, 'D', 330, 35],
    ['KPM', 'Imani Carter', 'Twice-yearly Wi-Fi coverage survey', 'Walk the property with the survey tool and compare signal strength with last time.', 'Medium', 'On-Site', 'Biannually', 1, 'S', 160, 21],
];
$rid = [];
foreach ($R as $r) {
    [$c, $ct, $subj, $det, $pri, $cat, $freq, $bill, $as, $startAgo, $nextIn] = $r;
    $cid = $clientId($CL[$c]); $contact = $contactId($cid, $ct); $catid = $catId($cat);
    if (!$cid || !$contact) { fwrite(STDERR, "32-recurring: unknown client/contact $c / $ct\n"); exit(1); }
    $have = intval(one("SELECT recurring_ticket_id FROM recurring_tickets WHERE recurring_ticket_client_id = $cid AND recurring_ticket_subject = '" . e($subj) . "'"));
    if (!$have) {
        q("INSERT INTO recurring_tickets SET recurring_ticket_category = '$catid', recurring_ticket_subject = '" . e($subj) . "', recurring_ticket_details = '" . e('<p>' . $det . '</p>') . "',
            recurring_ticket_priority = '" . e($pri) . "', recurring_ticket_frequency = '" . e($freq) . "', recurring_ticket_billable = $bill,
            recurring_ticket_start_date = '" . date('Y-m-d', $NOW - $startAgo * 86400) . "', recurring_ticket_next_run = '" . date('Y-m-d', $NOW + $nextIn * 86400) . "',
            recurring_ticket_created_at = '" . date('Y-m-d H:i:s', $NOW - $startAgo * 86400) . "', recurring_ticket_created_by = {$tech['M']}, recurring_ticket_assigned_to = {$tech[$as]},
            recurring_ticket_client_id = $cid, recurring_ticket_contact_id = $contact, recurring_ticket_asset_id = 0");
        $have = intval(mysqli_insert_id($mysqli));
    }
    $rid[$subj] = $have;
}

// ---- tickets those templates already raised -------------------------------------------------------
// [template subject, closed?, days ago, extra reply]
$G = [
    ['Weekly server health check', false, 5, 'Disk at 61%, backups green, no new warnings. Left open for the next run to compare.'],
    ['Monthly patch review', true, 26, 'All 14 workstations current. One failed Office update reapplied.'],
];
foreach ($G as [$subj, $closed, $ago, $note]) {
    $recId = $rid[$subj];
    $rec = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM recurring_tickets WHERE recurring_ticket_id = $recId"));
    $cid = intval($rec['recurring_ticket_client_id']);
    if (intval(one("SELECT ticket_id FROM tickets WHERE ticket_client_id = $cid AND ticket_subject = '" . e($subj) . "' AND ticket_recurring_ticket_id = $recId"))) { continue; }
    $createdT = $NOW - $ago * 86400 + 8 * 3600 - (int) date('G', $NOW - $ago * 86400) * 3600 + 3600;   // about 09:00 local on that day
    $createdT = strtotime(date('Y-m-d', $NOW - $ago * 86400) . ' 06:00:00');
    $assignee = intval($rec['recurring_ticket_assigned_to']);
    $cat = intval($rec['recurring_ticket_category']);
    $deliv = getTicketDeliveryMethodForCategory($mysqli, $cat);
    $delivSql = $deliv ? "'" . e($deliv) . "'" : 'NULL';
    $status = intval(one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = '" . ($closed ? 'Closed' : 'Open') . "'"));
    q("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    $number = intval(mysqli_insert_id($mysqli));
    $prefix = e(one('SELECT config_ticket_prefix FROM settings WHERE company_id = 1'));
    $closedAt = $closed ? "'" . date('Y-m-d H:i:s', $createdT + 3 * 3600) . "'" : 'NULL';
    $upd = date('Y-m-d H:i:s', $createdT + 2 * 3600);
    q("INSERT INTO tickets SET ticket_prefix = '$prefix', ticket_number = $number, ticket_source = 'Recurring', ticket_category = $cat, ticket_subject = '" . e($subj) . "',
        ticket_details = '" . e($rec['recurring_ticket_details']) . "', ticket_priority = '" . e($rec['recurring_ticket_priority']) . "', ticket_status = $status,
        ticket_billable = 0, ticket_url_key = '" . randomString(32) . "', ticket_created_at = '" . date('Y-m-d H:i:s', $createdT) . "', ticket_updated_at = '$upd',
        ticket_resolved_at = $closedAt, ticket_closed_at = $closedAt, ticket_closed_by = " . ($closed ? $assignee : 0) . ", ticket_created_by = " . intval($rec['recurring_ticket_created_by']) . ",
        ticket_assigned_to = $assignee, ticket_client_id = $cid, ticket_contact_id = " . intval($rec['recurring_ticket_contact_id']) . ", ticket_recurring_ticket_id = $recId,
        ticket_delivery_method = $delivSql, ticket_counts_sla = 0, ticket_first_response_at = '$upd'");
    $id = intval(mysqli_insert_id($mysqli));
    q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<strong>Initial Issue</strong><br>' . $rec['recurring_ticket_details']) . "', ticket_reply_type = 'Internal', ticket_reply_by = " . intval($rec['recurring_ticket_created_by']) . ",
        ticket_reply_ticket_id = $id, ticket_reply_created_at = '" . date('Y-m-d H:i:s', $createdT) . "'");
    q("UPDATE tickets SET ticket_initial_issue_reply_id = " . intval(mysqli_insert_id($mysqli)) . ", ticket_updated_at = '$upd' WHERE ticket_id = $id");
    q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<p>' . $note . '</p>') . "', ticket_reply_type = 'Internal', ticket_reply_by = $assignee, ticket_reply_ticket_id = $id,
        ticket_reply_created_at = '$upd', ticket_reply_time_worked = '00:40:00'");
    if ($closed) {
        q("INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $assignee,
            ticket_reply_ticket_id = $id, ticket_reply_created_at = $closedAt");
    }
}
q("UPDATE settings SET config_ticket_next_number = GREATEST(config_ticket_next_number, (SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets)) WHERE company_id = 1");

// ---- 2. unmatched e-mails (Service Desk > Requests) ---------------------------------------------
$mb = intval(one("SELECT mailbox_id FROM mailboxes WHERE mailbox_email = 'support@lakeshoremsp.example'"));
if (!$mb) { fwrite(STDERR, "32-recurring: run seed 30 first (example mailbox).\n"); exit(1); }
// [from name, from email, subject, body, hours ago, attachment]
$M = [
    ['Jenna Kowalczyk', 'jenna@evergreenhome.example', 'Do you support home-health clinics?', '<p>Hello, we run a small home-health agency with 12 staff and no IT support. Do you look after clinics like ours? We would like a quote for Microsoft 365 and a few laptops.</p><p>Jenna Kowalczyk<br>Evergreen Home Health</p>', 2, null],
    ['Tom Brandt', 'tom.brandt.home@mailhost.example', 'Plant 1 camera is down', '<p>This is Tom from the Plant 1 floor. The loading dock camera has been black since lunch. I am writing from my personal address because my work mail is slow. Can someone come look?</p>', 5, null],
    ['Print Supply Depot', 'billing@printsupply.example', 'Invoice 8841 is overdue', '<p>Our records show invoice 8841 for toner is unpaid. A copy is attached.</p>', 26, 'invoice-8841.pdf'],
    ['Aisha Rahman', 'aisha.rahman@mailhost.example', 'New starter next week: laptop and email', '<p>Hi, I am the new office manager starting Monday at a client you look after. Who do I speak to about getting my laptop and email account?</p>', 51, null],
    ['Conference Organizer', 'events@msp-expo.example', 'Exhibitor spaces now open', '<p>Registration for next spring\'s regional IT expo is open. Reply to book a booth.</p>', 94, null],
];
foreach ($M as [$fn, $fe, $subj, $body, $hrs, $att]) {
    if (intval(one("SELECT mail_request_id FROM mail_requests WHERE mail_request_from_email = '" . e($fe) . "' AND mail_request_subject = '" . e($subj) . "'"))) { continue; }
    $at = date('Y-m-d H:i:s', $NOW - $hrs * 3600);
    q("INSERT INTO mail_requests SET mail_request_mailbox_id = $mb, mail_request_from_email = '" . e($fe) . "', mail_request_from_name = '" . e($fn) . "', mail_request_subject = '" . e($subj) . "',
        mail_request_body = '" . e($body) . "', mail_request_received_at = '$at', mail_request_created_at = '$at', mail_request_message_id = '" . e('<' . md5($fe . $subj) . '@mailhost.example>') . "'");
    if ($att) {
        $mid = intval(mysqli_insert_id($mysqli));
        q("INSERT INTO mail_request_attachments SET mail_request_attachment_name = '" . e($att) . "', mail_request_attachment_reference_name = '" . e(md5($att)) . ".pdf', mail_request_attachment_mail_request_id = $mid");
    }
}
echo "32-recurring: " . count($R) . " recurring templates, 2 generated tickets, " . count($M) . " mail requests (idempotent).\n";
