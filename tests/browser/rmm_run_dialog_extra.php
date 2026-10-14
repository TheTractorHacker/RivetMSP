<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fixtures for tests/browser/rmm_run_dialog_smoke.mjs, run on the same throwaway install after tests/browser/rmm_seed.php (the scratch guard of tests/endpoint_agent_lib.php applies):
 *   php tests/browser/rmm_run_dialog_extra.php <seed.json> fixtures     library scripts of every risk class for Windows and Linux (through Core's API as user 1)
 *   php tests/browser/rmm_run_dialog_extra.php <seed.json> legacy       three hand-made older scripts and a vendor RMM integration (the synced tab and the import)
 *   php tests/browser/rmm_run_dialog_extra.php <seed.json> job <name>   the newest job of the library script <name>: type, state and params as stored
 *   php tests/browser/rmm_run_dialog_extra.php <seed.json> retire_linux | restore_linux
 * Prints one JSON line.
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_automation.php';

$mode = $argv[2] ?? 'fixtures';
$api = fn(string $m, array $seg, array $qs = [], ?array $b = null) => rivetRmmAutoApi($db, 1, 'admin', $m, $seg, $qs, $b);
$out = [];
if ($mode === 'fixtures') {
    $mk = function (string $name, string $language, string $risk, array $params = [], array $extra = []) use ($api): int {
        $r = $api('POST', ['scripts'], [], ['name' => $name, 'language' => $language, 'body' => $language === 'powershell' ? "Write-Output 'x'" : 'echo x', 'params' => $params,
            'tags' => $risk === '' ? ['mine'] : ['risk:' . $risk, 'maintenance'], 'description' => 'Smoke: ' . $name] + $extra);
        return (int) ($r['data']['script']['script_id'] ?? 0);
    };
    $dry = ['name' => 'dry_run', 'type' => 'bool', 'default' => true, 'label' => 'Dry run (only report)'];
    $age = ['name' => 'age_days', 'type' => 'int', 'label' => 'Minimum age in days', 'default' => 7, 'min' => 1, 'max' => 365];
    $out['read'] = $mk('Smoke win disk report', 'powershell', 'read_only', [['name' => 'drive', 'type' => 'string', 'label' => 'Drive letter', 'required' => true, 'max_length' => 3], $age, ['name' => 'token', 'type' => 'secret', 'label' => 'Access token'], $dry]);
    $out['clean'] = $mk('Smoke win clean temp files', 'powershell', 'changes_system', [$age, $dry]);
    $out['approve'] = $mk('Smoke win rebuild profile', 'powershell', 'disruptive', [], ['requires_approval' => true, 'destructive' => true]);
    $out['destr'] = $mk('Smoke win wipe cache', 'powershell', 'changes_system', [], ['destructive' => true]);
    $out['mine'] = $mk('Smoke win own script', 'powershell', '');
    $out['lread'] = $mk('Smoke lin journal report', 'bash', 'read_only', [$dry]);
    $out['lclean'] = $mk('Smoke lin package cache clean', 'bash', 'changes_system', [$age, $dry]);
    $q("DELETE FROM rmm_scripts");
    $out['count'] = (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2");
} elseif ($mode === 'legacy') {
    $q("DELETE FROM rmm_integrations");
    $q("INSERT INTO rmm_integrations SET id=7, name='Acme RMM', type='tactical_rmm', api_url='https://rmm.invalid', api_key_enc='', enabled=1");
    foreach ([['Fix printer queue', 'Repair', 'powershell', "Restart-Service Spooler\r\nGet-Printer", null], ['Disk report (older)', 'Inventory', 'powershell', 'Get-PSDrive', null], ['Nightly bash tidy', 'Maintenance', 'bash', 'echo tidy', null],
        ['Synced from vendor', 'Repair', 'powershell', 'Get-Process', 4321]] as [$n, $c, $t, $b, $tac]) {
        $q("INSERT INTO rmm_scripts SET name='" . $esc($n) . "', category='$c', description='Older script', script_type='$t', script_body='" . $esc($b) . "', tactical_script_id=" . ($tac ?? 'NULL') . ', rmm_integration_id=' . ($tac ? 7 : 0) . ', enabled=1, created_by=1');
    }
    $out['rows'] = (int) $one('SELECT COUNT(*) FROM rmm_scripts');
} elseif ($mode === 'job') {
    $name = (string) ($argv[3] ?? '');
    $row = $db->query("SELECT j.type, j.state, j.params_json, j.destructive FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id JOIN rmm_scripts_v2 s ON s.script_id=x.script_id WHERE s.name='" . $esc($name) . "' ORDER BY j.created_at DESC LIMIT 1")->fetch_assoc();
    $out = ['job' => $row ? ['type' => $row['type'], 'state' => $row['state'], 'params' => json_decode((string) $row['params_json'], true), 'destructive' => (int) $row['destructive']] : null,
        'approvals' => (int) $one("SELECT COUNT(*) FROM rmm_approvals a JOIN rmm_scripts_v2 s ON s.script_id=a.script_id WHERE s.name='" . $esc($name) . "' AND a.state='pending_approval'")];
} elseif ($mode === 'retire_linux' || $mode === 'restore_linux') {
    $q("UPDATE rmm_scripts_v2 SET retired_at=" . ($mode === 'retire_linux' ? 'NOW()' : 'NULL') . " WHERE platform='linux'");
    $out['ok'] = true;
}
echo json_encode($out) . "\n";
