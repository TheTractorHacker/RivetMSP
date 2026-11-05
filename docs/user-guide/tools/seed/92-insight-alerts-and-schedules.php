<?php
/*
 * Vendor-RMM alert history and scheduled reports for the RivetMSP user-guide demo (group G9, Insight).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/92-insight-alerts-and-schedules.php
 *
 * 1. rmm_alerts: the RMM Health report reads this table, which a vendor RMM sync (Tactical RMM, Level) fills. The sandbox has
 *    no vendor RMM, so this writes the rows such a sync would have created: one alert for each maintenance ticket of seed 90
 *    (the alert opened the ticket, the technician acknowledged and resolved it) plus alerts that cleared by themselves, and
 *    three recent ones still waiting. Example data, not a live connection. Only added while the table has no alert older than two weeks,
 *    so it can sit next to the current alerts the endpoints group (65-69) creates and never doubles up.
 * 2. report_schedules: four scheduled reports, addressed to staff only (the app rejects other addresses), one paused, with
 *    "last sent" times. Nothing is emailed: the sandbox has no mail server and the scheduler is not run.
 * Idempotent.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "92-insight-alerts-and-schedules: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "92-insight-alerts-and-schedules: " . mysqli_error($mysqli) . "\n  " . substr($sql, 0, 300) . "\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }
$tzName = (string) one('SELECT config_timezone FROM settings WHERE company_id = 1') ?: 'America/Chicago';
date_default_timezone_set($tzName);
q("SET time_zone = '" . (new DateTime('now', new DateTimeZone($tzName)))->format('P') . "'");
$NOW = time();

// ---- 1. alerts ---------------------------------------------------------------------------------
$alerts = 0;
$existingAlerts = intval(one('SELECT COUNT(*) FROM rmm_alerts'));
if (intval(one('SELECT COUNT(*) FROM rmm_alerts WHERE created_at < NOW() - INTERVAL 14 DAY')) === 0) {
    $integration = intval(one('SELECT COALESCE((SELECT integration_id FROM rmm_alerts ORDER BY id LIMIT 1), (SELECT id FROM rmm_integrations ORDER BY id LIMIT 1))'));
    if (!$integration) {
        q("INSERT INTO rmm_integrations SET name = 'RivetIT Endpoint Agent', type = 'rivetit_agent', api_url = '', web_url = '', api_key_enc = '', enabled = 1");
        $integration = intval(mysqli_insert_id($mysqli));
    }
    $tech = [intval(one("SELECT user_id FROM users WHERE user_email = 'devon.park@lakeshoremsp.example'")), intval(one("SELECT user_id FROM users WHERE user_email = 'sam.ortiz@lakeshoremsp.example'")),
        intval(one("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'"))];
    mt_srand(9201);
    $MSG = [
        'critical' => ['Backup job failed: no successful backup in 24 hours', 'Disk C: has less than 5% free space', 'Server did not check in for 30 minutes', 'Ransomware canary file was modified'],
        'warning' => ['CPU above 90% for 15 minutes', 'Windows Update pending a restart for 7 days', 'Disk C: has less than 15% free space', 'Memory use above 90% for 20 minutes', 'Antivirus definitions are 5 days old', 'Printer spooler service stopped'],
        'info' => ['Device rebooted unexpectedly', 'New software installed outside the catalog', 'Service restarted automatically', 'Certificate expires in 21 days'],
    ];
    $assetsByClient = [];
    $r = mysqli_query($mysqli, 'SELECT asset_id, asset_client_id FROM assets WHERE asset_archived_at IS NULL ORDER BY asset_id');
    while ($a = mysqli_fetch_assoc($r)) { $assetsByClient[intval($a['asset_client_id'])][] = intval($a['asset_id']); }
    $clients = array_keys($assetsByClient);
    $mk = function ($client, $sev, $created, $ackAfter, $resolveAfter, $ticketId) use ($integration, $assetsByClient, $MSG, $tech, &$alerts) {
        $assets = $assetsByClient[$client] ?? [0];
        $asset = $assets[mt_rand(0, count($assets) - 1)];
        $msg = $MSG[$sev][mt_rand(0, count($MSG[$sev]) - 1)];
        $status = $resolveAfter !== null ? 'resolved' : ($ackAfter !== null ? 'acknowledged' : 'new');
        $ack = $ackAfter !== null ? "'" . date('Y-m-d H:i:s', $created + $ackAfter) . "'" : 'NULL';
        $ackBy = $ackAfter !== null ? $tech[mt_rand(0, 2)] : 'NULL';
        $res = $resolveAfter !== null ? "'" . date('Y-m-d H:i:s', $created + $resolveAfter) . "'" : 'NULL';
        q("INSERT INTO rmm_alerts SET asset_id = $asset, client_id = $client, integration_id = $integration, tactical_alert_id = 'ex-" . mt_rand(100000, 999999) . "', severity = '$sev', status = '$status',
            message = '" . e($msg) . "', acknowledged_by = $ackBy, acknowledged_at = $ack, resolved_at = $res, created_at = '" . date('Y-m-d H:i:s', $created) . "', ticket_id = " . ($ticketId ?: 'NULL'));
        $alerts++;
    };
    // alerts that opened a maintenance ticket
    $r = mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id, UNIX_TIMESTAMP(ticket_created_at) c, UNIX_TIMESTAMP(ticket_closed_at) x FROM tickets WHERE ticket_url_key LIKE 'hist90-%' AND ticket_rmm_sourced = 1 ORDER BY ticket_created_at");
    while ($t = mysqli_fetch_assoc($r)) {
        $sev = mt_rand(1, 100) <= 30 ? 'critical' : 'warning';
        $created = intval($t['c']) - 120;
        $mk(intval($t['ticket_client_id']), $sev, $created, mt_rand(300, 3000), max(900, intval($t['x']) - $created), intval($t['ticket_id']));
    }
    // alerts that cleared by themselves or were handled without a ticket (about 3 per fortnight)
    for ($age = 360; $age >= 4; $age -= mt_rand(2, 6)) {
        $day = strtotime(date('Y-m-d', $NOW - $age * 86400));
        if (intval(date('N', $day)) >= 6 && mt_rand(1, 100) <= 60) { continue; }
        $sev = mt_rand(1, 100) <= 60 ? 'warning' : 'info';
        $created = $day + mt_rand(6, 22) * 3600 + mt_rand(0, 59) * 60;
        $self = mt_rand(1, 100) <= 55;
        $mk($clients[mt_rand(0, count($clients) - 1)], $sev, $created, $self ? null : mt_rand(300, 7200), $self ? mt_rand(600, 5400) : mt_rand(7200, 86400), 0);
    }
    // three recent ones still waiting (only when the endpoints seeds have not added current alerts)
    if (!$existingAlerts) {
    $mk($clients[0], 'critical', $NOW - 3 * 3600, null, null, 0);
    $mk($clients[2 % count($clients)], 'warning', $NOW - 26 * 3600, null, null, 0);
    $mk($clients[4 % count($clients)], 'warning', $NOW - 50 * 3600, 1800, null, 0);
    }
}

// ---- 2. scheduled reports ---------------------------------------------------------------------------
$sched = 0;
$ROWS = [
    ['service_desk', 'weekly', 'morgan.reyes@lakeshoremsp.example', 2, 1],
    ['clients_with_balance', 'weekly', 'morgan.reyes@lakeshoremsp.example, jules.whitaker@lakeshoremsp.example', 3, 1],
    ['mrr', 'monthly', 'morgan.reyes@lakeshoremsp.example', 21, 1],
    ['csat', 'monthly', 'morgan.reyes@lakeshoremsp.example, devon.park@lakeshoremsp.example', 40, 0],
];
foreach ($ROWS as [$report, $freq, $to, $sentAgo, $active]) {
    if (intval(one("SELECT schedule_id FROM report_schedules WHERE schedule_report = '" . e($report) . "' AND schedule_frequency = '$freq'"))) { continue; }
    q("INSERT INTO report_schedules SET schedule_report = '" . e($report) . "', schedule_frequency = '$freq', schedule_recipients = '" . e($to) . "', schedule_active = $active,
        schedule_last_sent = '" . date('Y-m-d 06:10:12', strtotime("-$sentAgo day")) . "', schedule_created_at = '" . date('Y-m-d 09:30:00', strtotime('-70 day')) . "'");
    $sched++;
}
echo "92-insight-alerts-and-schedules: $alerts alerts, $sched report schedules.\n";
