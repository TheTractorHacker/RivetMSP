<?php
/*
 * CRM activity timeline and follow-up reminders for the RivetMSP user-guide demo (group 8).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/63-crm-activities.php
 *
 * Depends on 00-core.sql and 60-crm-leads.sql. Idempotent: an activity is skipped when one with the same subject already
 * exists for the same contact. Finishes by running cron/crm_reminders.php's logic (via require), which is what creates the
 * "CRM Follow-up" notification for reminders whose time has already passed. Reminders in the future stay pending.
 */
if (php_sapi_name() !== 'cli') { exit("Run this from the command line.\n"); }
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) { fwrite(STDERR, "63-crm: no config.php in '$app'. Set RIVETIT_APP_DIR.\n"); exit(1); }
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "63-crm: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
// The app shows times in the company time zone; make NOW() and friends in this seed agree with it.
mysqli_query($mysqli, "SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/Chicago')))->format('P') . "'");

function esc($s) { global $mysqli; return mysqli_real_escape_string($mysqli, $s); }

// [contact, type, subject, details, owner e-mail, days ago, due (days from now or null), reminder (days from now or null), completed]
$rows = [
    ['Dr. Naomi Estrada', 'email', 'Sent the backup overview', 'Sent the one-page overview of managed backup and asked for a good time to talk.', 'devon.park@lakeshoremsp.example', 3, null, null, 0],
    ['Josie Marchetti', 'call', 'Discovery call', "Taproom point-of-sale drops off Wi-Fi at busy times. Agreed to look at the access points on site.\nNext step: book a site visit.", 'devon.park@lakeshoremsp.example', 12, 5, 2, 0],
    ['Dr. Aaron Whitfield', 'meeting', 'HIPAA review kick-off', 'Walked through the practice with the partners. They want the risk review report before the end of the month.', 'morgan.reyes@lakeshoremsp.example', 18, null, null, 0],
    ['Priscilla Okafor', 'call', 'Check on server warranty', 'File server warranty ends in January. Asked for the serial number and a list of the CAD seats.', 'morgan.reyes@lakeshoremsp.example', 9, -2, -3, 0],
    ['Renata Solis', 'meeting', 'On-site walkthrough', 'Walked the office with the closing managers. Noted two printers and a shared scanner that need to join the plan.', 'morgan.reyes@lakeshoremsp.example', 27, null, null, 0],
    ['Renata Solis', 'email', 'Proposal sent', 'Sent the managed services proposal for review. Follow up on Friday.', 'morgan.reyes@lakeshoremsp.example', 11, -6, -7, 1],
    ['Vivian Tran', 'note', 'Contract terms', 'Wants a 12-month term with a 60-day opt-out. Checked with Morgan and this is acceptable.', 'devon.park@lakeshoremsp.example', 4, null, null, 0],
    ['Vivian Tran', 'call', 'Confirm signature date', 'Call to agree on a signing date for the managed services agreement.', 'devon.park@lakeshoremsp.example', 1, 3, 1, 0],
    ['Hollis Brandt', 'note', 'Not moving forward', 'Decided to keep a family member as IT support for now. Check back in the spring.', 'sam.ortiz@lakeshoremsp.example', 40, null, null, 0],
];
$made = 0;
foreach ($rows as [$contact, $type, $subject, $body, $owner, $ago, $due, $rem, $done]) {
    $cid = one("SELECT contact_id FROM contacts WHERE contact_name = '" . esc($contact) . "' LIMIT 1");
    $uid = one("SELECT user_id FROM users WHERE user_email = '" . esc($owner) . "'");
    if (!$cid || !$uid) { fwrite(STDERR, "63-crm: skipped '$subject' (missing contact or user)\n"); continue; }
    if (one("SELECT activity_id FROM crm_activities WHERE activity_related_type = 'contact' AND activity_related_id = $cid AND activity_subject = '" . esc($subject) . "'")) { continue; }
    $dueSql = $due === null ? 'NULL' : "DATE_ADD(NOW(), INTERVAL $due DAY)";
    $remSql = $rem === null ? 'NULL' : "DATE_ADD(NOW(), INTERVAL $rem DAY)";
    // Completed follow-ups are stamped as already reminded so the reminder worker leaves them alone.
    $remindedSql = ($rem !== null && $done) ? "DATE_ADD(NOW(), INTERVAL $rem DAY)" : 'NULL';
    q("INSERT INTO crm_activities SET activity_type = '$type', activity_subject = '" . esc($subject) . "', activity_body = '" . esc($body) . "',
        activity_related_type = 'contact', activity_related_id = $cid, activity_at = DATE_SUB(NOW(), INTERVAL $ago DAY),
        activity_due_at = $dueSql, activity_reminder_at = $remSql, activity_completed = $done, activity_owner = $uid,
        activity_created_at = DATE_SUB(NOW(), INTERVAL $ago DAY), activity_reminded_at = $remindedSql");
    $made++;
}
echo "63-crm: $made activity row(s) added\n";

// Create the notifications for reminders that are already due (what the hourly cron job does).
require dirname($app . '/cron/crm_reminders.php') . '/crm_reminders.php';
echo "63-crm: reminders processed\n";
