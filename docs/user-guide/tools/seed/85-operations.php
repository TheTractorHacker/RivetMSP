<?php
/*
 * Operations data for the RivetMSP user-guide demo (group G14: system settings, maintenance, ticketing setup).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/85-operations.php
 *
 * Idempotent: every row is looked up by name or date first, and settings are only set to fixed values.
 *   1. Two extra ticket statuses beyond the built-in five: "Waiting on Customer" (active, pauses the SLA clock) and
 *      "Waiting on Parts" (inactive, pauses the SLA clock), so the Ticket statuses page shows every column.
 *   2. Holiday catalog: the built-in United States rule set for 2026 and 2027, one custom holiday, and two of the 2026
 *      holidays added to the default business-hours calendar (Thanksgiving Day and Christmas Day).
 *   3. Three canned responses.
 *   4. Notification addresses (all .example) on Settings > Notifications.
 *   5. Backups: "Allow unencrypted in-app backups" on (a backup passphrase is already saved by seed 01) and the daily
 *      schedule ticked. No backup file is created here; the capture script presses "Save to Server" once.
 * Depends only on seeds 00 and 01 (and the stock rows the installer creates).
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "85-operations: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/holiday_functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "85-operations: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function esc($s) { global $mysqli; return mysqli_real_escape_string($mysqli, $s); }

// ---- 1. ticket statuses -----------------------------------------------------------------------------
$statuses = [
    ['Waiting on Customer', '#fd7e14', 1, 1, 6],
    ['Waiting on Parts', '#6f42c1', 1, 0, 7],
];
foreach ($statuses as [$name, $color, $pauses, $active, $order]) {
    if (!one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = '" . esc($name) . "'")) {
        q("INSERT INTO ticket_statuses SET ticket_status_name = '" . esc($name) . "', ticket_status_color = '$color', ticket_status_pauses_sla = $pauses, ticket_status_active = $active, ticket_status_order = $order");
    }
}

// ---- 2. holidays ------------------------------------------------------------------------------------
$country = 'United States';
foreach ([2026, 2027] as $year) {
    foreach (getFederalHolidaysForCountry($country, $year) as $h) {
        if (!one("SELECT holiday_id FROM holidays WHERE holiday_country = '" . esc($country) . "' AND holiday_date = '" . esc($h['date']) . "'")) {
            q("INSERT INTO holidays SET holiday_country = '" . esc($country) . "', holiday_year = $year, holiday_date = '" . esc($h['date']) . "', holiday_name = '" . esc($h['name']) . "', holiday_is_custom = 0, holiday_created_by = 0");
        }
    }
}
if (!one("SELECT holiday_id FROM holidays WHERE holiday_date = '2026-12-24' AND holiday_is_custom = 1")) {
    q("INSERT INTO holidays SET holiday_country = '" . esc($country) . "', holiday_year = 2026, holiday_date = '2026-12-24', holiday_name = 'Lakeshore team holiday break', holiday_is_custom = 1, holiday_created_by = 1");
}
$cal = one("SELECT calendar_id FROM sla_business_hours WHERE calendar_is_default = 1 ORDER BY calendar_id LIMIT 1");
if ($cal) {
    foreach (['2026-11-26', '2026-12-25'] as $date) {
        if (!one("SELECT holiday_id FROM sla_holidays WHERE calendar_id = " . intval($cal) . " AND holiday_date = '$date'")) {
            $name = one("SELECT holiday_name FROM holidays WHERE holiday_country = '" . esc($country) . "' AND holiday_date = '$date' LIMIT 1");
            if ($name) {
                q("INSERT INTO sla_holidays SET calendar_id = " . intval($cal) . ", holiday_date = '$date', holiday_name = '" . esc($name) . "'");
            }
        }
    }
}

// ---- 3. canned responses ----------------------------------------------------------------------------
$canned = [
    ['Ticket received', '<p>Hello,</p><p>Thanks for contacting Lakeshore Managed IT. We have your request and a technician will follow up shortly. If anything changes in the meantime, reply to this message.</p>'],
    ['Need more information', '<p>Hello,</p><p>To keep working on this we need a little more detail: what you were doing when the problem started, the exact message on screen (a photo is fine), and whether anyone else is affected.</p>'],
    ['Resolved: please confirm', '<p>Hello,</p><p>We believe this is fixed. Please try it again and let us know. If it is working, no reply is needed and the ticket will close on its own.</p>'],
];
foreach ($canned as [$name, $msg]) {
    if (!one("SELECT canned_response_id FROM canned_responses WHERE canned_response_name = '" . esc($name) . "' AND canned_response_archived_at IS NULL")) {
        q("INSERT INTO canned_responses SET canned_response_name = '" . esc($name) . "', canned_response_message = '" . esc($msg) . "'");
    }
}

// ---- 4. notification addresses and 5. backup switches ----------------------------------------------
q("UPDATE settings SET
      config_ticket_new_ticket_notification_email = 'dispatch@lakeshoremsp.example',
      config_invoice_paid_notification_email = 'billing@lakeshoremsp.example',
      config_quote_notification_email = 'sales@lakeshoremsp.example',
      config_backup_allow_unencrypted_override = 1,
      config_backup_auto_enabled = 1, config_backup_frequency = 'daily', config_backup_retain_count = 7
   WHERE company_id = 1");

echo "85-operations: done\n";
