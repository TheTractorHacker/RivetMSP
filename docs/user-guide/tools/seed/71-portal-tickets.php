<?php
/*
 * Seed 71 (group 12, portals): two extra tickets for Hannah Brooks (Northshore Dental) so the client portal and the guest ticket page
 * can show states the older tickets do not have.
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/71-portal-tickets.php
 *
 *   1. "Reception label printer prints blank labels"  status Resolved, answered by a technician. The portal shows
 *      "Your ticket has been resolved" with Reopen ticket / Close ticket, and so does the guest ticket page.
 *   2. "Add a second monitor at the front desk"       status Open, one technician reply, and a task "Approve the monitor purchase"
 *      with a pending CLIENT approval (any contact may approve). The portal shows the Approvals box; the guest approval page works.
 *
 * Depends on 00-core and 30-ticketing-setup (statuses, categories). Idempotent: skips a ticket whose client and subject already exist.
 * Example data only: nothing here is a live connection.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "71-portal-tickets: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "71-portal-tickets: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }

$tz = (string) one('SELECT config_timezone FROM settings WHERE company_id = 1') ?: 'America/Chicago';
q("SET time_zone = '" . (new DateTime('now', new DateTimeZone($tz)))->format('P') . "'");

$cid = (int) one("SELECT client_id FROM clients WHERE client_name = 'Northshore Dental Group' LIMIT 1");
$hannah = (int) one("SELECT contact_id FROM contacts WHERE contact_client_id = $cid AND contact_email = 'hannah.brooks@northshoredental.example' LIMIT 1");
$devon = (int) one("SELECT user_id FROM users WHERE user_email = 'devon.park@lakeshoremsp.example' LIMIT 1");
if (!$cid || !$hannah || !$devon) {
    fwrite(STDERR, "71-portal-tickets: run 00-core first.\n");
    exit(1);
}
$st_open = (int) one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = 'Open' LIMIT 1") ?: 2;
$st_resolved = (int) one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = 'Resolved' LIMIT 1") ?: 4;
$cat_hw = (int) one("SELECT category_id FROM categories WHERE category_name = 'Hardware Request' AND category_type = 'Ticket' LIMIT 1");
$cat_remote = (int) one("SELECT category_id FROM categories WHERE category_name = 'Remote' AND category_type = 'Ticket' LIMIT 1");

// subject, details, priority, status id, category, hours ago created, resolved?, replies [type, by (contact|tech), hours ago, text]
$tickets = [
  ['Reception label printer prints blank labels',
   'The label printer at reception prints blank labels since this morning. The roll is new and the light is green.',
   'Medium', $st_resolved, $cat_remote, 26, true,
   [['Public', 'tech', 24, 'Thanks Hannah. I connected remotely and found the print head set to the wrong label type after last night\'s driver update. I switched it back and printed a test label.'],
    ['Public', 'tech', 24, 'The test label printed fine. I am marking this resolved; reopen it from the portal if the labels go blank again.']]],
  ['Add a second monitor at the front desk',
   'Our front desk team would like a second monitor so they can keep the schedule open next to the patient record.',
   'Low', $st_open, $cat_hw, 5, false,
   [['Public', 'tech', 4, 'Happy to help. A 24 inch monitor with a stand is about $219 including tax. I added a task so you can approve the purchase from this ticket, or from the link in the e-mail we sent.']]],
];

foreach ($tickets as [$subject, $details, $priority, $status, $cat, $hours, $resolved, $replies]) {
    if (one("SELECT ticket_id FROM tickets WHERE ticket_client_id = $cid AND ticket_subject = '" . e($subject) . "'")) { continue; }

    q("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    $number = (int) one('SELECT LAST_INSERT_ID()');
    $prefix = e(one('SELECT config_ticket_prefix FROM settings WHERE company_id = 1'));
    $key = randomString(32);
    $created = "DATE_SUB(NOW(), INTERVAL $hours HOUR)";
    $firstResp = "DATE_SUB(NOW(), INTERVAL " . ($hours - 1) . " HOUR)";
    $resolvedSql = $resolved ? "DATE_SUB(NOW(), INTERVAL " . ($hours - 2) . " HOUR)" : 'NULL';

    q("INSERT INTO tickets SET ticket_prefix = '$prefix', ticket_number = $number, ticket_source = 'Portal', ticket_category = $cat,
        ticket_subject = '" . e($subject) . "', ticket_details = '" . e('<p>' . $details . '</p>') . "', ticket_priority = '$priority', ticket_status = $status,
        ticket_billable = 0, ticket_onsite = 0, ticket_url_key = '$key', ticket_created_at = $created, ticket_updated_at = $firstResp,
        ticket_first_response_at = $firstResp, ticket_resolved_at = $resolvedSql, ticket_created_by = 0, ticket_assigned_to = $devon,
        ticket_client_id = $cid, ticket_contact_id = $hannah, ticket_parent_id = 0");
    $id = (int) mysqli_insert_id($mysqli);

    q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<strong>Initial Issue</strong><br><p>' . $details . '</p>') . "', ticket_reply_type = 'Client', ticket_reply_by = $hannah,
        ticket_reply_ticket_id = $id, ticket_reply_created_at = $created");
    q("UPDATE tickets SET ticket_initial_issue_reply_id = " . (int) mysqli_insert_id($mysqli) . " WHERE ticket_id = $id");
    q("INSERT INTO ticket_history SET ticket_history_status = 'New', ticket_history_description = 'Ticket created', ticket_history_ticket_id = $id, ticket_history_created_at = $created");

    foreach ($replies as [$type, $who, $ago, $text]) {
        $by = $who === 'tech' ? $devon : $hannah;
        $rtype = $who === 'tech' ? $type : 'Client';
        q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<p>' . $text . '</p>') . "', ticket_reply_type = '$rtype', ticket_reply_by = $by,
            ticket_reply_ticket_id = $id, ticket_reply_created_at = DATE_SUB(NOW(), INTERVAL $ago HOUR), ticket_reply_time_worked = '00:10:00'");
    }
    if ($resolved) {
        q("INSERT INTO ticket_replies SET ticket_reply = 'Ticket resolved.', ticket_reply_type = 'System', ticket_reply_by = $devon, ticket_reply_ticket_id = $id,
            ticket_reply_created_at = $resolvedSql");
        q("INSERT INTO ticket_history SET ticket_history_status = 'Resolved', ticket_history_description = 'Ticket resolved', ticket_history_ticket_id = $id, ticket_history_created_at = $resolvedSql");
    } else {
        // a task that needs the client's approval, still pending
        q("INSERT INTO tasks SET task_name = 'Approve the monitor purchase', task_order = 1, task_ticket_id = $id, task_assigned_to = $devon");
        $task = (int) mysqli_insert_id($mysqli);
        q("INSERT INTO task_approvals SET approval_scope = 'client', approval_type = 'any', approval_status = 'pending', approval_created_by = $devon,
            approval_url_key = '" . randomString(32) . "', approval_task_id = $task");
        q("INSERT INTO tasks SET task_name = 'Order and deliver the monitor', task_order = 2, task_ticket_id = $id, task_assigned_to = $devon");
    }
    echo "71-portal-tickets: $prefix$number $subject\n";
}
q("UPDATE settings SET config_ticket_next_number = GREATEST(config_ticket_next_number, (SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets)) WHERE company_id = 1");
