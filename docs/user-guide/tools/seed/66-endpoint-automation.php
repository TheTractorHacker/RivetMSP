<?php
/*
 * Demo data for "RMM policies, scripts, schedules and checks" and the alert centre (Lakeshore Managed IT).
 * EXAMPLE DATA, NOT A LIVE CONNECTION. Needs 65-endpoint-agent.php (the devices).
 *
 * Installs the two starter packs (scripts, and policies) the way Starter pack / Policies > Install does, then turns a few
 * policies on and assigns them, adds tags, a group, a custom script with parameters (one secret) and an approval rule, a
 * schedule that waits for a second person's approval, a script run in the past, custom fields, an alert-escalation
 * policy, a maintenance window and alert-to-ticket routes.
 *
 * Idempotent: every item is looked up by name and skipped when it exists (the starter packs themselves are idempotent).
 */

$app = getenv('RIVETIT_APP_DIR') ?: (getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo');
chdir($app . '/scripts');
require_once $app . '/config.php';
require_once $app . '/functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once $app . '/includes/inc_set_timezone.php';
require_once $app . '/includes/rmm_bootstrap.php';

function one(string $sql): ?array
{
    global $mysqli;
    $r = mysqli_query($mysqli, $sql);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ?: null;
}
function q(string $sql)
{
    global $mysqli;
    $r = mysqli_query($mysqli, $sql);
    if ($r === false) {
        fwrite(STDERR, 'SQL error: ' . mysqli_error($mysqli) . "\n" . substr($sql, 0, 300) . "\n");
    }
    return $r;
}
function e($v): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $v);
}
function say(string $what, $r): void
{
    echo '66: ' . $what . ': ' . ($r->ok ? 'ok' : 'NOT OK ' . $r->message) . "\n";
}

$uid = (int) (one("SELECT user_id u FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'")['u'] ?? 0);
$uid2 = (int) (one("SELECT user_id u FROM users WHERE user_email = 'devon.park@lakeshoremsp.example'")['u'] ?? 0);
$uid3 = (int) (one("SELECT user_id u FROM users WHERE user_email = 'sam.ortiz@lakeshoremsp.example'")['u'] ?? 0);
if ($uid === 0 || !one("SELECT device_id FROM endpoint_agent_devices LIMIT 1")) {
    fwrite(STDERR, "66: run 65-endpoint-agent.php first\n");
    exit(1);
}
$rmm = rivetRmmModule($mysqli);
$who = rivetRmmPrincipal($uid, 'Morgan Reyes');
$cid = static fn (string $n): int => (int) (one("SELECT client_id c FROM clients WHERE client_name = '" . e($n) . "'")['c'] ?? 0);
$dev = static fn (string $h): int => (int) (one("SELECT device_id d FROM endpoint_agent_devices WHERE hostname = '" . e($h) . "'")['d'] ?? 0);

// 1. starter packs (every script, every policy draft)
say('script starter pack', $rmm->starterActions()->install($who, []));
say('policy starter pack', $rmm->policyPackActions()->run($who, 'install', []));

// 2. tags and a group
$tags = $rmm->tags();
foreach ([['Domain controller', '#6f42c1', 'Servers that answer sign-ins'], ['CAD workstation', '#0d6efd', 'Design PCs with heavy CAD software'], ['Plant floor', '#fd7e14', 'PCs on the production floor at Granite Peak']] as [$n, $c, $d]) {
    if (!$tags->findByName($n)) {
        $tags->create($n, $c, $d, $uid);
    }
}
foreach ([['SRV-GPM-DC01', 'Domain controller'], ['LT-CPA-31', 'CAD workstation'], ['WS-GPM-36', 'Plant floor'], ['WS-GPM-37', 'Plant floor']] as [$h, $t]) {
    if ($dev($h)) {
        try { $tags->assign($dev($h), $t, 'manual', $uid); } catch (\Throwable $ex) { /* already tagged */ }
    }
}
$groups = $rmm->groups();
if (!one("SELECT group_id FROM rmm_groups WHERE name = 'Pilot devices'")) {
    $g = $groups->create('Pilot devices', 'Devices that get changes first', $uid);
    foreach (['LT-CPA-31', 'LT-LFC-35', 'DT-NSD-33'] as $h) {
        if ($dev($h)) { $groups->addDevices((int) $g['group_id'], [$dev($h)]); }
    }
}

// 3. policies: turn on and assign the ones a small company would start with
$pol = $rmm->policyActions();
$pid = static fn (string $n): int => (int) (one("SELECT policy_id p FROM rmm_policies WHERE name = '" . e($n) . "'")['p'] ?? 0);
foreach ([['Pack: Windows workstation baseline', 'global', 0], ['Pack alerting: Workstation thresholds', 'global', 0], ['Pack: Windows server baseline', 'tag', (int) (one("SELECT tag_id t FROM rmm_tags WHERE name = 'Domain controller'")['t'] ?? 0)],
    ['Pack: Laptop add-on', 'global', 0]] as [$name, $scope, $sid]) {
    $id = $pid($name);
    if ($id === 0 || one("SELECT assignment_id FROM rmm_policy_assignments WHERE policy_id = $id")) {
        continue;
    }
    $pol->update($who, $id, ['enabled' => true]);
    $r = $pol->assign($who, $id, ['scope_type' => $scope, 'scope_id' => $sid, 'priority' => 100]);
    say("assign $name", $r);
}
// a client policy of our own
if (!$pid('Granite Peak plant floor: quiet hours')) {
    $r = $pol->create($who, ['name' => 'Granite Peak plant floor: quiet hours', 'description' => 'Slower reporting for the plant-floor PCs so shift work is not interrupted.',
        'settings' => ['interval.check_in_s' => ['mode' => 'override', 'value' => 600], 'interval.collect_s' => ['mode' => 'override', 'value' => 120]]]);
    say('create client policy', $r);
    $id = $pid('Granite Peak plant floor: quiet hours');
    if ($id) {
        $pol->update($who, $id, ['enabled' => true]);
        say('assign client policy', $pol->assign($who, $id, ['scope_type' => 'client', 'scope_id' => $cid('Granite Peak Manufacturing'), 'priority' => 200]));
    }
}

// 4. a custom library script with parameters (one secret) that needs a second person
$sa = $rmm->scriptActions();
if (!one("SELECT script_id FROM rmm_scripts_v2 WHERE name = 'Rotate local admin password'")) {
    say('custom script 1', $sa->createScript($who, ['name' => 'Rotate local admin password', 'description' => 'Sets a new password for the built-in support account. Needs a second person to approve.',
        'language' => 'powershell', 'tags' => ['security', 'example'], 'requires_approval' => true, 'timeout_s' => 120, 'note' => 'First version',
        'params' => [['name' => 'account', 'type' => 'string', 'required' => true, 'label' => 'Account name', 'default' => 'SupportAdmin'], ['name' => 'new_password', 'type' => 'secret', 'required' => true, 'label' => 'New password']],
        'body' => "param()\n\$user = \$env:RIVETIT_PARAM_account\n\$pw = ConvertTo-SecureString \$env:RIVETIT_PARAM_new_password -AsPlainText -Force\nSet-LocalUser -Name \$user -Password \$pw\nWrite-Output \"Password changed for \$user\"\n"]));
}
if (!one("SELECT script_id FROM rmm_scripts_v2 WHERE name = 'Disk cleanup (temp files)'")) {
    $r = $sa->createScript($who, ['name' => 'Disk cleanup (temp files)', 'description' => 'Deletes files older than a number of days from the Windows temp folders and reports the space freed.',
        'language' => 'powershell', 'tags' => ['cleanup', 'example'], 'timeout_s' => 600, 'note' => 'First version',
        'params' => [['name' => 'older_than_days', 'type' => 'int', 'required' => true, 'label' => 'Older than (days)', 'default' => 14, 'min' => 1, 'max' => 365]],
        'body' => "\$days = [int]\$env:RIVETIT_PARAM_older_than_days\n\$cut = (Get-Date).AddDays(-\$days)\nGet-ChildItem \$env:TEMP -Recurse -File -ErrorAction SilentlyContinue | Where-Object { \$_.LastWriteTime -lt \$cut } | Remove-Item -Force -ErrorAction SilentlyContinue\nWrite-Output 'Temp cleanup finished'\n"]);
    say('custom script 2', $r);
    if ($r->ok) {
        $sid = (int) $r->data['script']['script_id'];
        $sa->updateScript($who, $sid, ['body' => $r->data['script']['current']['body'] . "Get-PSDrive C | Select-Object Used,Free | Format-List | Out-String | Write-Output\n", 'note' => 'Also report free space']);
    }
}

// 6. custom fields
$fa = $rmm->fieldActions();
foreach ([['cost_center', 'Cost center', 'client', 'text', 'Finance cost center work for this client is billed to.'],
          ['bitlocker_key_stored', 'BitLocker key stored in vault', 'device', 'bool', 'Tick when the recovery key is stored in the password manager.'],
          ['support_admin_password', 'Support account password', 'device', 'secret', 'Stored encrypted; scripts reference it as a placeholder.'],
          ['support_tier', 'Support tier', 'client', 'list', 'How quickly the client expects help.']] as [$n, $l, $scope, $type, $d]) {
    if (one("SELECT field_id FROM rmm_custom_fields WHERE name = '" . e($n) . "'")) {
        continue;
    }
    $in = ['name' => $n, 'label' => $l, 'scope' => $scope, 'type' => $type, 'description' => $d];
    if ($type === 'list') { $in['options'] = ['Standard', 'Priority', 'Production-critical']; }
    say("field $n", $fa->define($who, $in));
}

// 5. schedules: one that runs, one waiting for a second person
$cleanId = (int) (one("SELECT script_id s FROM rmm_scripts_v2 WHERE name = 'Disk cleanup (temp files)'")['s'] ?? 0);
$rotId = (int) (one("SELECT script_id s FROM rmm_scripts_v2 WHERE name = 'Rotate local admin password'")['s'] ?? 0);
if ($cleanId && !one("SELECT schedule_id FROM rmm_schedules WHERE name = 'Weekly temp cleanup, Granite Peak'")) {
    say('schedule 1', $sa->saveSchedule($who, null, ['name' => 'Weekly temp cleanup, Granite Peak', 'library_script_id' => $cleanId, 'params' => ['older_than_days' => 14],
        'target' => ['type' => 'client', 'id' => $cid('Granite Peak Manufacturing')], 'kind' => 'cron', 'cron' => '0 8 * * 0', 'jitter_s' => 600, 'overlap' => 'skip', 'enabled' => true]));
}
if ($rotId && !one("SELECT schedule_id FROM rmm_schedules WHERE name = 'Monthly support account rotation'")) {
    say('schedule 2', $sa->saveSchedule($who, null, ['name' => 'Monthly support account rotation', 'library_script_id' => $rotId, 'params' => ['account' => 'SupportAdmin', 'new_password' => '{{field.support_admin_password}}'],
        'target' => ['type' => 'tag', 'id' => (int) (one("SELECT tag_id t FROM rmm_tags WHERE name = 'Plant floor'")['t'] ?? 0)], 'kind' => 'cron', 'cron' => '0 6 1 * *', 'enabled' => true]));
}

// 7. alert escalation policy and a maintenance window
$al = $rmm->alertingActions();
if (!one("SELECT policy_id FROM rmm_escalation_policies WHERE name = 'Critical alerts: technician, then manager'")) {
    say('escalation', $al->createPolicy($who, ['name' => 'Critical alerts: technician, then manager', 'min_severity' => 'crit', 'repeat_every_min' => 60, 'repeat_max' => 3,
        'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => (string) $uid2]]], ['after_min' => 30, 'targets' => [['type' => 'user', 'ref' => (string) $uid]]]]]));
}
if (!one("SELECT window_id FROM rmm_maintenance_windows WHERE name = 'Patch Tuesday reboots'")) {
    say('maintenance window', $al->createWindow($who, ['name' => 'Patch Tuesday reboots', 'mode' => 'mute', 'scope_type' => 'all', 'kind' => 'recurring', 'timezone' => 'America/Chicago',
        'recur_freq' => 'weekly', 'recur_interval' => 1, 'recur_days' => '2', 'local_start' => '22:00', 'duration_min' => 120, 'note' => 'Devices restart after patching; mute noise during the window.', 'enabled' => true]));
}

// 8. alert-to-ticket routing (critical alerts become High priority tickets in the first category)
$cat = one("SELECT category_id c FROM categories WHERE category_type = 'ticket' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1");
if ($cat && !one("SELECT id FROM rmm_alert_ticket_routes WHERE match_type = 'severity' AND match_value = 'critical'")) {
    q("INSERT INTO rmm_alert_ticket_routes SET match_type = 'severity', match_value = 'critical', category_id = " . (int) $cat['c'] . ", priority = 'High', sort = 1");
}

$rmm->cache()->invalidate('fleet', true);
echo "66-endpoint-automation: done\n";
