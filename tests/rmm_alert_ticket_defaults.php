<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * RMM tickets are Maintenance with no SLA and no email by default; per-ticket "Count toward SLA" and "Send email notifications" switches
 * (26.10.41, DB 2.6.105), against the REAL code on a SCRATCH database:
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... RIVETMSP_REDIS_HOST=127.0.0.1 \
 *   RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/rmm_alert_ticket_defaults.php     (rules of tests/endpoint_agent_lib.php)
 * Covers: the 2.6.104 -> 2.6.105 updater step (and the code before it ran); all four alert-to-ticket paths (the real "Create ticket" handler,
 * the real cron, the real automation action, the real escalation ensureTicket) with the defaults and with both settings on; the per-ticket SLA
 * switch both ways with both start anchors; ticket_counts_sla vs the "pauses the SLA" statuses; the Service Desk report and the dashboard;
 * the email default per ticket source (RMM / agent / inbound email); THE regression walk: every ticket mail path for a ticket whose email is
 * off queues nothing while the same path for a ticket with email on does; the one-reply override; the queue retry; the backfill (dry run, real
 * run, inbound-email tickets untouched, SLA untouched); the settings card and handlers; permissions; hostile input.
 */
$sd = sys_get_temp_dir() . '/rmm_atd_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd"); putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_functions.php";
require_once "$root/includes/rmm_alert_routes.php";
require_once "$root/includes/sla_functions.php";
require_once "$root/includes/automation_functions.php";
$sdir = sys_get_temp_dir() . '/rmm_atd_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sd, $sdir) { foreach ([$sd, $sdir] as $d) { foreach (glob("$d/*") ?: [] as $f) { @unlink($f); } @rmdir($d); } });
$db->query("SET time_zone = '+00:00'");

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- updater 2.6.104 -> 2.6.105 (and the code before it has run) ---\n";
$cols6 = ['tickets' => ['ticket_rmm_sourced', 'ticket_counts_sla', 'ticket_send_email', 'ticket_sla_started_at'], 'settings' => ['config_rmm_alert_ticket_sla', 'config_rmm_alert_ticket_email']];
foreach ($cols6 as $t => $cs) { foreach ($cs as $c) { $q("ALTER TABLE `$t` DROP COLUMN IF EXISTS `$c`"); } }
$q("UPDATE settings SET config_current_database_version = '2.6.104'");
ea_reset(); ea_seed_users();
$q("DELETE FROM email_queue"); $q("DELETE FROM categories WHERE category_type = 'Ticket'"); $q("DELETE FROM rmm_alert_ticket_routes");
$q("INSERT INTO rmm_integrations (name, type, api_url, web_url, api_key_enc, enabled) VALUES ('Agent', 'rivetit_agent', '', '', '', 1)"); $intAgent = (int) $db->insert_id;
$q("INSERT INTO rmm_alerts SET client_id = 1, integration_id = $intAgent, tactical_alert_id = 'pre-1', severity = 'critical', status = 'new', message = 'Before the update'");
$pre = createTicketFromRmmAlert($db, mysqli_fetch_assoc($db->query('SELECT * FROM rmm_alerts WHERE id = ' . $db->insert_id)), 0, 'RMM Alert');
$ok($pre['ticket_id'] > 0 && !ticketFlagsReady($db), 'before DB 2.6.105: createTicketFromRmmAlert still works (no new columns written)');
recalculateTicketSla($db, $pre['ticket_id']);
addToMailQueue([['from' => 'a@msp.example', 'from_name' => 'A', 'recipient' => 'b@client.example', 'recipient_name' => 'B', 'subject' => 'x', 'body' => 'y', 'ticket_id' => $pre['ticket_id']]]);
$ok((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_ticket_id = {$pre['ticket_id']}") === 1, 'before DB 2.6.105: addToMailQueue queues as before (no gate without the column)');
shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1');
// The real updater does not stop at 2.6.105: once consolidated with other branches' steps (notification preferences, 2.6.105 -> 2.6.106) it keeps
// going to whatever is actually latest - this test only cares that the 2.6.104 -> 2.6.105 step itself ran and its columns are correct, not that
// 2.6.105 is the final version on this checkout.
$ok(version_compare((string) $one('SELECT config_current_database_version FROM settings LIMIT 1'), '2.6.105', '>='), 'the real updater reaches at least 2.6.105');
$tc = array_column($rows('SHOW COLUMNS FROM tickets'), null, 'Field'); $sc = array_column($rows('SHOW COLUMNS FROM settings'), null, 'Field');
$ok(($tc['ticket_rmm_sourced']['Default'] ?? '') === '0' && ($tc['ticket_counts_sla']['Default'] ?? '') === '1' && ($tc['ticket_send_email']['Default'] ?? '') === '1'
    && ($tc['ticket_sla_started_at']['Null'] ?? '') === 'YES' && ($sc['config_rmm_alert_ticket_sla']['Default'] ?? '') === '0' && ($sc['config_rmm_alert_ticket_email']['Default'] ?? '') === '0', 'six columns with the specified defaults');
$pr = mysqli_fetch_assoc($db->query("SELECT ticket_rmm_sourced, ticket_counts_sla, ticket_send_email, ticket_sla_started_at FROM tickets WHERE ticket_id = {$pre['ticket_id']}"));
$ok($pr['ticket_rmm_sourced'] === '0' && $pr['ticket_counts_sla'] === '1' && $pr['ticket_send_email'] === '1' && $pr['ticket_sla_started_at'] === null, 'an existing ticket is left exactly as it was (counts SLA, sends email, not labelled)');
$q("UPDATE settings SET config_current_database_version = '2.6.104'");
shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1');
$ok(version_compare((string) $one('SELECT config_current_database_version FROM settings LIMIT 1'), '2.6.105', '>='), 're-running the step is harmless');
$dbsql = (string) file_get_contents("$root/db.sql");
$ok(str_contains($dbsql, '`ticket_send_email` tinyint(1) NOT NULL DEFAULT 1') && str_contains($dbsql, '`config_rmm_alert_ticket_email` tinyint(1) NOT NULL DEFAULT 0')
    && version_compare(trim((string) shell_exec('php -r ' . escapeshellarg('require "' . $root . '/includes/database_version.php"; echo LATEST_DATABASE_VERSION;'))), '2.6.105', '>='), 'db.sql and LATEST_DATABASE_VERSION carry the change (at least 2.6.105)');
$ok(ticketFlagsReady($db), 'the code sees the new columns');

// Put back what this file changes outside the tables ea_reset() owns, so a suite run after it (tests/rmm_ui.php expects the scratch company zone
// America/Chicago, no default SLA policy...) sees the scratch database as it was.
$keepSettings = ['config_timezone', 'config_smtp_host', 'config_ticket_from_email', 'config_ticket_from_name', 'config_ticket_client_general_notifications', 'config_ticket_csat_enable',
    'config_ticket_new_ticket_notification_email', 'config_rmm_alert_ticket_category_id', 'config_rmm_alert_ticket_sla', 'config_rmm_alert_ticket_email', 'config_rmm_auto_ticket_severities',
    'config_enable_cron', 'config_ticket_autoclose_hours', 'config_ticket_csat_reminder_days', 'config_module_enable_rmm'];
$savedSettings = mysqli_fetch_assoc($db->query('SELECT ' . implode(', ', $keepSettings) . ' FROM settings WHERE company_id = 1'));
$savedTables = [];
foreach (['ticket_statuses', 'sla_policies', 'contacts', 'user_settings'] as $t) { $savedTables[$t] = $rows("SELECT * FROM `$t`"); }
register_shutdown_function(function () use ($db, $savedSettings, $savedTables) {
    $set = [];
    foreach ($savedSettings as $k => $v) { $set[] = "`$k` = " . ($v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'"); }
    $db->query('UPDATE settings SET ' . implode(', ', $set) . ' WHERE company_id = 1');
    foreach ($savedTables as $t => $rs) {
        $db->query("DELETE FROM `$t`");
        foreach ($rs as $r) {
            $db->query("INSERT INTO `$t` SET " . implode(', ', array_map(fn($k, $v) => "`$k` = " . ($v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'"), array_keys($r), $r)));
        }
    }
});

// ---------------------------------------------------------------------------------------------------------------------------------------------
// Fixtures: UTC everywhere, statuses (3 and 6 pause the SLA), contacts, a default SLA policy so a ticket that counts toward SLA really gets due dates.
ea_reset(); ea_seed_users();
foreach (['email_queue', 'ticket_history', 'ticket_watchers', 'contacts', 'sla_policies', 'ticket_sla_events', 'rmm_alert_ticket_routes', 'ticket_statuses', 'ticket_outtake_forms', 'tasks', 'task_approvals'] as $t) { $q("DELETE FROM `$t`"); }
$q("DELETE FROM categories WHERE category_type = 'Ticket'");
$q("INSERT IGNORE INTO companies SET company_id = 1, company_name = 'Scratch MSP'");
foreach ([[1, 'New', 0], [2, 'Open', 0], [3, 'On Hold', 1], [4, 'Resolved', 0], [5, 'Closed', 0], [6, 'Waiting on Customer', 1], [7, 'Waiting on Vendor', 0]] as [$id, $n, $f]) {
    $q("INSERT INTO ticket_statuses SET ticket_status_id=$id, ticket_status_name='$n', ticket_status_color='#000', ticket_status_active=1, ticket_status_order=$id, ticket_status_pauses_sla=$f");
}
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'Jane Roe', contact_email = 'jane@client.example', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 2, contact_name = 'Bob Two', contact_email = 'bob@clientb.example', contact_client_id = 2");
$q("INSERT INTO sla_policies SET policy_name = 'Std', policy_low_response = 60, policy_low_resolution = 480, policy_medium_response = 60, policy_medium_resolution = 480, policy_high_response = 60, policy_high_resolution = 480, policy_is_default = 1");
$q("UPDATE settings SET config_timezone = 'UTC', config_smtp_host = '127.0.0.1', config_ticket_from_email = 'support@msp.example', config_ticket_from_name = 'MSP Support',
    config_ticket_client_general_notifications = 1, config_ticket_csat_enable = 1, config_ticket_new_ticket_notification_email = '', config_rmm_alert_ticket_category_id = NULL,
    config_rmm_alert_ticket_sla = 0, config_rmm_alert_ticket_email = 0, config_rmm_auto_ticket_severities = '', config_enable_cron = 0 WHERE company_id = 1");
// extra roles: 7 support read-only (level 1), 8 support edit (level 2); users 20 (RO), 21 (edit, Client B only), 22 (edit), 30 a client-portal user
foreach ([[7, 'SupportRO', 1], [8, 'SupportEdit', 2]] as [$rid, $rn, $lvl]) { $q("INSERT INTO user_roles SET role_id=$rid, role_name='$rn', role_is_admin=0, role_type=1"); $q("INSERT INTO user_role_permissions SET user_role_id=$rid, module_id=5, user_role_permission_level=$lvl"); }
foreach ([[20, 'ro', 7, 1], [21, 'editb', 8, 1], [22, 'edit', 8, 1], [30, 'portal', 8, 2]] as [$uid, $n, $r, $type]) {
    $q("INSERT INTO users SET user_id=$uid, user_name='$n', user_email='$n@example.test', user_password='x', user_type=$type, user_status=1, user_role_id=$r"); $q("INSERT INTO user_settings SET user_id=$uid");
}
$q("INSERT INTO user_client_permissions SET user_id=21, client_id=2");
$GLOBALS['config_ticket_prefix'] = 'T'; $config_ticket_prefix = 'T';
$q("INSERT INTO rmm_integrations (name, type, api_url, web_url, api_key_enc, enabled) VALUES ('Agent', 'rivetit_agent', '', '', '', 1)"); $intAgent = (int) $db->insert_id;
$q("INSERT INTO categories SET category_name = 'Maintenance', category_type = 'Ticket'"); $maint = (int) $db->insert_id;
$q("UPDATE settings SET config_rmm_alert_ticket_category_id = $maint");
$n = 0;
$mkAlert = function (string $sev = 'critical', int $client = 1) use ($q, $db, &$n, $intAgent): array {
    $n++;
    $q("INSERT INTO rmm_alerts SET client_id = $client, integration_id = $intAgent, tactical_alert_id = 'agent:7:disk_c:$n', severity = '$sev', status = 'new', message = 'Disk low $n', raw_data_json = '{\"check\":\"disk_c\"}'");
    return mysqli_fetch_assoc($db->query('SELECT * FROM rmm_alerts WHERE id = ' . $db->insert_id));
};
$tk = fn(int $id): array => mysqli_fetch_assoc($db->query("SELECT * FROM tickets WHERE ticket_id = $id")) ?: [];
$num = 5000;
/** A plain ticket (client 1, contact Jane, status Open). */
$mkTicket = function (bool $email = true, array $set = []) use ($q, $db, &$num): int {
    $num++;
    $cols = array_merge(['ticket_prefix' => 'T', 'ticket_number' => $num, 'ticket_subject' => "Subject $num", 'ticket_details' => 'd', 'ticket_priority' => 'High', 'ticket_status' => 2,
        'ticket_client_id' => 1, 'ticket_contact_id' => 1, 'ticket_created_by' => 1, 'ticket_url_key' => "key$num", 'ticket_source' => 'Agent', 'ticket_send_email' => $email ? 1 : 0], $set);
    $sql = [];
    foreach ($cols as $k => $v) { $sql[] = "`$k` = " . ($v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'"); }
    if (!$q('INSERT INTO tickets SET ticket_created_at = NOW(), ' . implode(', ', $sql))) { echo 'INSERT failed: ' . $db->error . "\n"; }
    return (int) $db->insert_id;
};
$mailsFor = fn(int $tid): int => (int) $one("SELECT COUNT(*) FROM email_queue WHERE email_ticket_id = $tid");

$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sid = []; foreach ([1, 10, 14, 20, 21, 22, 30] as $u) { $sid[$u] = ea_forge_session($sdir, $u); }
$agentPost = fn(array $f, int $uid = 1, bool $csrf = true) => web($wb, 'POST', '/agent/post.php', $sid[$uid], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), ['Referer: ' . $wb . '/agent/ticket.php']);
$agentGet = fn(string $qs, int $uid = 1) => web($wb, 'GET', '/agent/post.php?' . $qs . '&csrf_token=csrftok1', $sid[$uid], [], ['Referer: ' . $wb . '/agent/ticket.php']);
$REF = ['Referer: ' . $wb . '/admin/settings_integrations.php?tab=rmm'];
$adminPost = fn(array $f, int $uid = 1, bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $sid[$uid], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $REF);

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- all four alert-to-ticket paths: Maintenance, RMM-sourced, no SLA, no email by default ---\n";
$paths = [];
// 1. the real "Create ticket" button handler
$a1 = $mkAlert();
$r = web($wb, 'POST', '/agent/post/rmm_alert.php', $sid[1], ['csrf_token' => 'csrftok1', 'action' => 'create_ticket', 'alert_id' => $a1['id']]);
$paths['Create ticket button'] = (int) (json_decode($r[1], true)['ticket_id'] ?? 0);
// 2. the real cron auto-ticketing
$a2 = $mkAlert();
$q("UPDATE settings SET config_rmm_auto_ticket_severities = 'critical', config_enable_cron = 1");
$cronEnv = 'RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=' . escapeshellarg((string) getenv('RIVETMSP_REDIS_PORT')) . ' RIVETMSP_REDIS_ENV_FILE=/dev/null';
$runCron = fn() => shell_exec("cd " . escapeshellarg("$root/cron") . " && $cronEnv php cron.php </dev/null 2>&1");
$runCron();
$q("UPDATE settings SET config_rmm_auto_ticket_severities = '', config_enable_cron = 0");
$paths['cron auto-ticketing'] = (int) $one("SELECT ticket_id FROM rmm_alerts WHERE id = {$a2['id']}");
// 3. the real automation rule action
$a3 = $mkAlert(); $ctx = ['alert' => $a3, 'alert_id' => $a3['id'], 'client_id' => 1];
automationExecuteAction($db, ['action' => 'create_ticket_from_alert'], $ctx, ['rule_name' => 'Disk rule']);
$paths['automation rule'] = (int) ($ctx['tid'] ?? 0);
// 4. the real endpoint escalation (critical alert) ensureTicket()
$a4 = $mkAlert();
$esc = new \RivetMSP\Core\Adapter\Endpoint\EndpointEscalation($db);
$m = new ReflectionMethod($esc, 'ensureTicket'); $m->setAccessible(true);
$paths['endpoint escalation'] = (int) ($m->invoke($esc, $a4)['ticket_id'] ?? 0);
foreach ($paths as $label => $tid) {
    $t = $tk($tid);
    $ok($tid > 0 && (int) $t['ticket_category'] === $maint && $t['ticket_rmm_sourced'] === '1' && $t['ticket_counts_sla'] === '0' && $t['ticket_send_email'] === '0'
        && $t['ticket_sla_response_due'] === null && $t['ticket_sla_resolution_due'] === null, "$label: Maintenance, RMM-sourced, no SLA (no due dates), no email");
}
$ok($tk($paths['endpoint escalation'])['ticket_source'] === 'RMM Escalation' && $tk($paths['automation rule'])['ticket_source'] === 'Automation: Disk rule' && $tk($paths['cron auto-ticketing'])['ticket_source'] === 'RMM Automation', 'each path kept its own ticket_source');
$ok($mailsFor($paths['Create ticket button']) + $mailsFor($paths['cron auto-ticketing']) + $mailsFor($paths['automation rule']) === 0, 'no email was queued for any new RMM ticket');
$ctl = $mkTicket(); recalculateTicketSla($db, $ctl);
$ok($tk($ctl)['ticket_sla_response_due'] !== null, 'control: an ordinary ticket under the same policy does get SLA due dates');

echo "\n--- the settings only seed NEW tickets ---\n";
$q("UPDATE settings SET config_rmm_alert_ticket_sla = 1, config_rmm_alert_ticket_email = 1");
$tOn = createTicketFromRmmAlert($db, $mkAlert(), 0, 'RMM Alert')['ticket_id']; $t = $tk($tOn);
$ok($t['ticket_counts_sla'] === '1' && $t['ticket_send_email'] === '1' && $t['ticket_rmm_sourced'] === '1' && $t['ticket_sla_response_due'] !== null && $t['ticket_sla_resolution_due'] !== null, 'both settings on: a new RMM ticket starts its SLA (due dates set) and sends email');
$ok(abs(strtotime($t['ticket_sla_response_due']) - strtotime($t['ticket_created_at']) - 3600) <= 5, 'and its response due is creation + the policy target');
$q("UPDATE settings SET config_rmm_alert_ticket_sla = 0, config_rmm_alert_ticket_email = 0");
$t = $tk($tOn);
$ok($t['ticket_counts_sla'] === '1' && $t['ticket_send_email'] === '1', 'turning the settings back off does not touch tickets that already exist');
$t = $tk($paths['cron auto-ticketing']);
$ok($t['ticket_counts_sla'] === '0', 'and turning them on had not touched the earlier ones either');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- per-ticket Count toward SLA (both directions, both anchors) ---\n";
$tid = $paths['Create ticket button'];
$q("UPDATE tickets SET ticket_created_at = NOW() - INTERVAL 3 HOUR WHERE ticket_id = $tid");
$ok(ticketSetCountsSla($db, $tid, true, 'bogus', 'Tester') === 'invalid' && $tk($tid)['ticket_counts_sla'] === '0', 'an unknown start anchor is refused, nothing changes');
$ok(ticketSetCountsSla($db, $tid, true, 'now', 'Tester') === 'changed', 'on, starting now');
$t = $tk($tid);
$ok($t['ticket_counts_sla'] === '1' && $t['ticket_sla_started_at'] !== null && abs(strtotime($t['ticket_sla_started_at']) - time()) <= 5, 'the SLA start is stamped now');
$ok(abs(strtotime($t['ticket_sla_response_due']) - (strtotime($t['ticket_sla_started_at']) + 3600)) <= 5 && abs(strtotime($t['ticket_sla_resolution_due']) - (strtotime($t['ticket_sla_started_at']) + 480 * 60)) <= 5, 'due dates run from now (not from creation 3h ago)');
$ok(slaDueState($db, $t, $t['ticket_sla_response_due'])['breached'] === false, 'so it is not breached even though it was created 3h ago');
$pctRow = mysqli_fetch_assoc($db->query("SELECT t.* FROM tickets t WHERE ticket_id = $tid"));   // what cron's SLA automation conditions read (slaStatus on t.*)
$ok(slaStatus($pctRow, [])['resolution']['pct'] < 5.0, 'slaStatus measures the % used from the "now" anchor (cron automation conditions do not see a fresh SLA as ~100% used)');
$q("UPDATE tickets SET ticket_priority = 'Low' WHERE ticket_id = $tid"); recalculateTicketSla($db, $tid); $t2 = $tk($tid);
$ok($t2['ticket_sla_response_due'] === $t['ticket_sla_response_due'], 'a later recalculation (priority change) keeps the "from now" anchor');
$ok(ticketSetCountsSla($db, $tid, true, 'now', 'Tester') === 'unchanged', 'turning on what is on changes nothing');
$ok(ticketSetCountsSla($db, $tid, false, '', 'Tester') === 'changed', 'off');
$t = $tk($tid);
$ok($t['ticket_counts_sla'] === '0' && $t['ticket_sla_response_due'] === null && $t['ticket_sla_resolution_due'] === null && $t['ticket_sla_policy_id'] === null, 'off clears the due dates and the policy');
$ok(ticketSetCountsSla($db, $tid, true, 'created', 'Tester') === 'changed', 'on, from ticket creation');
$t = $tk($tid);
$ok($t['ticket_sla_started_at'] === null && abs(strtotime($t['ticket_sla_response_due']) - (strtotime($t['ticket_created_at']) + 3600)) <= 5, 'due dates run from creation');
$ok(slaDueState($db, $t, $t['ticket_sla_response_due'])['breached'] === true, 'so the 1h response target of a 3h-old ticket is breached, as chosen');
$hist = $rows("SELECT ticket_history_description AS d FROM ticket_history WHERE ticket_history_ticket_id = $tid ORDER BY ticket_history_id");
$ok(count($hist) === 3 && str_contains($hist[0]['d'], 'Tester turned Count toward SLA on (was off), starting the SLA from now') && str_contains($hist[1]['d'], 'off (was on)') && str_contains($hist[2]['d'], 'from ticket creation'), 'every change is in the ticket history: who, old -> new, which anchor');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Ticket' AND log_entity_id = $tid AND log_description LIKE '%Count toward SLA%'") === 3, 'and in the audit log');

echo "\n--- precedence: ticket_counts_sla = 0 beats the \"pauses the SLA\" statuses ---\n";
$p1 = $mkTicket(true, ['ticket_counts_sla' => 0, 'ticket_rmm_sourced' => 1]);
$q("UPDATE tickets SET ticket_status = 3 WHERE ticket_id = $p1"); slaSyncPause($db, $p1);
$q("UPDATE tickets SET ticket_status = 2 WHERE ticket_id = $p1"); slaSyncPause($db, $p1);   // leaving the pause status runs recalculateTicketSla
slaAccruePause($db, $p1, 2, 3); slaAccruePause($db, $p1, 3, 2);
$t = $tk($p1);
$ok($t['ticket_sla_response_due'] === null && $t['ticket_sla_resolution_due'] === null, 'a pause and resume (both pause paths) never brings SLA back to a no-SLA ticket');
$ok(slaStatus($t, [])['response']['state'] === 'none' && slaStatus($t, [])['resolution']['state'] === 'none', 'slaStatus: no SLA at all (not paused, not breached)');
$p2 = $mkTicket(true, ['ticket_counts_sla' => 0]);
$q("UPDATE tickets SET ticket_status = 6, ticket_created_at = NOW() - INTERVAL 5 HOUR WHERE ticket_id = $p2"); slaSyncPause($db, $p2);
ticketSetCountsSla($db, $p2, true, 'now', 'Tester'); $t = $tk($p2);
$ok($t['ticket_sla_paused_at'] !== null && abs(strtotime($t['ticket_sla_paused_at']) - time()) <= 5 && (int) $t['ticket_sla_paused_seconds'] === 0, 'turned on (from now) while waiting on the customer: paused from now, no earlier pause carried over');
$st = slaDueState($db, $t, $t['ticket_sla_response_due']);
$ok($st['paused'] === true && $st['breached'] === false, 'so it does not start counting until it leaves the waiting status');
$p3 = $mkTicket(true, ['ticket_counts_sla' => 0]);
$q("UPDATE tickets SET ticket_status = 3 WHERE ticket_id = $p3");   // a path that did not sync
ticketSetCountsSla($db, $p3, true, 'created', 'Tester'); $t = $tk($p3);
$ok($t['ticket_sla_paused_at'] !== null && slaStatus($t, [])['resolution']['state'] === 'paused', 'turned on (from creation) in a pausing status: the pause is synced first, the clock does not run early');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- reports and dashboard ---\n";
$q("UPDATE tickets SET ticket_archived_at = NOW()");   // only the tickets below count
$r1 = $mkTicket(); recalculateTicketSla($db, $r1);
$q("UPDATE tickets SET ticket_first_response_at = NOW(), ticket_resolved_at = NOW(), ticket_closed_at = NOW() WHERE ticket_id = $r1");
$r2 = createTicketFromRmmAlert($db, $mkAlert(), 0, 'RMM Alert')['ticket_id'];
$r3 = createTicketFromRmmAlert($db, $mkAlert(), 0, 'RMM Alert')['ticket_id'];
$q("UPDATE tickets SET ticket_sla_response_due = NOW() - INTERVAL 1 DAY, ticket_sla_resolution_due = NOW() - INTERVAL 1 DAY WHERE ticket_id = $r3");  // a no-SLA ticket whose due dates were written behind the SLA engine's back
$from = date('Y-m-d', strtotime('-1 day')); $to = date('Y-m-d', strtotime('+1 day'));
$inc = getServiceDeskReport($db, $from, $to); $exc = getServiceDeskReport($db, $from, $to, null, 'exclude'); $only = getServiceDeskReport($db, $from, $to, null, 'only');
$ok($inc['totals']['opened'] === 3 && $inc['maintenance'] === 'include', 'volume (include, the default): the RMM no-SLA tickets are still counted');
$ok($inc['sla']['response_total'] === 1 && $inc['sla']['resolution_total'] === 1 && $inc['sla']['response_pct'] == 100.0, 'SLA compliance only counts the ticket that counts toward SLA (even one with stray due dates is left out)');
$ok($exc['totals']['opened'] === 1 && $only['totals']['opened'] === 2 && $only['sla']['response_total'] === 0, 'the Maintenance filter isolates (only) or excludes them');
$ok(getServiceDeskReport($db, $from, $to, null, "only' OR 1=1 --")['maintenance'] === 'include', 'a hostile filter value falls back to include');
$page = web($wb, 'GET', '/agent/reports/service_desk.php?maintenance=exclude&canned_date=custom&dtf=' . $from . '&dtt=' . $to, $sid[1]);
$ok($page[0] === 200 && str_contains($page[1], 'id="reportMaintenance"') && preg_match('/<option value="exclude" selected>/', $page[1]) === 1, 'the report page has the Maintenance filter and keeps the chosen value');
$ok(str_contains((string) file_get_contents("$root/api/v1/reports/service_desk.php"), "in_array(\$_GET['maintenance'], ['include', 'exclude', 'only'], true)"), 'the API whitelists ?maintenance=');
$q("UPDATE tickets SET ticket_assigned_to = 1 WHERE ticket_id IN ($r2, $r3)");
$q("UPDATE tickets SET ticket_sla_response_due = NULL, ticket_sla_resolution_due = NULL WHERE ticket_id = $r3"); recalculateTicketSla($db, $r3);
$late = $mkTicket(true, ['ticket_assigned_to' => 1, 'ticket_sla_resolution_due' => date('Y-m-d H:i:s', time() - 3600)]);
$q("UPDATE user_settings SET user_config_dashboard_technical_enable = 1 WHERE user_id = 1");
$dash = web($wb, 'GET', '/agent/dashboard.php', $sid[1]);
$rowOf = function (string $html, int $tid): string { $p = strpos($html, "ticket.php?ticket_id=$tid&"); if ($p === false) { return ''; } $e = strpos($html, '</tr>', $p); return substr($html, $p, $e - $p); };
$slaBadge = fn(string $row): bool => preg_match("/text-bg-(danger|warning|success|secondary)'>(Breached|Paused|OK)</", $row) === 1;
$ok($dash[0] === 200 && str_contains($rowOf($dash[1], $late), "'>Breached<"), 'dashboard control: an overdue ordinary ticket shows Breached');
$rr = $rowOf($dash[1], $r2);
$ok($rr !== '' && !$slaBadge($rr) && str_contains($rr, "<td><span class='text-muted'>-</span></td>"), 'dashboard: the no-SLA RMM ticket shows no SLA badge at all ("-")');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- email default per ticket source ---\n";
$q("UPDATE tickets SET ticket_archived_at = NULL");
$ok($tk($r2)['ticket_send_email'] === '0', 'RMM ticket: email off by default');
$q("UPDATE settings SET config_rmm_alert_ticket_email = 0, config_rmm_alert_ticket_sla = 0");
$agentPost(['add_ticket' => '1', 'client_id' => '1', 'contact_id' => '1', 'subject' => 'Agent made', 'details' => 'x', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0']);
$agentTid = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'Agent made'");
$ok($agentTid > 0 && $tk($agentTid)['ticket_send_email'] === '1' && $tk($agentTid)['ticket_rmm_sourced'] === '0', 'agent-created ticket (real handler): email on, not RMM');
$config_ticket_client_general_notifications = 1; $config_ticket_new_ticket_notification_email = ''; $config_ticket_from_email = 'support@msp.example'; $config_ticket_from_name = 'MSP Support';
$config_app_name = 'RivetMSP'; $config_ticket_default_billable = 0; $config_base_url = 'localhost';
$mailTid = (int) addTicket(1, 'Jane Roe', 'jane@client.example', 1, date('Y-m-d H:i:s'), 'Printer from email', 'body', [], '', []);
$ok($mailTid > 0 && $tk($mailTid)['ticket_send_email'] === '1' && $tk($mailTid)['ticket_source'] === 'Email', 'inbound-email ticket (real addTicket) with every RMM/email setting off: email on');
$ok($mailsFor($mailTid) >= 1, 'and its "ticket created" email to the customer was queued');
$ok(ticketSetSendEmail($db, $mailTid, false, 'Tester') === 'refused' && $tk($mailTid)['ticket_send_email'] === '1', 'an inbound-email ticket cannot be switched off (function)');
$agentPost(['set_ticket_send_email' => '1', 'ticket_id' => (string) $mailTid, 'value' => '0']);
$agentPost(['bulk_set_ticket_send_email' => '1', 'ticket_ids' => [(string) $mailTid, (string) $agentTid], 'bulk_send_email' => '0']);
$ok($tk($mailTid)['ticket_send_email'] === '1' && $tk($agentTid)['ticket_send_email'] === '0', 'nor through the ticket page or the bulk action (the other ticket in the same bulk did switch off)');
$ok(preg_match_all("/ticket_send_email\s*=\s*0|ticket_send_email = \? /", (string) file_get_contents("$root/functions.php") . file_get_contents("$root/cron/ticket_email_parser.php")) === 0, 'no mail-intake code writes ticket_send_email = 0');
$q("UPDATE tickets SET ticket_send_email = 1 WHERE ticket_id = $agentTid");

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- per-ticket email switch both ways, audit, queued mail cancelled ---\n";
$e1 = $mkTicket();
addToMailQueue([['from' => 'support@msp.example', 'from_name' => 'S', 'recipient' => 'jane@client.example', 'recipient_name' => 'J', 'subject' => 'queued', 'body' => 'b', 'ticket_id' => $e1]]);
$q("INSERT INTO email_queue SET email_recipient='jane@client.example', email_from='support@msp.example', email_from_name='S', email_subject='retry me', email_content='c', email_ticket_id=$e1, email_status=2, email_attempts=1, email_failed_at=NOW() - INTERVAL 1 HOUR");
$q("INSERT INTO email_queue SET email_recipient='jane@client.example', email_from='support@msp.example', email_from_name='S', email_subject='sent before', email_content='c', email_ticket_id=$e1, email_status=3, email_attempts=1, email_sent_at=NOW()");
$ok((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_ticket_id = $e1 AND " . \RivetMSP\Mail\MailQueuePolicy::retryDueSql()) === 1, 'setup: one failed email for the ticket is due for a cron retry');
$ok(ticketSetSendEmail($db, $e1, false, 'Tester') === 'changed' && $tk($e1)['ticket_send_email'] === '0', 'off');
$ok((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_ticket_id = $e1 AND (email_status = 0 OR " . \RivetMSP\Mail\MailQueuePolicy::retryDueSql() . ")") === 0
    && (int) $one("SELECT COUNT(*) FROM email_queue WHERE email_ticket_id = $e1 AND email_status = 2 AND email_attempts = 99") === 2 && (int) $one("SELECT COUNT(*) FROM email_queue WHERE email_ticket_id = $e1 AND email_status = 3") === 1,
    'turning it off cancels the queued and the retry-pending email (Mail queue "Cancel" convention); already-sent mail is left alone; the cron retry has nothing to send');
$ok(ticketSetSendEmail($db, $e1, false, 'Tester') === 'unchanged', 'off again changes nothing');
$ok(ticketSetSendEmail($db, $e1, true, 'Tester') === 'changed' && $tk($e1)['ticket_send_email'] === '1', 'on');
$hist = $rows("SELECT ticket_history_description AS d FROM ticket_history WHERE ticket_history_ticket_id = $e1 ORDER BY ticket_history_id");
$ok(count($hist) === 2 && str_contains($hist[0]['d'], 'Tester turned email notifications off (was on), cancelling 2 queued email(s)') && str_contains($hist[1]['d'], 'Tester turned email notifications on (was off)'), 'both changes are in the ticket history with who and old -> new');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Ticket' AND log_entity_id = $e1 AND log_description LIKE '%email notifications%'") === 2, 'and in the audit log');
$ok(ticketSetSendEmail($db, 99999999, false, 'Tester') === 'missing', 'an unknown ticket is reported, nothing written');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- REGRESSION: every ticket mail path queues NOTHING for a ticket whose email is off (and does queue for a control ticket) ---\n";
$q("UPDATE users SET user_email = 'stock@example.test' WHERE user_id = 14");
/** Run $fire against a fresh email-on and a fresh email-off ticket; the control must queue mail (the path really mails in this setup), the muted one nothing. */
$walk = function (string $label, callable $fire, array $set = [], ?callable $count = null) use ($mkTicket, $mailsFor, $ok, $q, $db) {
    $count = $count ?? $mailsFor;
    $on = $mkTicket(true, $set); $off = $mkTicket(false, $set);
    $q("INSERT INTO ticket_watchers SET watcher_email = 'watch@client.example', watcher_ticket_id = $on");
    $q("INSERT INTO ticket_watchers SET watcher_email = 'watch@client.example', watcher_ticket_id = $off");
    $fire($on); $fire($off);
    $c = $count($on); $m = $count($off);
    $ok($c > 0 && $m === 0, "$label: control queued $c, email-off ticket queued $m");
    return [$on, $off];
};
$walk('public reply (Public + Email)', fn($t) => $agentPost(['add_ticket_reply' => '1', 'ticket_id' => (string) $t, 'ticket_reply' => 'Hello there', 'status' => '2', 'hours' => '0', 'minutes' => '0', 'seconds' => '0', 'public_reply_type' => '2']));
$walk('reply that resolves and closes (with the CSAT survey)', fn($t) => $agentPost(['add_ticket_reply' => '1', 'ticket_id' => (string) $t, 'ticket_reply' => 'Fixed', 'status' => '4', 'hours' => '0', 'minutes' => '0', 'seconds' => '0', 'public_reply_type' => '2']));
$walk('resolve (with the CSAT survey)', fn($t) => $agentGet("resolve_ticket=$t"));
$walk('close (with the feedback link)', fn($t) => $agentGet("close_ticket=$t"));
$walk('assign to a technician', fn($t) => $agentPost(['assign_ticket' => '1', 'ticket_id' => (string) $t, 'assigned_to' => '14', 'ticket_status' => '2']));
$walk('change the contact (notify)', fn($t) => $agentPost(['edit_ticket_contact' => '1', 'ticket_id' => (string) $t, 'contact' => '1', 'contact_notify' => '1']));
$walk('edit ticket (notify the contact)', fn($t) => $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $t, 'contact_id' => '1', 'contact_notify' => '1', 'subject' => 'Edited', 'details' => 'd', 'priority' => 'High', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0']));
$walk('add a watcher (notify)', fn($t) => $agentPost(['add_ticket_watcher' => '1', 'ticket_id' => (string) $t, 'watcher_email' => 'new@client.example', 'watcher_notify' => '1']));
$walk('schedule work', fn($t) => $agentPost(['edit_ticket_schedule' => '1', 'ticket_id' => (string) $t, 'scheduled_date_time' => date('Y-m-d\TH:i', time() + 86400), 'scheduled_end_time' => date('Y-m-d\TH:i', time() + 90000), 'onsite' => '0', 'appointment_notes' => '']), ['ticket_assigned_to' => 14]);
$walk('cancel scheduled work', fn($t) => $agentGet("cancel_ticket_schedule=$t"), ['ticket_assigned_to' => 14, 'ticket_schedule' => date('Y-m-d H:i:s', time() + 86400)]);
$walk('bulk reply', fn($t) => $agentPost(['bulk_ticket_reply' => '1', 'ticket_ids' => [(string) $t], 'bulk_reply_details' => 'Bulk hello', 'bulk_status' => '2', 'time' => '00:05:00']));
$walk('bulk resolve (with the CSAT survey)', fn($t) => $agentPost(['bulk_resolve_tickets' => '1', 'ticket_ids' => [(string) $t], 'bulk_details' => 'Bulk fixed', 'time' => '00:05:00']));
$walk('kanban drag to Resolved', fn($t) => web($wb, 'POST', '/agent/ajax.php', $sid[1], ['update_kanban_ticket' => '1', 'csrf_token' => 'csrftok1', 'positions' => [['ticket_id' => $t, 'ticket_order' => 1, 'ticket_oldStatus' => 2, 'ticket_status' => 4]]]));
$q("UPDATE settings SET config_ticket_autoclose_hours = 1, config_enable_cron = 1, config_ticket_csat_reminder_days = 1");
[$acOn, $acOff] = [$mkTicket(true, ['ticket_status' => 4]), $mkTicket(false, ['ticket_status' => 4])];
$q("UPDATE tickets SET ticket_updated_at = NOW() - INTERVAL 5 HOUR, ticket_resolved_at = NOW() - INTERVAL 5 HOUR WHERE ticket_id IN ($acOn, $acOff)");
[$csOn, $csOff] = [$mkTicket(true, ['ticket_status' => 5]), $mkTicket(false, ['ticket_status' => 5])];
$q("UPDATE tickets SET ticket_closed_at = NOW() - INTERVAL 3 DAY, ticket_resolved_at = NOW() - INTERVAL 3 DAY WHERE ticket_id IN ($csOn, $csOff)");
$runCron();
$q("UPDATE settings SET config_enable_cron = 0");
$ok($tk($acOff)['ticket_status'] === '5' && $mailsFor($acOn) > 0 && $mailsFor($acOff) === 0, 'cron auto-close: both closed; control mailed ' . $mailsFor($acOn) . ', email-off ticket ' . $mailsFor($acOff));
$ok(str_contains((string) $one("SELECT email_subject FROM email_queue WHERE email_ticket_id = $csOn LIMIT 1"), 'How did we do') && $mailsFor($csOff) === 0, 'cron CSAT survey reminder: control mailed, email-off ticket nothing');
// task approval request (agent/post/task.php) needs a task row; outtake signing link (agent/post/outtake.php) needs an outtake form row
$walk('outtake form signing link', function ($t) use ($q, $db, $agentPost) {
    $q("INSERT INTO ticket_outtake_forms SET outtake_ticket_id = $t, outtake_sign_token = '" . bin2hex(random_bytes(16)) . "'");
    $agentPost(['send_outtake_email' => '1', 'outtake_id' => (string) $db->insert_id]);
});
$walk('task approval request to the contact', function ($t) use ($q, $db, $agentPost) {
    $q("INSERT INTO tasks SET task_name = 'Approve me', task_ticket_id = $t");
    $agentPost(['add_ticket_task_approver' => '1', 'task_id' => (string) $db->insert_id, 'approval_scope' => 'client', 'approval_type' => 'any']);
});
// bulk assign: ONE summary email for several tickets; dropped only when every ticket in it has email off
$bOn = $mkTicket(true); $bOff1 = $mkTicket(false); $bOff2 = $mkTicket(false);
$sum = fn() => (int) $one("SELECT COUNT(*) FROM email_queue WHERE email_subject LIKE '%tickets have been assigned to you'");
$agentPost(['bulk_assign_ticket' => '1', 'ticket_ids' => [(string) $bOff1, (string) $bOff2], 'assign_to' => '14']);
$ok($sum() === 0, 'bulk assign of email-off tickets only: no summary email');
$agentPost(['bulk_assign_ticket' => '1', 'ticket_ids' => [(string) $bOn, (string) $bOff1], 'assign_to' => '14']);
$ok($sum() === 1, 'bulk assign including an email-on ticket: the summary is sent');
// the gate itself, by subject token (a caller that forgets ticket_id but writes [T123] in the subject) and the multi-ticket rule
$g = $mkTicket(false);
addToMailQueue([['from' => 'support@msp.example', 'from_name' => 'S', 'recipient' => 'x@client.example', 'recipient_name' => 'X', 'subject' => 'Ticket update - [T' . $tk($g)['ticket_number'] . '] - s', 'body' => 'b']]);
$ok($mailsFor($g) === 0 && ($GLOBALS['mail_queue_last_queued'] ?? -1) === 0, 'the gate also catches an email that only names the ticket in its subject token');
// future guard: every first-party array literal that queues mail about a ticket names the ticket; no raw INSERT INTO email_queue for tickets
$miss = [];
$scan = ['agent/post/ticket.php', 'agent/ajax.php', 'agent/post/task.php', 'agent/post/recurring_ticket.php', 'agent/post/outtake.php', 'client/post.php', 'cron/cron.php', 'functions.php', 'guest/guest_post.php', 'api/v1/tickets.php', 'includes/automation_functions.php', 'includes/event_bus.php'];
foreach ($scan as $f) {
    $src = (string) file_get_contents("$root/$f");
    preg_match_all("/\[\s*(?:'from'|'recipient')\s*=>.*?'body'.*?\]/s", $src, $mm, PREG_OFFSET_CAPTURE);
    foreach ($mm[0] as [$lit, $off]) {
        if (preg_match("/'subject'\s*=>\s*([^\n]+)/", $lit, $sm) && stripos($lit, 'ticket') !== false && !str_contains($lit, "'ticket_id'") && !str_contains($lit, "'ticket_ids'")) {
            $line = substr_count(substr($src, 0, $off), "\n") + 1;
            $miss[] = "$f:$line";
        }
    }
}
$allowed = ['agent/post/recurring_ticket.php'];   // the recurring-TEMPLATE reassignment summary: templates, not tickets, so there is no ticket to gate on
$miss = array_values(array_filter($miss, fn($x) => !in_array(explode(':', $x)[0], $allowed, true)));
$ok($miss === [], 'every ticket mail literal in first-party code passes ticket_id/ticket_ids to the gate' . ($miss ? ' (missing: ' . implode(', ', $miss) . ')' : ''));
$raw = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php') || preg_match('#/(vendor|plugins|tests|node_modules|\.git)/#', $p)) { continue; }
    if (stripos((string) file_get_contents($p), 'INSERT INTO email_queue') !== false) { $raw[] = substr($p, strlen($root) + 1); }
}
sort($raw);
$ok($raw === ['agent/post/payment.php', 'functions.php'], 'only addToMailQueue() and the (non-ticket) payment receipt write email_queue directly: ' . implode(', ', $raw));
$ok(!preg_match('/new PHPMailer/', implode('', array_map(fn($f) => (string) @file_get_contents("$root/$f"), ['functions.php', 'agent/post/ticket.php', 'cron/cron.php', 'client/post.php']))), 'nothing ticket-related builds its own mailer (only cron/mail_queue.php sends)');

echo "\n--- the one-off \"email this reply anyway\" override ---\n";
$ov = $mkTicket(false);
$agentPost(['add_ticket_reply' => '1', 'ticket_id' => (string) $ov, 'ticket_reply' => 'Not emailed', 'status' => '2', 'hours' => '0', 'minutes' => '0', 'seconds' => '0', 'public_reply_type' => '2']);
$ok($mailsFor($ov) === 0 && $one("SELECT ticket_reply_emailed FROM ticket_replies WHERE ticket_reply_ticket_id = $ov AND ticket_reply LIKE '%Not emailed%'") === '0', 'without the override nothing is sent and the reply is recorded as not emailed');
$hostile = "Override <script>alert(1)</script> '; DROP TABLE tickets; -- O'Brien";
$agentPost(['add_ticket_reply' => '1', 'ticket_id' => (string) $ov, 'ticket_reply' => $hostile, 'status' => '2', 'hours' => '0', 'minutes' => '0', 'seconds' => '0', 'public_reply_type' => '2', 'email_override' => '1']);
$ok($mailsFor($ov) === 1 && $tk($ov)['ticket_send_email'] === '0', 'with the override exactly one email is queued and the switch stays off');
$ok($one("SELECT ticket_reply_emailed FROM ticket_replies WHERE ticket_reply_ticket_id = $ov AND ticket_reply LIKE '%DROP TABLE tickets%'") === '1' && (int) $one('SELECT COUNT(*) FROM tickets') > 0
    && str_contains((string) $one("SELECT email_subject FROM email_queue WHERE email_ticket_id = $ov"), '[T'), 'hostile reply text is stored literally (no SQL injection), recorded as emailed');
$agentPost(['add_ticket_reply' => '1', 'ticket_id' => (string) $ov, 'ticket_reply' => 'Internal with override', 'status' => '2', 'hours' => '0', 'minutes' => '0', 'seconds' => '0', 'public_reply_type' => '0', 'email_override' => '1']);
$ok($mailsFor($ov) === 1, 'the override never turns an internal note into an email');
$agentPost(['add_ticket_reply' => '1', 'ticket_id' => (string) $ov, 'ticket_reply' => 'Next one', 'status' => '2', 'hours' => '0', 'minutes' => '0', 'seconds' => '0', 'public_reply_type' => '2']);
$ok($mailsFor($ov) === 1, 'the next reply without the override is not emailed again');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- ticket page and list ---\n";
$pg = web($wb, 'GET', "/agent/ticket.php?ticket_id=$ov", $sid[1])[1];
$ok(str_contains($pg, 'id="ticketCountsSla"') && str_contains($pg, 'id="ticketSendEmail"') && str_contains($pg, 'id="ticketFlagForm"') && str_contains($pg, 'js/ticket_flags.js'), 'ticket page: both switches, the form and the script');
$ok(str_contains($pg, 'id="ticketEmailOffBanner"') && str_contains($pg, 'name="email_override"') && str_contains($pg, '<i class="fas fa-envelope fa-sm"></i> No email'), 'email off: the reply banner with the override, and the No email marker');
$pgRo = web($wb, 'GET', "/agent/ticket.php?ticket_id=$ov", $sid[20])[1];
$ok(preg_match('/id="ticketCountsSla"[^>]*disabled/', $pgRo) === 1 && !str_contains($pgRo, 'id="ticketFlagForm"'), 'a read-only technician sees the switches disabled and gets no form');
$pgMail = web($wb, 'GET', "/agent/ticket.php?ticket_id=$mailTid", $sid[1])[1];
$ok(preg_match('/id="ticketSendEmail"[^>]*checked[^>]*disabled/', $pgMail) === 1, 'an inbound-email ticket: email switch on and locked');
ticketSetCountsSla($db, $ov, false, '', 'Tester'); ticketSetCountsSla($db, $ov, true, 'now', 'Tester');
$pgS = web($wb, 'GET', "/agent/ticket.php?ticket_id=$ov", $sid[1])[1];
$ok(preg_match('/SLA counted from \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/', $pgS) === 1, 'started from now: "SLA counted from" in company time with its zone label');
$list = web($wb, 'GET', '/agent/tickets.php?q=T' . $tk($ov)['ticket_number'], $sid[1])[1];
$ok(str_contains($list, 'modals/ticket/ticket_bulk_email.php') && str_contains($list, 'Email Notifications'), 'ticket list: the bulk action is in the menu');
$ok(str_contains($list, 'title="Email notifications are off for this ticket"'), 'ticket list: the No email marker on an email-off row');
$md = web($wb, 'GET', '/agent/modals/ticket/ticket_bulk_email.php?ticket_ids[]=' . $ov . '&ticket_ids[]=abc', $sid[1]); $md[1] = (string) (json_decode($md[1], true)['content'] ?? $md[1]);
$ok($md[0] === 200 && str_contains($md[1], 'name="bulk_set_ticket_send_email"') && str_contains($md[1], 'name="ticket_ids[]" value="0"'), 'bulk modal renders; a non-numeric id becomes 0');
$ok(str_contains((string) file_get_contents("$root/js/ticket_flags.js"), 'RivetDialog.choice(') && str_contains((string) file_get_contents("$root/js/rivet_dialog.js"), "task.kind === 'choice'"), 'turning SLA on asks with RivetDialog.choice (the new kind)');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- permissions and hostile input on the per-ticket switches ---\n";
$pt = $mkTicket(true, ['ticket_counts_sla' => 1]);
$state = fn() => $tk($pt)['ticket_send_email'] . $tk($pt)['ticket_counts_sla'];
foreach ([20 => 'a read-only technician (support level 1)', 10 => 'a technician without the support module', 21 => 'a technician restricted to another client', 30 => 'a client-portal user'] as $u => $who) {
    $agentPost(['set_ticket_send_email' => '1', 'ticket_id' => (string) $pt, 'value' => '0'], $u);
    $agentPost(['set_ticket_counts_sla' => '1', 'ticket_id' => (string) $pt, 'value' => '0'], $u);
    $agentPost(['bulk_set_ticket_send_email' => '1', 'ticket_ids' => [(string) $pt], 'bulk_send_email' => '0'], $u);
    $ok($state() === '11', "$who cannot flip either switch");
}
$agentPost(['set_ticket_send_email' => '1', 'ticket_id' => (string) $pt, 'value' => '0'], 22, false);
$ok($state() === '11', 'no CSRF token: nothing changes');
foreach (['array value' => ['value' => ['0']], 'text value' => ['value' => "0' OR '1'='1"], 'missing value' => []] as $l => $f) {
    $agentPost(['set_ticket_send_email' => '1', 'ticket_id' => (string) $pt] + $f, 22);
    $ok($state() === '11', "hostile form ($l): refused");
}
$agentPost(['set_ticket_counts_sla' => '1', 'ticket_id' => (string) $pt, 'value' => '1', 'sla_anchor' => "now'; DROP TABLE tickets; --"], 22);
$agentPost(['set_ticket_counts_sla' => '1', 'ticket_id' => (string) $pt, 'value' => '0'], 22);
$agentPost(['set_ticket_counts_sla' => '1', 'ticket_id' => (string) $pt, 'value' => '1', 'sla_anchor' => "now'; DROP TABLE tickets; --"], 22);
$ok($tk($pt)['ticket_counts_sla'] === '0' && (int) $one('SELECT COUNT(*) FROM tickets') > 0, 'a hostile SLA start anchor is refused (the switch stays off)');
$agentPost(['set_ticket_send_email' => '1', 'ticket_id' => (string) $pt, 'value' => '0'], 22);
$agentPost(['set_ticket_counts_sla' => '1', 'ticket_id' => (string) $pt, 'value' => '1', 'sla_anchor' => 'created'], 22);
$ok($state() === '01', 'a support technician at level 2 (the same level as status/priority edits) can flip both');
$ok((int) $one("SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = $pt AND ticket_history_description LIKE 'edit turned%'") === 3, 'and the history names that technician');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- backfill (label only, dry run first) ---\n";
$q("UPDATE tickets SET ticket_archived_at = NOW()");
$due = date('Y-m-d H:i:s', time() + 7200);
$sla = ['ticket_sla_response_due' => $due, 'ticket_sla_resolution_due' => $due, 'ticket_sla_paused_seconds' => 120, 'ticket_sla_policy_id' => 1];
$bLinked = $mkTicket(true, $sla + ['ticket_source' => 'RMM Alert']);
$bAuto = $mkTicket(true, $sla + ['ticket_source' => 'Automation: Disk rule']);
$bEsc = $mkTicket(true, $sla + ['ticket_source' => 'RMM Escalation']);
$bBus = $mkTicket(true, $sla + ['ticket_source' => 'Automation: Event rule']);   // event-bus automation ticket, nothing to do with RMM
$bMail = $mkTicket(true, $sla + ['ticket_source' => 'Email']);
$bPlain = $mkTicket(true, $sla);
$q("INSERT INTO rmm_alerts SET client_id = 1, integration_id = $intAgent, tactical_alert_id = 'old-1', severity = 'critical', status = 'acknowledged', message = 'old', ticket_id = $bLinked");
$q("INSERT INTO rmm_alerts SET client_id = 1, integration_id = $intAgent, tactical_alert_id = 'old-2', severity = 'critical', status = 'acknowledged', message = 'old', ticket_id = $bAuto");
$q("INSERT INTO rmm_alerts SET client_id = 1, integration_id = $intAgent, tactical_alert_id = 'old-3', severity = 'critical', status = 'acknowledged', message = 'old', ticket_id = $bMail");  // even linked, an email ticket is never touched
$q("UPDATE tickets SET ticket_rmm_sourced = 0, ticket_send_email = 1 WHERE ticket_archived_at IS NOT NULL");   // every older ticket: pre-update state
addToMailQueue([['from' => 'support@msp.example', 'from_name' => 'S', 'recipient' => 'jane@client.example', 'recipient_name' => 'J', 'subject' => 'pending', 'body' => 'b', 'ticket_id' => $bLinked]]);
$snap = fn() => $rows("SELECT ticket_id, ticket_counts_sla, ticket_sla_response_due, ticket_sla_resolution_due, ticket_sla_paused_seconds, ticket_sla_paused_at, ticket_sla_policy_id, ticket_sla_started_at FROM tickets ORDER BY ticket_id");
$flags = fn() => $rows("SELECT ticket_id, ticket_rmm_sourced, ticket_send_email FROM tickets ORDER BY ticket_id");
$before = $snap(); $fb = $flags();
$r = rmmTicketDefaultsBackfill($db, true);
$ok($flags() === $fb, 'dry run writes nothing');
$expectMatched = (int) $one('SELECT COUNT(*) FROM tickets WHERE ' . rmmTicketBackfillWhereSql());
$ok($r['matched'] === $expectMatched && $r['email'] >= 3 && $r['label'] >= 3, "dry run counts what would change ({$r['matched']} matched, {$r['label']} labels, {$r['email']} email switches)");
$adminPost(['rmm_ticket_defaults_backfill' => 'run'], 14);
$adminPost(['rmm_ticket_defaults_backfill' => 'run'], 1, false);
$ok($flags() === $fb, 'a technician, or a post without CSRF, cannot run it');
$adminPost(['rmm_ticket_defaults_backfill' => 'dry']);
$ok($flags() === $fb && (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%RMM ticket backfill (dry run)%would change%'") === 1, 'admin dry run (real handler): nothing written, audited with "would change"');
$adminPost(['rmm_ticket_defaults_backfill' => 'run']);
$f = fn(int $id) => $tk($id)['ticket_rmm_sourced'] . $tk($id)['ticket_send_email'];
$ok($f($bLinked) === '10' && $f($bAuto) === '10' && $f($bEsc) === '10', 'real run: alert-linked, automation-with-alert and RMM-source tickets are labelled and their email turned off');
$ok($f($bBus) === '01' && $f($bPlain) === '01', 'an event-bus "Automation:" ticket with no alert and an ordinary ticket are untouched');
$ok($f($bMail) === '01', 'an inbound-email ticket is untouched even when an alert links to it');
$ok($snap() === $before, 'no SLA field of ANY ticket changed (label only)');
$ok((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_ticket_id = $bLinked AND email_status = 0") === 0, 'mail already queued for a ticket it turned off is cancelled');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%RMM ticket backfill:%changed%SLA untouched%'") === 1, 'the real run is audited with "changed"');
$r = rmmTicketDefaultsBackfill($db, true);
$ok($r['label'] === 0 && $r['email'] === 0, 'running it again has nothing left to change');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- settings card ---\n";
$q("UPDATE settings SET config_module_enable_rmm = 1, config_rmm_alert_ticket_category_id = NULL");
$q("INSERT INTO categories SET category_name = '<img src=x onerror=alert(1)>', category_type = 'Ticket'");
$page = web($wb, 'GET', '/admin/settings_integrations.php?tab=rmm', $sid[1]);
$ok($page[0] === 200 && str_contains($page[1], 'id="at_flag_sla"') && str_contains($page[1], 'id="at_flag_email"') && !preg_match('/id="at_flag_sla"[^>]*checked/', $page[1]), 'the card shows both switches, off by default');
$ok(str_contains($page[1], 'Use the &quot;Maintenance&quot; category for alert tickets') || str_contains($page[1], 'Use the "Maintenance" category for alert tickets'), 'a Maintenance category exists but is not chosen: the existing one-click button offers to use it');
$ok(str_contains($page[1], 'id="at-backfill-preview"') && str_contains($page[1], 'name="rmm_ticket_defaults_backfill" value="dry"') && str_contains($page[1], 'data-rivet-confirm='), 'the backfill preview, dry-run and confirmed (RivetDialog) apply buttons');
$ok(!str_contains($page[1], '<img src=x onerror=alert(1)>') && str_contains($page[1], '&lt;img src=x onerror=alert(1)&gt;'), 'a hostile category name is escaped on the card');
$adminPost(['create_alert_maintenance_category' => '1']);
$ok((int) $one('SELECT config_rmm_alert_ticket_category_id FROM settings') === $maint && (int) $one("SELECT COUNT(*) FROM categories WHERE category_name = 'Maintenance'") === 1, 'that button chooses the existing Maintenance category (no second one)');
$adminPost(['save_rmm_alert_ticket_flags' => '1', 'config_rmm_alert_ticket_sla' => '1', 'config_rmm_alert_ticket_email' => '1'], 14);
$adminPost(['save_rmm_alert_ticket_flags' => '1', 'config_rmm_alert_ticket_sla' => '1', 'config_rmm_alert_ticket_email' => '1'], 1, false);
$ok(rmmAlertTicketFlagDefaults($db) === ['sla' => false, 'email' => false], 'a technician, or a post without CSRF, cannot change them');
$adminPost(['save_rmm_alert_ticket_flags' => '1', 'config_rmm_alert_ticket_sla' => '1', 'config_rmm_alert_ticket_email' => ['1']]);
$ok(rmmAlertTicketFlagDefaults($db) === ['sla' => true, 'email' => false], 'admin save: SLA on; a hostile (array) email value reads as off');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%set RMM alert tickets: count toward SLA off -> on, send email off -> off%'") === 1, 'audited old -> new');
$adminPost(['save_rmm_alert_ticket_flags' => '1']);
$ok(rmmAlertTicketFlagDefaults($db) === ['sla' => false, 'email' => false], 'unticking both saves both off');
$ok(str_contains((string) @file_get_contents($web['log']), 'Fatal') === false, 'no PHP fatal in the server log');
$q("UPDATE tickets SET ticket_archived_at = NULL");
echo "\n";
