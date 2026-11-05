<?php
/*
 * Demo data for "Remote access (MeshCentral and RivetPanel)". EXAMPLE DATA, NOT A LIVE CONNECTION: no MeshCentral server or RivetPanel helper exists here.
 * Needs 65 (devices). Idempotent.
 *   - MeshCentral settings (an https .example address, a made-up login-token key, a made-up device group id), four devices
 *     mapped to Mesh node ids, a few past remote-session rows for the audit list;
 *   - RivetPanel switched on, a pending token and one enrolled panel device for Granite Peak Manufacturing.
 */

$app = getenv('RIVETIT_APP_DIR') ?: (getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo');
chdir($app . '/scripts');
require_once $app . '/config.php';
require_once $app . '/functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once $app . '/includes/inc_set_timezone.php';
require_once $app . '/includes/rmm_bootstrap.php';
require_once $app . '/includes/panel_bootstrap.php';

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
function s($v): string
{
    return $v === null ? 'NULL' : "'" . e($v) . "'";
}

$uid = (int) (one("SELECT user_id u FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'")['u'] ?? 0);
$uid2 = (int) (one("SELECT user_id u FROM users WHERE user_email = 'devon.park@lakeshoremsp.example'")['u'] ?? 0);
if ($uid === 0 || !one("SELECT device_id FROM endpoint_agent_devices LIMIT 1")) {
    fwrite(STDERR, "68: run 65 first\n");
    exit(1);
}
$rmm = rivetRmmModule($mysqli);
$who = rivetRmmPrincipal($uid, 'Morgan Reyes');
$dev = static fn (string $h): ?array => one("SELECT * FROM endpoint_agent_devices WHERE hostname = '" . e($h) . "'");

// ------------------------------------------------------------------ MeshCentral
$meshKey = hash('sha256', 'lakeshore-demo-mesh-login-token-key-not-real');                       // 64 hex characters, made up
$meshGroup = substr(strtr(base64_encode(hash('sha384', 'lakeshore-demo-mesh-group', true)), '+/', '@$'), 0, 64);   // 64 characters of letters, digits, @ and $
$r = $rmm->admin()->saveMesh($who, ['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.lakeshoremsp.example', 'mesh_domain' => '', 'mesh_account_template' => 'rivetit-{user_id}',
    'mesh_policy' => 'unattended', 'mesh_token_ttl_s' => 300, 'mesh_login_key' => $meshKey, 'mesh_group_id' => $meshGroup]);
echo '68: mesh settings: ' . ($r->ok ? 'ok' : 'NOT OK ' . $r->message) . "\n";
foreach (['LT-CPA-31', 'LT-LFC-35', 'SRV-GPM-DC01', 'WS-GPM-36'] as $h) {
    $d = $dev($h);
    if ($d && !one("SELECT 1 FROM endpoint_agent_mesh_nodes WHERE device_id = " . (int) $d['device_id'])) {
        $node = 'node//' . substr(strtr(base64_encode(hash('sha384', 'node-' . $h, true)), '+/', '@$'), 0, 64);
        $x = $rmm->technician()->setMeshNode($who, (int) $d['device_id'], $node);
        echo "68: mesh node $h: " . ($x->ok ? 'ok' : 'NOT OK ' . $x->message) . "\n";
    }
}
// past remote sessions (what Remote sessions / the audit list shows)
$i = 0;
foreach ([['LT-CPA-31', $uid2, 'desktop', 190], ['SRV-GPM-DC01', $uid, 'desktop', 1500], ['LT-LFC-35', $uid2, 'desktop', 3100]] as [$h, $by, $view, $min]) {
    $i++;
    $d = $dev($h);
    if ($d && !one("SELECT id FROM rmm_remote_sessions WHERE user_agent LIKE 'demo-agent-session-$i %'")) {
        q("INSERT INTO rmm_remote_sessions SET asset_id = " . (int) $d['asset_id'] . ", client_id = " . (int) $d['client_id'] . ", user_id = $by, connection_type = 'meshcentral',
            connection_url = " . s('meshcentral:session:demo' . $i) . ", source_ip = '10.0.20.5', user_agent = " . s("demo-agent-session-$i Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0") . ",
            created_at = NOW() - INTERVAL $min MINUTE");
    }
}

// ------------------------------------------------------------------ RivetPanel
q("UPDATE settings SET config_core_panel_enabled = 1 WHERE company_id = 1");
$cidProd = (int) (one("SELECT client_id c FROM clients WHERE client_name = 'Granite Peak Manufacturing'")['c'] ?? 0);
if ($cidProd && !one("SELECT token_id FROM endpoint_agent_enrollment_tokens WHERE kind = 'panel' LIMIT 1")) {
    $panel = rivetPanelModule($mysqli);
    $t = $panel->enrollment()->createToken($cidProd, null, 72, 'Granite Peak front-office PCs', $uid, 10);
    $t2 = $panel->enrollment()->createToken($cidProd, null, 24, 'Plant supervisor PC', $uid, 1);
    try {
        $panel->enrollment()->enroll(['enrollment_token' => $t2['token'], 'device' => ['install_id' => '5b0f7a68-2d3e-4c11-9a6e-3f7d2c1b9a01', 'hostname' => 'FRONTDESK-GPM-01', 'os' => 'windows', 'os_version' => 'Windows 11 Pro 23H2',
            'arch' => 'amd64', 'agent_version' => '1.0.3']], '10.30.1.36');
        echo "68: panel device enrolled\n";
    } catch (\Throwable $ex) {
        fwrite(STDERR, '68: panel enroll: ' . $ex->getMessage() . "\n");
    }
}


$rmm->cache()->invalidate('fleet', true);
echo "68-endpoint-remote-panel: done\n";
