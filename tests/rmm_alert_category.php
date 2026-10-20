<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Which ticket category tickets made from RMM alerts get (26.10.37, DB 2.6.101), on a SCRATCH database.
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=mspac_scratch RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=6751 \
 *   RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/rmm_alert_category.php        (same scratch rules as tests/endpoint_agent_lib.php)
 * Covers: route resolution units, server-side validation (hostile input), the 2.6.100 -> 2.6.101 updater step, createTicketFromRmmAlert on all paths,
 * the real cron/cron.php auto-ticketing block, the settings page and handlers (admin only, CSRF, audit).
 */
$sd = sys_get_temp_dir() . '/rmm_ac_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd"); putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_functions.php";
require_once "$root/includes/rmm_alert_routes.php";
$sdir = sys_get_temp_dir() . '/rmm_ac_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sd, $sdir) { foreach ([$sd, $sdir] as $d) { foreach (glob("$d/*") ?: [] as $f) { @unlink($f); } @rmdir($d); } });

ea_reset(); ea_seed_users();
$q("DELETE FROM rmm_alert_ticket_routes"); $q("DELETE FROM categories WHERE category_type = 'Ticket'");
$q("UPDATE settings SET config_rmm_alert_ticket_category_id = NULL, config_rmm_auto_ticket_severities = ''");
$cat = function (string $n, bool $arch = false) use ($q, $db): int { $a = $arch ? ', category_archived_at = NOW()' : ''; $q("INSERT INTO categories SET category_name = '" . $db->real_escape_string($n) . "', category_type = 'Ticket'$a"); return (int) $db->insert_id; };
$maint = $cat('Maintenance'); $net = $cat('Network'); $sec = $cat('Security'); $gone = $cat('Old', true);
$q("INSERT INTO categories SET category_name = 'ExpenseCat', category_type = 'Expense'"); $expense = (int) $db->insert_id;
$q("INSERT INTO rmm_integrations (name, type, api_url, web_url, api_key_enc, enabled) VALUES ('Agent', 'rivetit_agent', '', '', '', 1)"); $intAgent = (int) $db->insert_id;
$q("INSERT INTO rmm_integrations (name, type, api_url, web_url, api_key_enc, enabled) VALUES ('Tactical', 'tactical_rmm', '', '', '', 1)"); $intTrmm = (int) $db->insert_id;
$n = 0;
$mkAlert = function (string $sev, string $check = '', int $integ = 0, string $key = '') use ($q, $db, &$n, $intAgent): array {
    $n++; $integ = $integ ?: $intAgent; $tid = $key !== '' ? $key : ($check !== '' ? "agent:7:$check:$n" : "trmm-$n");
    $raw = $check !== '' ? $db->real_escape_string(json_encode(['check' => $check, 'source' => 'rivetit_agent'])) : '';
    $q("INSERT INTO rmm_alerts SET client_id = 1, integration_id = $integ, tactical_alert_id = '" . $db->real_escape_string($tid) . "', severity = '$sev', status = 'new', message = 'Alert $n', raw_data_json = " . ($raw !== '' ? "'$raw'" : 'NULL'));
    return mysqli_fetch_assoc($db->query('SELECT * FROM rmm_alerts WHERE id = ' . $db->insert_id));
};
$tk = fn(int $id): array => mysqli_fetch_assoc($db->query("SELECT ticket_category, ticket_priority FROM tickets WHERE ticket_id = $id"));

echo "\n--- resolver units ---\n";
$R = fn($c, $p = null, $t = 'check_prefix', $v = 'disk', $s = 0, $id = 1) => ['id' => $id, 'match_type' => $t, 'match_value' => $v, 'category_id' => $c, 'priority' => $p, 'sort' => $s];
$a = ['severity' => 'critical', 'raw_data_json' => json_encode(['check' => 'disk_c']), 'tactical_alert_id' => 'agent:1:disk_c:2'];
$ok(rmmAlertResolveRoute($a, 'rivetit_agent', [], 0) === ['category_id' => 0, 'priority' => null, 'board_id' => null, 'matched' => 'none'], 'nothing configured: no category');
$ok(rmmAlertResolveRoute($a, '', [], 5)['category_id'] === 5, 'default only: default');
$routes = [$R(2, null, 'severity', 'critical', 0, 1), $R(3, 'Low', 'source', 'rivetit_agent', 0, 2), $R(4, 'High', 'check_prefix', 'disk', 0, 3)];
$r = rmmAlertResolveRoute($a, 'rivetit_agent', $routes, 9);
$ok($r['category_id'] === 4 && $r['priority'] === 'High' && $r['matched'] === 'check_prefix:disk', 'check family beats source, severity and default');
$r = rmmAlertResolveRoute(['severity' => 'critical', 'tactical_alert_id' => 'x'], 'rivetit_agent', $routes, 9);
$ok($r['category_id'] === 3 && $r['priority'] === 'Low', 'no check family: source beats severity');
$r = rmmAlertResolveRoute(['severity' => 'critical'], 'tactical_rmm', $routes, 9);
$ok($r['category_id'] === 2 && $r['priority'] === null, 'severity beats default, priority stays unset');
$ok(rmmAlertResolveRoute(['severity' => 'info'], 'tactical_rmm', $routes, 9)['category_id'] === 9, 'nothing matches: default');
$ok(rmmAlertResolveRoute(['severity' => 'info'], 'tactical_rmm', $routes, 0)['category_id'] === 0, 'nothing matches and no default: none');
$ok(rmmAlertResolveRoute(['severity' => 'CRITICAL'], 'X', [$R(2, null, 'severity', 'critical')], 0)['category_id'] === 2, 'severity compare is case-insensitive');
$ok(rmmAlertResolveRoute($a, '', [$R(6, 'Urgent', 'check_prefix', 'disk')], 0)['priority'] === null, 'a priority that is not Low/Medium/High is ignored');
$ok(rmmAlertResolveRoute($a, '', [$R(7, null, 'check_prefix', 'disk', 20, 1), $R(8, null, 'check_prefix', 'disk', 10, 2)], 0)['category_id'] === 8, 'lowest sort wins inside one kind');
foreach (['disk_c' => 'disk', 'disk.utilization' => 'disk', 'patch.compliance' => 'patch', 'log.rule_4' => 'log', 'service.spooler' => 'service', 'reboot.pending' => 'reboot', 'agent_update' => 'agent_update', 'change.fim' => 'change', 'cpu.utilization' => '', '' => '', 'DISK_D' => 'disk'] as $k => $f) {
    $ok(rmmAlertCheckFamily($k) === $f, "check key '$k' is family '" . ($f ?: 'none') . "'");
}
$ok(rmmAlertCheckKey(['tactical_alert_id' => 'agent:12:disk_c:3']) === 'disk_c' && rmmAlertCheckKey(['raw_data_json' => '{bad']) === '' && rmmAlertCheckKey(['raw_data_json' => '{"check":["x"]}']) === '', 'check key from the alert key; bad JSON or a non-string check gives none');

echo "\n--- validation (hostile input) ---\n";
$cats = [$maint => 'Maintenance', $net => 'Network'];
$v = rmmAlertValidateSettings(['default_category' => (string) $maint, 'route_type' => ['check_prefix', 'severity'], 'route_value' => ['disk', 'critical'], 'route_category' => [(string) $net, (string) $maint], 'route_priority' => ['High', '']], $cats);
$ok($v['errors'] === [] && $v['default'] === $maint && count($v['routes']) === 2 && $v['routes'][0]['priority'] === 'High' && $v['routes'][1]['priority'] === null, 'a good form is accepted');
$bad = [
    'unknown category' => ['default_category' => '999'],
    'archived category' => ['route_type' => ['severity'], 'route_value' => ['info'], 'route_category' => [(string) $gone]],
    'expense category' => ['route_type' => ['severity'], 'route_value' => ['info'], 'route_category' => [(string) $expense]],
    'bad type' => ['route_type' => ["severity' OR 1=1 --"], 'route_value' => ['info'], 'route_category' => [(string) $net]],
    'bad value' => ['route_type' => ['severity'], 'route_value' => ["info'; DROP TABLE tickets;--"], 'route_category' => [(string) $net]],
    'value from another type' => ['route_type' => ['severity'], 'route_value' => ['disk'], 'route_category' => [(string) $net]],
    'bad priority' => ['route_type' => ['severity'], 'route_value' => ['info'], 'route_category' => [(string) $net], 'route_priority' => ['<script>']],
    'array injection' => ['route_type' => [['x']], 'route_value' => [['y']], 'route_category' => [['z']]],
    'duplicate' => ['route_type' => ['severity', 'severity'], 'route_value' => ['info', 'info'], 'route_category' => [(string) $net, (string) $maint]],
    'too many' => ['route_type' => array_fill(0, 51, 'severity'), 'route_value' => array_fill(0, 51, 'info'), 'route_category' => array_fill(0, 51, (string) $net)],
];
foreach ($bad as $l => $post) { $v = rmmAlertValidateSettings($post, $cats); $ok($v['errors'] !== [] && $v['routes'] === [] || ($l === 'duplicate' && count($v['routes']) === 1 && $v['errors'] !== []) || ($l === 'too many' && $v['errors'] !== []), "rejected: $l"); }
$v = rmmAlertValidateSettings(['route_type' => 'notarray'], $cats);
$ok($v['routes'] === [] && $v['default'] === 0, 'non-array form fields are ignored safely');
$v = rmmAlertValidateSettings(['route_type' => [''], 'route_value' => [''], 'route_category' => ['']], $cats);
$ok($v['routes'] === [] && $v['errors'] === [], 'an empty row is skipped without an error');

echo "\n--- updater 2.6.100 -> 2.6.101 ---\n";
$q("INSERT INTO rmm_alert_ticket_routes SET match_type = 'severity', match_value = 'info', category_id = $net");
$q("UPDATE settings SET config_rmm_alert_ticket_category_id = $net");
$q("ALTER TABLE settings DROP COLUMN config_rmm_alert_ticket_category_id"); $q("DROP TABLE rmm_alert_ticket_routes");
$q("UPDATE settings SET config_current_database_version = '2.6.100'");
shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1');
$ok(version_compare((string) $one('SELECT config_current_database_version FROM settings LIMIT 1'), '2.6.101', '>='), 'the updater reaches 2.6.101 (or later)');
$cols = array_column($rows('SHOW COLUMNS FROM settings'), 'Null', 'Field');
$ok(($cols['config_rmm_alert_ticket_category_id'] ?? '') === 'YES' && $one('SELECT config_rmm_alert_ticket_category_id FROM settings LIMIT 1') === null, 'settings column exists, NULL by default (unchanged behaviour)');
$rc = array_column($rows('SHOW COLUMNS FROM rmm_alert_ticket_routes'), 'Type', 'Field');
$ok(array_keys($rc) === ['id', 'match_type', 'match_value', 'category_id', 'board_id', 'priority', 'sort'] && str_contains($rc['match_type'], "'severity','source','check_prefix'"), 'route table has the specified columns');
$q("INSERT INTO rmm_alert_ticket_routes SET match_type = 'severity', match_value = 'info', category_id = $net");
$q("UPDATE settings SET config_current_database_version = '2.6.100'");
shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1');
$ok(version_compare((string) $one('SELECT config_current_database_version FROM settings LIMIT 1'), '2.6.101', '>=') && (int) $one('SELECT COUNT(*) FROM rmm_alert_ticket_routes') === 1, 're-running the step is harmless and keeps rows');
$ok(str_contains((string) file_get_contents("$root/db.sql"), 'config_rmm_alert_ticket_category_id') && str_contains((string) file_get_contents("$root/db.sql"), 'CREATE TABLE `rmm_alert_ticket_routes`') && version_compare(LATEST_DATABASE_VERSION, '2.6.101', '>='), 'db.sql and LATEST_DATABASE_VERSION carry the change');
$q("DELETE FROM rmm_alert_ticket_routes");

echo "\n--- createTicketFromRmmAlert ---\n";
$t = createTicketFromRmmAlert($db, $mkAlert('critical', 'disk_c'), 0, 'RMM Alert'); $x = $tk($t['ticket_id']);
$ok((int) $x['ticket_category'] === 0 && $x['ticket_priority'] === 'High', 'UNCONFIGURED: no category, priority from severity (identical to before)');
$q("UPDATE settings SET config_rmm_alert_ticket_category_id = $maint");
$t = createTicketFromRmmAlert($db, $mkAlert('warning', '', $intTrmm), 0, 'RMM Alert'); $x = $tk($t['ticket_id']);
$ok((int) $x['ticket_category'] === $maint && $x['ticket_priority'] === 'Medium', 'default category applied, priority untouched');
$q("INSERT INTO rmm_alert_ticket_routes SET match_type='severity', match_value='critical', category_id=$sec, priority='Medium', sort=10");
$q("INSERT INTO rmm_alert_ticket_routes SET match_type='check_prefix', match_value='disk', category_id=$net, priority=NULL, sort=20");
$q("INSERT INTO rmm_alert_ticket_routes SET match_type='source', match_value='tactical_rmm', category_id=$sec, priority='Low', sort=30");
$t = createTicketFromRmmAlert($db, $mkAlert('critical', 'disk_c'), 0, 'RMM Alert'); $x = $tk($t['ticket_id']);
$ok((int) $x['ticket_category'] === $net && $x['ticket_priority'] === 'High', 'disk check beats critical severity; no route priority keeps the severity one');
$t = createTicketFromRmmAlert($db, $mkAlert('critical', '', $intAgent), 0, 'RMM Alert'); $x = $tk($t['ticket_id']);
$ok((int) $x['ticket_category'] === $sec && $x['ticket_priority'] === 'Medium', 'severity route sets category and priority');
$t = createTicketFromRmmAlert($db, $mkAlert('info', '', $intTrmm), 0, 'RMM Alert'); $x = $tk($t['ticket_id']);
$ok((int) $x['ticket_category'] === $sec && $x['ticket_priority'] === 'Low', 'source route (Tactical RMM) applies');
$t = createTicketFromRmmAlert($db, $mkAlert('info', 'cpu.utilization'), 0, 'RMM Alert'); $x = $tk($t['ticket_id']);
$ok((int) $x['ticket_category'] === $maint, 'an alert no route matches falls back to the default');
$q("UPDATE categories SET category_archived_at = NOW() WHERE category_id = $net");
$t = createTicketFromRmmAlert($db, $mkAlert('critical', 'disk_c'), 0, 'RMM Alert'); $x = $tk($t['ticket_id']);
$ok((int) $x['ticket_category'] === 0, 'a route whose category was archived gives no category (never a dead id)');
$q("UPDATE categories SET category_archived_at = NULL WHERE category_id = $net");
$q("DELETE FROM categories WHERE category_id = $sec");
$t = createTicketFromRmmAlert($db, $mkAlert('critical', ''), 0, 'RMM Alert'); $x = $tk($t['ticket_id']);
$ok((int) $x['ticket_category'] === 0, 'a deleted category gives no category');
$a = $mkAlert('critical', 'disk_c'); $t1 = createTicketFromRmmAlert($db, $a, 0, 'RMM Alert'); $t2 = createTicketFromRmmAlert($db, mysqli_fetch_assoc($db->query('SELECT * FROM rmm_alerts WHERE id = ' . (int) $a['id'])), 0, 'RMM Alert');
$ok($t2['existing'] === true && $t2['ticket_id'] === $t1['ticket_id'], 'asking again for an alert that already has a ticket does not make a second one');
$sec = $cat('Security');

echo "\n--- every alert-to-ticket path uses the one function ---\n";
foreach (['cron/cron.php', 'agent/post/rmm_alert.php', 'includes/automation_functions.php', 'src/Core/Adapter/Endpoint/EndpointEscalation.php'] as $f) {
    $ok(str_contains((string) file_get_contents("$root/$f"), 'createTicketFromRmmAlert('), "$f creates alert tickets through createTicketFromRmmAlert()");
}
$ok(!preg_match('/INSERT INTO tickets[^;]*rmm_alert/i', (string) file_get_contents("$root/includes/rmm_functions.php") . (string) file_get_contents("$root/agent/post/rmm_alert.php")) && substr_count((string) file_get_contents("$root/includes/rmm_functions.php"), 'INSERT INTO tickets') === 1, 'the function is the only alert ticket INSERT');

echo "\n--- the real cron auto-ticketing ---\n";
$q("DELETE FROM tickets"); $q("DELETE FROM rmm_alerts");
$q("UPDATE settings SET config_rmm_auto_ticket_severities = 'critical,warning', config_module_enable_rmm = 1, config_enable_cron = 1");
$q("DELETE FROM rmm_alert_ticket_routes"); $q("UPDATE settings SET config_rmm_alert_ticket_category_id = NULL");
$q("INSERT INTO rmm_alert_ticket_routes SET match_type='check_prefix', match_value='disk', category_id=$net, sort=10");
$q("INSERT INTO rmm_alert_ticket_routes SET match_type='severity', match_value='warning', category_id=$sec, priority='Low', sort=20");
$aDisk = $mkAlert('critical', 'disk_c'); $aWarn = $mkAlert('warning', '', $intTrmm); $aCrit = $mkAlert('critical', '', $intTrmm); $aInfo = $mkAlert('info', '', $intTrmm);
$env = 'RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=' . escapeshellarg((string) getenv('RIVETMSP_REDIS_PORT')) . ' RIVETMSP_REDIS_ENV_FILE=/dev/null';
$out = shell_exec("cd " . escapeshellarg("$root/cron") . " && $env php cron.php </dev/null 2>&1");
$tid = fn(array $al) => (int) $one("SELECT ticket_id FROM rmm_alerts WHERE id = {$al['id']}");
$cc = fn(int $t) => $t ? (int) $one("SELECT ticket_category FROM tickets WHERE ticket_id = $t") : -1;
$ok($tid($aDisk) > 0 && $cc($tid($aDisk)) === $net, 'cron: critical disk alert -> ticket in the disk route category');
$ok($tid($aWarn) > 0 && $cc($tid($aWarn)) === $sec && $one('SELECT ticket_priority FROM tickets WHERE ticket_id = ' . $tid($aWarn)) === 'Low', 'cron: warning alert -> severity route category and priority');
$ok($tid($aCrit) > 0 && $cc($tid($aCrit)) === 0, 'cron: critical alert no route matches and no default -> no category');
$ok($tid($aInfo) === 0, 'cron: info alert is not auto-ticketed (severity toggle still decides that)');
$q("DELETE FROM tickets"); $q("DELETE FROM rmm_alerts"); $q("UPDATE settings SET config_rmm_alert_ticket_category_id = $maint");
$aCrit = $mkAlert('critical', '', $intTrmm);
shell_exec("cd " . escapeshellarg("$root/cron") . " && $env php cron.php </dev/null 2>&1");
$ok($cc($tid($aCrit)) === $maint, 'cron: with a default category the unmatched alert goes there');
$q("UPDATE settings SET config_rmm_auto_ticket_severities = '', config_enable_cron = 0");

echo "\n--- settings page and handlers ---\n";
$q("DELETE FROM rmm_alert_ticket_routes"); $q("UPDATE settings SET config_rmm_alert_ticket_category_id = NULL, config_module_enable_rmm = 1"); $q("DELETE FROM categories WHERE category_name = 'Maintenance'");
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sidAdmin = ea_forge_session($sdir, 1); $sidTech = ea_forge_session($sdir, 10);
$REF = ['Referer: ' . $wb . '/admin/settings_integrations.php?tab=rmm'];
$post = fn(array $f, ?string $sid = null, bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $sid ?? $sidAdmin, $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $REF);
$page = web($wb, 'GET', '/admin/settings_integrations.php?tab=rmm', $sidAdmin);
if ($page[0] !== 200 || !str_contains($page[1], 'Alert tickets')) { echo 'DEBUG ' . $page[0] . ' ' . substr(strip_tags($page[1]), 0, 300) . ' | ' . substr($page[2], 0, 300) . ' | ' . substr((string) @file_get_contents($web['log']), -600) . "\n"; }
$ok($page[0] === 200 && str_contains($page[1], 'Alert tickets') && str_contains($page[1], 'id="at_default"') && str_contains($page[1], 'Create a "Maintenance" category'), 'the Alert tickets card shows, with the one-click Maintenance button when none exists');
$ok(str_contains($page[1], 'rmm_alert_routes.js') && str_contains($page[1], 'id="at-row-template"'), 'script and row template are on the page');
$mc = fn() => (int) $one("SELECT COUNT(*) FROM categories WHERE category_name = 'Maintenance' AND category_type = 'Ticket'");
$ok($post(['create_alert_maintenance_category' => '1'], null, false)[0] !== 0 && $mc() === 0, 'creating Maintenance without a CSRF token does nothing');
$post(['create_alert_maintenance_category' => '1'], $sidTech);
$ok($mc() === 0, 'a technician (not an admin) cannot create it');
$post(['create_alert_maintenance_category' => '1']);
$mid = (int) $one("SELECT category_id FROM categories WHERE category_name = 'Maintenance' AND category_type = 'Ticket'");
$ok($mc() === 1 && (int) $one('SELECT config_rmm_alert_ticket_category_id FROM settings') === $mid, 'admin: Maintenance created and chosen');
$post(['create_alert_maintenance_category' => '1']);
$ok($mc() === 1, 'doing it again does not create a second Maintenance');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Category' AND log_action = 'Create' AND log_description LIKE '%Maintenance%'") === 1 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Settings' AND log_description LIKE '%default ticket category for RMM alerts%'") >= 1, 'both actions are in the audit log');
$page = web($wb, 'GET', '/admin/settings_integrations.php?tab=rmm', $sidAdmin)[1];
$ok(!str_contains($page, 'Create a "Maintenance" category') && preg_match('/<option value="' . $mid . '" selected>Maintenance/', $page) === 1, 'once it exists the button is gone and it is selected');
$form = ['save_rmm_alert_ticket_settings' => '1', 'default_category' => (string) $mid, 'route_type' => ['check_prefix', 'severity'], 'route_value' => ['disk', 'critical'], 'route_category' => [(string) $net, (string) $sec], 'route_priority' => ['', 'High']];
$post($form, null, false);
$ok((int) $one('SELECT COUNT(*) FROM rmm_alert_ticket_routes') === 0, 'save without CSRF token changes nothing');
$post($form, $sidTech);
$ok((int) $one('SELECT COUNT(*) FROM rmm_alert_ticket_routes') === 0, 'save by a technician changes nothing');
$post($form);
$ok((int) $one('SELECT COUNT(*) FROM rmm_alert_ticket_routes') === 2 && (int) $one("SELECT category_id FROM rmm_alert_ticket_routes WHERE match_type='check_prefix' AND match_value='disk'") === $net && $one("SELECT priority FROM rmm_alert_ticket_routes WHERE match_value='critical'") === 'High', 'admin save stores the default and both rules');
$page = web($wb, 'GET', '/admin/settings_integrations.php?tab=rmm', $sidAdmin)[1];
$ok(substr_count($page, 'class="at-rule"') === 3 && str_contains($page, 'name="route_type[1]"'), 'the saved rules are drawn back (plus the hidden template row)');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Settings' AND log_description LIKE '%updated RMM alert ticket categories%check_prefix=disk%'") === 1, 'saving is audited with the rules');
foreach (['SQL' => ["route_type" => ["severity"], "route_value" => ["info' OR '1'='1"], "route_category" => [(string) $net]], 'bad category' => ['route_type' => ['severity'], 'route_value' => ['info'], 'route_category' => ['999999']], 'bad default' => ['default_category' => '-5', 'route_type' => [], 'route_value' => [], 'route_category' => []], 'xss' => ['route_type' => ['<img src=x>'], 'route_value' => ['x'], 'route_category' => ['1']]] as $l => $f) {
    $post(['save_rmm_alert_ticket_settings' => '1'] + $f + ['default_category' => '0']);
    $ok((int) $one('SELECT COUNT(*) FROM rmm_alert_ticket_routes') === 2 && (int) $one('SELECT config_rmm_alert_ticket_category_id FROM settings') === $mid, "hostile form ($l) is refused and the saved settings stay intact");
}
$post(['save_rmm_alert_ticket_settings' => '1', 'default_category' => '0', 'route_type' => 'x']);
$ok((int) $one('SELECT COUNT(*) FROM rmm_alert_ticket_routes') === 0 && $one('SELECT config_rmm_alert_ticket_category_id FROM settings') === null, 'a form with no rules and No category clears the settings (back to the old behaviour)');
$ok(str_contains((string) @file_get_contents($web['log']), 'Fatal') === false, 'no PHP fatal in the server log');
$q("DELETE FROM rmm_alert_ticket_routes"); $q("UPDATE settings SET config_rmm_alert_ticket_category_id = NULL");
echo "\n";
