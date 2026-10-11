<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fragment of tests/rmm_ui.php (RivetCore 1.0.0-rc.12, the optional MeshCentral agent, migration 0023): the install settings on Administration > Endpoint agent (group id, certificate
 * pin and the "read it from the server" button that never saves, the overwrite switch, the advanced corrections), the device's Mesh agent state in the Remote card (installed,
 * failed, mapped, conflict, kept_manual) driven by REAL check-ins, the events in the shared picker, and the module-off path. Shares that file's variables.
 *
 * Everything is driven through the product: the real admin handler (admin/post.php, CSRF, Referer dispatch) over HTTP, the real pages, the real check-in endpoint. The pin probe talks
 * to a local TLS server (tests/support/MockTlsServer.php). There is NO real MeshCentral server behind any of this (see docs/RMM_MODULE.md): what is proven is RivetMSP's side.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;

require_once "$root/includes/rmm_mesh_admin.php";
require_once "$root/includes/rmm_scale_admin.php";
require_once "$root/tests/support/MockTlsServer.php";

$mGroup = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
$mNodeA = 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01';
$mNodeB = 'node//ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210@$ZyXwVuTsRqPoNmLkJiHgFeDcBa98';
$mNodeC = 'node//QqWwEeRrTtYyUuIiOoPpAaSsDdFfGgHhJjKk1234@$QqWwEeRrTtYyUuIiOoPpAaSsDdFfGgHhJjKk';
$mPg = fn (string $who = 'admin') => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS[$who]);
$mAdm = fn (array $f, string $who = 'admin') => web($wb, 'POST', '/admin/post.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $REF1);
$mCfg = fn () => $rmm->meshAgentSettings();
$mReset = function () use ($q, $rmm, $fresh) {
    $q('DELETE FROM endpoint_agent_mesh_settings');
    $q('DELETE FROM endpoint_agent_mesh_agents');
    $q('DELETE FROM endpoint_agent_installer_options');
    $q('DELETE FROM endpoint_agent_mesh_nodes');
    $rmm->meshAgentSettings()->refresh();
    $rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test']);
    $fresh();
};
$mReset();
$mAudit = fn (string $action) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($action) . "'");
/** The message a card shows once after a save (empty when none). */
$mMsg = fn (string $body): string => preg_match('/data-rmm-scale-msg>(.*?)<\/div>/s', $body, $m) === 1 ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES)) : '';

// ============================================================ migration 0023 through the Core runner: tables there, nothing configured, the tick-box refused
foreach (['endpoint_agent_mesh_settings', 'endpoint_agent_mesh_agents', 'endpoint_agent_installer_options'] as $t) {
    $ok($one("SHOW TABLES LIKE '$t'") === $t, "table $t exists");
}
$ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id = '0023_rmm_mesh_agent'") === 1, 'migration 0023 is recorded once');
$ok($mCfg()->get()['group_id'] === '' && $mCfg()->get()['server_cert_pin'] === '' && $mCfg()->get()['auto_map_overwrite'] === false, 'nothing is configured by default: no group id, no pin, overwrite off');
$ok($rmm->meshAgents()->installRefusal() !== null && str_contains((string) $rmm->meshAgents()->installRefusal(), 'device group id'), 'Core refuses the installer option until a group id is set (its sentence: ' . $rmm->meshAgents()->installRefusal() . ')');

// ============================================================ the Mesh card
[$c, $body] = $mPg();
$ok($c === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:') && !str_contains($body, 'Deprecated:'), 'Administration loads with the Mesh install fields and no PHP notice (' . $c . ')');
$ok(str_contains($body, 'name="mesh_group_id"') && preg_match('/<input type="password"[^>]*id="mc_group"/', $body) === 1, 'the device group id field is a masked password input (it is a secret)');
$ok(str_contains($body, 'name="mesh_server_cert_pin"') && str_contains($body, 'name="mesh_auto_map_overwrite"') && str_contains($body, 'name="mesh_agent_ids"') && str_contains($body, 'name="mesh_install_args"') && str_contains($body, 'name="mesh_install_fields"'), 'pin, overwrite switch, the two corrections and the marker field are on the form');
$ok(!preg_match('/name="mesh_auto_map_overwrite"[^>]*checked/', $body), 'the overwrite switch is off by default');
$ok(str_contains($body, 'id="mesh-pin-probe"') && preg_match('/<button type="submit" form="mesh-pin-probe"/', $body) === 1 && str_contains($body, 'Read it from the server'), 'the "Read it from the server" button belongs to its own form (forms cannot nest) and the page has that form');
$ok(str_contains($body, 'were not verified against a real server') && str_contains($body, 'Verify these against your own Mesh server') && str_contains($body, '<details class="rmm-mesh-advanced"'), 'the advanced section carries Core\'s "not verified" note and our verify warning');
$ok(str_contains($body, '/js/rmm_mesh_admin.js') && str_contains($body, 'Not ready'), 'the page links the Mesh script and says the install option is not ready yet');
preg_match('/id="mesh">(.*?)<script/s', $body, $mcard);
$ok(isset($mcard[1]) && !preg_match('/\son[a-z]+="/', $mcard[1]), 'CSP: no inline event handlers in the Mesh card');
$ok(preg_match('/<label class="form-label" for="mc_group">/', $body) === 1 && preg_match('/<label class="form-label" for="mc_pin">/', $body) === 1 && preg_match('/<label class="form-check-label" for="mc_over">/', $body) === 1, 'accessible: the three fields have labels');

// ============================================================ saving the install settings
$full = ['save_mesh_settings' => 1, 'mesh_install_fields' => '1', 'mesh_enabled' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_account_template' => 'rivetit-support', 'mesh_policy' => 'unattended',
    'mesh_token_ttl_s' => '300', 'mesh_login_key' => ''];
$mMeshForm = 'mesh//' . strtr(base64_encode((string) hex2bin($mGroup)), ['+' => '@', '/' => '$']);   // MeshCentral's own form of the same id
$mAdm($full + ['mesh_group_id' => $mMeshForm, 'mesh_server_cert_pin' => strtoupper(hash('sha384', 'pin-one')), 'mesh_auto_map_overwrite' => '1']);
$mCfg()->refresh();
$g = $mCfg()->get();
$ok($g['group_id'] === $mGroup && $g['server_cert_pin'] === hash('sha384', 'pin-one') && $g['auto_map_overwrite'] === true, 'saved: the mesh//... form is converted to the hex id, the pin is stored normalised, the overwrite switch is on');
$ok($rmm->meshAgents()->installRefusal() === null, 'with Mesh on, an https address and a group id Core accepts the installer option');
[$c, $body] = $mPg();
$ok(str_contains($body, 'value="' . $mGroup . '"') && str_contains($body, 'value="' . hash('sha384', 'pin-one') . '"') && preg_match('/name="mesh_auto_map_overwrite"[^>]*checked/', $body) === 1 && str_contains($body, 'Installers can include the MeshCentral agent'), 'the page shows the stored values and says installers can include the agent');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%edited the MeshCentral settings%Mesh agent install settings (mesh_group_id%'") >= 1, 'the change is audited by Core (the activity log names the Mesh agent install settings)');
$ok(!str_contains((string) $one("SELECT GROUP_CONCAT(log_description) FROM logs WHERE log_description LIKE '%MeshCentral settings%'"), $mGroup), 'and the audit text never holds the device group id');

// a form without the marker (an older form, a script) changes none of them, and an unticked switch with the marker switches it off
$noMarker = $full; unset($noMarker['mesh_install_fields']);
$mAdm($noMarker + ['mesh_group_id' => 'c' . substr($mGroup, 1), 'mesh_server_cert_pin' => '', 'mesh_auto_map_overwrite' => '']);
$mCfg()->refresh();
$ok($mCfg()->get()['group_id'] === $mGroup && $mCfg()->get()['server_cert_pin'] === hash('sha384', 'pin-one') && $mCfg()->get()['auto_map_overwrite'] === true, 'a form without mesh_install_fields leaves the install settings alone');
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => hash('sha384', 'pin-one')]);
$mCfg()->refresh();
$ok($mCfg()->get()['auto_map_overwrite'] === false && $mCfg()->get()['group_id'] === $mGroup, 'with the marker an unticked overwrite switch is saved as off');

// refusals: nothing written, Core's text in the card, what was typed put back
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => 'not-a-hash', 'mesh_auto_map_overwrite' => '1']);
[$c, $body] = $mPg();
$ok($mCfg()->get()['server_cert_pin'] === hash('sha384', 'pin-one') && $mCfg()->get()['auto_map_overwrite'] === false, 'a bad pin writes nothing at all (the overwrite switch in the same form stays as it was)');
$ok(str_contains($mMsg($body), 'The certificate pin must be the SHA-384') && str_contains($body, 'value="not-a-hash"') && preg_match('/name="mesh_auto_map_overwrite"[^>]*checked/', $body) === 1, 'Core\'s refusal text is shown in the card and what the person typed is put back');
[$c, $body] = $mPg();
$ok($mMsg($body) === '' && str_contains($body, 'value="' . hash('sha384', 'pin-one') . '"'), 'the refusal is shown once; the next load shows the stored values');
$mAdm($full + ['mesh_group_id' => 'xyz not hex', 'mesh_server_cert_pin' => '']);
[$c, $body] = $mPg();
$ok(str_contains($mMsg($body), 'device group id') && $mCfg()->get()['group_id'] === $mGroup, 'a bad group id is refused with Core\'s sentence and nothing is written');
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_agent_ids' => '{not json']);
[$c, $body] = $mPg();
$ok(str_contains($mMsg($body), 'must be a JSON object') && str_contains($body, '{not json'), 'invalid JSON in a correction box is refused before Core is asked, and the text stays in the box');
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_agent_ids' => '{"windows/amd64": 999}']);
[$c, $body] = $mPg();
$ok(str_contains($mMsg($body), 'agent ids between 1 and 255') && $mCfg()->get()['agent_ids'] === [], 'an out-of-range agent id is refused by Core');

// the corrections
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_agent_ids' => '{"windows/arm64": 40}', 'mesh_install_args' => '{"linux": ["-install", "--installPath=/opt/mesh"]}']);
$mCfg()->refresh();
$ok($mCfg()->get()['agent_ids'] === ['windows/arm64' => 40] && $mCfg()->get()['install_args'] === ['linux' => ['-install', '--installPath=/opt/mesh']] && $mCfg()->get()['server_cert_pin'] === '', 'the corrections are saved as JSON, and an empty pin clears the pin');
[$c, $body] = $mPg();
$ok(str_contains($body, 'rmm-mesh-advanced" data-rmm-mesh-advanced open') && str_contains($body, '&quot;windows/arm64&quot;: 40'), 'with corrections stored the advanced section opens and shows them');
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_agent_ids' => '', 'mesh_install_args' => '']);
$mCfg()->refresh();
$ok($mCfg()->get()['agent_ids'] === [] && $mCfg()->get()['install_args'] === [], 'empty boxes clear the corrections (the built-in tables apply again)');

// only an administrator can post, and CSRF
[$c] = $mAdm($full + ['mesh_group_id' => 'a' . substr($mGroup, 1)], 'tech');
$mCfg()->refresh();
$ok($mCfg()->get()['group_id'] === $mGroup, 'a technician cannot change the install settings (' . $c . ')');
[$c] = web($wb, 'POST', '/admin/post.php', $SS['admin'], $full + ['csrf_token' => 'wrong', 'mesh_group_id' => 'b' . substr($mGroup, 1)], $REF1);
$mCfg()->refresh();
$ok($mCfg()->get()['group_id'] === $mGroup, 'a wrong CSRF token changes nothing');
[$c] = web($wb, 'POST', '/admin/post.php', $SS['admin'], ['probe_mesh_pin' => '1', 'csrf_token' => 'wrong'], $REF1);
[, $body] = $mPg();
$ok(!str_contains($body, 'data-rmm-mesh-pin-result'), 'a probe with a wrong CSRF token does nothing');

// ============================================================ "Read it from the server": proposes, never saves
$tls = new MockTlsServer();
$rmm->settings()->set(['mesh_url' => $tls->url()]);
$fresh();
$sBefore = json_encode($mCfg()->get());
$settingsRowBefore = json_encode($rows('SELECT * FROM endpoint_agent_mesh_settings'));
$mAdm(['probe_mesh_pin' => '1']);
[$c, $body] = $mPg();
$ok(str_contains($body, 'data-rmm-mesh-pin-result="ok"') && str_contains($body, $tls->sha384) && str_contains($body, $tls->sha256) && str_contains($body, 'Nothing has been saved') && str_contains($body, 'mesh.test.invalid'), 'the probe shows the SHA-384, the SHA-256 and the subject of the server\'s certificate, and says nothing was saved');
$ok(str_contains($body, 'data-rmm-mesh-use-pin="' . $tls->sha384 . '"') && str_contains($body, 'Put this hash in the pin field'), 'a button offers to put the hash in the field (a script does it; it does not submit)');
$mCfg()->refresh();
$ok(json_encode($mCfg()->get()) === $sBefore && json_encode($rows('SELECT * FROM endpoint_agent_mesh_settings')) === $settingsRowBefore, 'NOTHING was saved by the probe: the stored pin and the whole settings row are unchanged');
$ok(!preg_match('/id="mc_pin"[^>]*value="' . $tls->sha384 . '"/', $body), 'and the pin field is not pre-filled with the proposal');
[$c, $body] = $mPg();
$ok(!str_contains($body, 'data-rmm-mesh-pin-result') , 'the proposal is shown once');
// a failure is a sentence, not an error page
$rmm->settings()->set(['mesh_url' => 'https://127.0.0.1:9']);
$fresh();
$mAdm(['probe_mesh_pin' => '1']);
[$c, $body] = $mPg();
$ok($c === 200 && str_contains($body, 'data-rmm-mesh-pin-result="error"'), 'an unreachable server is a message in the card');
$rmm->settings()->set(['mesh_url' => '']);
$fresh();
[$c, $body] = $mPg();
$ok(preg_match('/<button type="submit" form="mesh-pin-probe"[^>]*disabled/', $body) === 1 && str_contains($body, 'Save the MeshCentral address first'), 'with no address saved the button is disabled and says why');
$mAdm(['probe_mesh_pin' => '1']);
[$c, $body] = $mPg();
$ok(str_contains($body, 'data-rmm-mesh-pin-result="error"') && str_contains($body, 'Save the MeshCentral address first'), 'a forged probe without an address gets Core\'s sentence');
[$c, $body] = $mPg('tech');
$ok(!str_contains($body, 'id="mesh-pin-probe"'), 'a technician is not shown the administration page\'s probe form (' . $c . ')');
$rmm->settings()->set(['mesh_url' => 'https://mesh.example.test']);
$fresh();
$tls->stop();

// ============================================================ the device's Mesh agent state, from real check-ins
$mDev = []; $mTok = []; $mAsset = [];
foreach (['MA1' => [1, 'A'], 'MA2' => [1, 'A'], 'MA3' => [1, 'A'], 'MA4' => [1, 'A'], 'MA5' => [1, 'A'], 'MB1' => [2, 'B']] as $n => [$client, $tk]) {
    $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
    $mAsset[$n] = (int) $db->insert_id;
    [$c, , $j] = ea_enroll($S['enroll'][$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n]));
    $mDev[$n] = (int) ($j['device_id'] ?? 0);
    $mTok[$n] = (string) ($j['device_token'] ?? '');
    $ok($c === 201 && $mDev[$n] > 0, "$n enrolled");
    ea_checkin($mTok[$n], ['capabilities' => ['job:collect']]);
}
$A += $mAsset;
$mPage = fn (string $who, string $n) => web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $mAsset[$n], $SS[$who]);
$mReport = fn (string $n, $report) => ea_checkin($mTok[$n], ['capabilities' => ['job:collect', 'mesh:nodeid'], 'mesh_agent' => $report]);
$mEvents = function () use ($db) { $o = []; foreach ($db->query("SELECT payload FROM integration_jobs WHERE job_type='webhook.deliver' ORDER BY job_id") as $r) { $p = json_decode($r['payload'], true); $o[] = $p['event'] ?? ''; } return $o; };
$q("INSERT INTO webhooks SET webhook_name='mesh events', webhook_url='http://127.0.0.1:9/mesh', webhook_secret='', webhook_events='rmm.mesh.*', webhook_enabled=1");
$evBefore = count(array_filter($mEvents(), fn ($e) => str_starts_with($e, 'rmm.mesh.')));

// a device that never reported: nothing about Mesh agents is drawn
[$c, $body] = $mPage('admin', 'MA1');
$ok($c === 200 && !str_contains($body, 'data-rmm-mesh-agent') && str_contains($body, 'id="rmm-remote"'), 'a device that never reported a Mesh agent: the Remote card has no Mesh agent block');
$ok(!array_key_exists('mesh_agent', $rmm->readModel()->deviceView($mDev['MA1'], false, 5) ?? []), 'and Core\'s device view has no mesh_agent member for it');

// installed and mapped
[$c] = $mReport('MA1', ['installed' => true, 'version' => '1.1.60', 'node_id' => $mNodeA]);
$ok($c === 200, 'check-in with a Mesh agent report: 200');
[$c, $body] = $mPage('admin', 'MA1');
$ok(str_contains($body, 'data-rmm-mesh-agent="installed"') && str_contains($body, 'data-rmm-mesh-map="mapped"') && str_contains($body, 'MeshCentral agent installed') && str_contains($body, 'version 1.1.60'), 'MA1 (administrator): installed, version, mapped');
$ok(str_contains($body, htmlspecialchars($mNodeA, ENT_QUOTES)) && str_contains($body, 'mapped to this device'), 'the administrator sees the node id that was mapped');
$mapRow = $rows('SELECT mesh_node_id, source FROM endpoint_agent_mesh_nodes WHERE device_id = ' . $mDev['MA1']);
$ok(count($mapRow) === 1 && $mapRow[0]['mesh_node_id'] === $mNodeA && $mapRow[0]['source'] === 'agent', 'the node was mapped automatically (source agent)');
[$c, $body] = $mPage('tech', 'MA1');
$ok($c === 200 && str_contains($body, 'data-rmm-mesh-agent="installed"') && !str_contains($body, htmlspecialchars($mNodeA, ENT_QUOTES)), 'a technician sees the state but never the node id');
$ok($mAudit('Mesh Agent Installed') >= 1 && $mAudit('Mesh Node Mapped') >= 1, 'audit: Mesh Agent Installed and Mesh Node Mapped');
[$c, $body] = $mPage('admin', 'MA1');
$ok(preg_match('/id="rmm-mesh-node"[^>]*value="' . preg_quote(htmlspecialchars($mNodeA, ENT_QUOTES), '/') . '"/', $body) === 1, 'the existing mapping form shows the automatically found node');

// a failed install
[$c] = $mReport('MA2', ['installed' => false, 'error' => 'download failed: <script>alert(1)</script> certificate mismatch']);
[$c, $body] = $mPage('admin', 'MA2');
$ok(str_contains($body, 'data-rmm-mesh-agent="not_installed"') && str_contains($body, 'MeshCentral agent not installed') && str_contains($body, 'The installer could not install it') && str_contains($body, 'certificate mismatch'), 'MA2: the install error is shown with a plain sentence');
$ok(!str_contains($body, '<script>alert(1)</script>') && $one('SELECT COUNT(*) FROM endpoint_agent_mesh_nodes WHERE device_id = ' . $mDev['MA2']) === '0', 'the error text is escaped and nothing was mapped');
$ok($mAudit('Mesh Agent Install Failed') >= 1, 'audit: Mesh Agent Install Failed');

// conflict: the same node on another live device of the same client, and on a device of another client
[$c] = $mReport('MA3', ['installed' => true, 'version' => '1.1.60', 'node_id' => $mNodeA]);
[$c, $body] = $mPage('admin', 'MA3');
$ok(str_contains($body, 'data-rmm-mesh-hint="conflict"') && str_contains($body, 'role="alert"') && str_contains($body, 'Not mapped.') && str_contains($body, 'device ' . $mDev['MA1']) && str_contains($body, 'rmm_agent_device.php?device_id=' . $mDev['MA1']), 'MA3 (same client as the owner): a visible conflict hint that names the other device');
$ok($one('SELECT COUNT(*) FROM endpoint_agent_mesh_nodes WHERE device_id = ' . $mDev['MA3']) === '0' && (int) $one('SELECT device_id FROM endpoint_agent_mesh_nodes WHERE mesh_node_id = "' . $esc($mNodeA) . '"') === $mDev['MA1'], 'nothing moved: the node is still on MA1');
[$c] = $mReport('MB1', ['installed' => true, 'version' => '1.1.60', 'node_id' => $mNodeA]);
[$c, $body] = $mPage('admin', 'MB1');
$ok(str_contains($body, 'data-rmm-mesh-hint="conflict"') && !str_contains($body, 'device_id=' . $mDev['MA1']) && str_contains($body, 'another device'), 'MB1 (another client): the conflict is flagged without naming a device of a client it does not belong to');
$ok($mAudit('Mesh Node Conflict') === 2, 'audit: one Mesh Node Conflict row per conflict, not one per report (' . $mAudit('Mesh Node Conflict') . ')');
$mReport('MA3', ['installed' => true, 'version' => '1.1.60', 'node_id' => $mNodeA]);
$ok($mAudit('Mesh Node Conflict') === 2, 'a repeated report does not write another conflict row');
$conflictBody = $mPage('clientb', 'MB1')[1];
$ok(!str_contains($conflictBody, 'device_id=' . $mDev['MA1']), 'a user limited to Client B never gets a link to a Client A device');

// kept_manual: a node mapped by hand wins until the overwrite switch is on
$rmm->technician()->setMeshNode($admin, $mDev['MA4'], $mNodeB);
$mReport('MA4', ['installed' => true, 'version' => '1.1.60', 'node_id' => $mNodeC]);
[$c, $body] = $mPage('admin', 'MA4');
$ok(str_contains($body, 'data-rmm-mesh-hint="kept_manual"') && str_contains($body, 'Your mapping was kept') && str_contains($body, 'Administration &gt; Endpoint agent &gt; MeshCentral'), 'MA4 (administrator): the manual mapping was kept, with the way to change it');
$ok((string) $one('SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id = ' . $mDev['MA4']) === $mNodeB, 'the node mapped by hand is still there');
[$c, $body] = $mPage('tech', 'MA4');
$ok(str_contains($body, 'data-rmm-mesh-hint="kept_manual"') && str_contains($body, 'An administrator can change the mapping') && !str_contains($body, 'Administration &gt; Endpoint agent &gt; MeshCentral') && !str_contains($body, htmlspecialchars($mNodeC, ENT_QUOTES)), 'a technician is told to ask an administrator and sees no node id');
$ok($mAudit('Mesh Node Differs') === 1, 'audit: Mesh Node Differs once');
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_auto_map_overwrite' => '1']);
$mCfg()->refresh();
$ok($mCfg()->get()['auto_map_overwrite'] === true, 'the overwrite switch turned on from the page');
$mReport('MA4', ['installed' => true, 'version' => '1.1.61', 'node_id' => $mNodeC]);
[$c, $body] = $mPage('admin', 'MA4');
$ok(str_contains($body, 'data-rmm-mesh-map="mapped"') && !str_contains($body, 'data-rmm-mesh-hint') && (string) $one('SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id = ' . $mDev['MA4']) === $mNodeC, 'with the switch on the reported node replaces the manual one and the hint is gone');
$ok($mAudit('Mesh Node Mapped') >= 2, 'audit: Mesh Node Mapped again for the replacement');
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '']);
$mCfg()->refresh();
$ok($mCfg()->get()['auto_map_overwrite'] === false, 'and the switch turned off again');

// the device page of a device that is not linked to an asset yet (it keeps agent/rmm_agent_device.php) shows the state too
$mReportTok = fn (string $tok, $report) => ea_checkin($tok, ['capabilities' => ['job:collect', 'mesh:nodeid'], 'mesh_agent' => $report]);
[$c] = $mReportTok($S['token']['PEND'], ['installed' => true, 'version' => '1.1.60', 'node_id' => $mNodeC . 'x']);
[$c, $body] = web($wb, 'GET', '/agent/rmm_agent_device.php?device_id=' . $D['PEND'], $SS['admin']);
$ok($c === 200 && str_contains($body, 'MeshCentral agent') && str_contains($body, 'data-rmm-mesh-agent="installed"') && str_contains($body, 'unless the MeshCentral setting that lets the agent'), 'an unlinked device\'s own page shows the Mesh agent state and the overwrite hint (' . $c . ')');

// the Remote tab shows the same state (the view it is drawn from)
$remView = ['mesh' => ['configured' => true, 'mapped' => true, 'views' => ['desktop']], 'mesh_agent' => rivetRmmUiMeshAgent($rmm->readModel()->deviceView($mDev['MA3'], false, 5)['mesh_agent'], true), 'admin' => true, 'offline' => false];
require_once "$root/includes/rmm_rem_ui.php";
$ok(str_contains(rivetRmmRemMeshHtml($remView), 'data-rmm-mesh-hint="conflict"') && str_contains(rivetRmmRemMeshHtml($remView), 'data-rem-mesh="desktop"'), 'the Remote tab\'s Mesh card carries the same state next to its buttons');
$ok(rivetRmmUiMeshAgent(null, true) === null && rivetRmmUiMeshAgentHtml(null, true) === '' && rivetRmmUiMeshAgent(['installed' => true, 'map_state' => 'bogus', 'node_id' => $mNodeA], false)['node_id'] === '' && rivetRmmUiMeshAgent(['installed' => true, 'map_state' => 'bogus'], true)['map_state'] === null, 'view-model: null stays null, an unknown map state is dropped, the node id is administrators only');

// hostile and broken reports never fail the check-in
foreach ([['installed' => 'yes'], 'string', [1, 2], ['installed' => true, 'node_id' => 'node//x'], ['installed' => true, 'node_id' => ['a'], 'version' => str_repeat('v', 500)]] as $bad) {
    [$c] = $mReport('MA5', $bad);
    $ok($c === 200, 'a hostile mesh_agent report does not fail the check-in (' . $c . ')');
}
$ok(!str_contains($mPage('admin', 'MA5')[1], 'Fatal error'), 'and the page still renders');

// events: registered by Core and offered by the shared picker; queued once for the subscription
require_once "$root/includes/event_picker.php";
$pickerIds = array_column(json_decode(eventPickerCatalogJson(), true)['events'], 'i');
$ok(in_array('rmm.mesh.agent_installed', $pickerIds, true) && in_array('rmm.mesh.node_conflict', $pickerIds, true) && in_array('rmm.starter.installed', $pickerIds, true), 'the shared event picker offers rmm.mesh.agent_installed, rmm.mesh.node_conflict and rmm.starter.installed');
$evs = array_values(array_filter($mEvents(), fn ($e) => str_starts_with($e, 'rmm.mesh.')));
$ok(count($evs) - $evBefore >= 4 && in_array('rmm.mesh.agent_installed', $evs, true) && in_array('rmm.mesh.node_conflict', $evs, true), 'the events were queued for the subscription: ' . implode(', ', $evs));
$q("UPDATE webhooks SET webhook_enabled=0");

// ============================================================ module off: nothing is asked, nothing is drawn
$rmm->admin()->disable($admin);
$fresh();
$a = $questions(); $vmOff = rivetRmmUiInstaller($db, 1); $b = $questions();
$ok($vmOff === null && $b - $a === $overhead, 'module off: the installer view-model is null and costs no statement');
[$c, $body] = $mPage('admin', 'MA1');
$ok(!str_contains($body, 'data-rmm-mesh-agent') && !str_contains($body, 'rmmInstallerModal'), 'module off: no Mesh agent block and no installer dialog on the asset page');
$rmm->admin()->enable($admin);
$fresh();
$mReset();
$q('DELETE FROM endpoint_agent_mesh_settings');
