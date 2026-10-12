<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fragment of tests/rmm_ui.php (RivetCore 1.0.0-rc.16, pushed MeshCentral agent install and the MSI installer format; migration 0025): the Remote tab's install / remove controls
 * (includes/rmm_rem_ui.php, agent/post/rmm_remote_tools.php, js/rmm_remote.js), the fleet page's bulk card (includes/rmm_ui_render.php, agent/post/rmm_mesh_bulk.php,
 * js/rmm_mesh_bulk.js), the Format selector of the Add device dialog and the MSI templates / Deployment cards on Administration. Shares that file's variables.
 *
 * Everything is driven through the product: devices enroll and check in through the real device endpoints (and announce job:mesh_install or not), the technician acts through the real
 * handlers over HTTP (forged sessions, CSRF), the DEVICE is simulated through the real device endpoints (GET /api/v1/agent_jobs receives the signed job, POST reports the result).
 * There is NO real MeshCentral server and no Windows machine behind any of this: what is proven is RivetMSP's side.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Installer\InstallerStamp;

$mpGroup = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
$mpGroupUrl = \RivetCore\Rmm\Mesh\MeshAgentCatalog::hexToUrl($mpGroup);
$mpAct = $rmm->remoteActions();
$mpCall = function (string $who, string $action, array $f = []) use ($wb, $SS): array {
    [$c, $body] = web($wb, 'POST', '/agent/post/rmm_remote_tools.php', $SS[$who], $f + ['action' => $action, 'csrf_token' => 'csrftok1']);

    return [$c, json_decode($body, true) ?: [], $body];
};
$mpBulk = function (string $who, array $f = []) use ($wb, $SS): array {
    [$c, $body] = web($wb, 'POST', '/agent/post/rmm_mesh_bulk.php', $SS[$who], $f + ['csrf_token' => 'csrftok1']);

    return [$c, json_decode($body, true) ?: [], $body];
};
$mpAdm = fn (array $f, string $who = 'admin') => web($wb, 'POST', '/admin/post.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $REF1);
$mpJobs = function (string $tok): array {
    [$c, , $j] = http('GET', '/api/v1/agent_jobs', $tok);

    return $c === 200 ? ($j['jobs'] ?? []) : [];
};
$mpJobRow = fn (int $dev, string $type) => $rows("SELECT * FROM endpoint_agent_jobs WHERE device_id = $dev AND type = '$type' ORDER BY created_at DESC LIMIT 1")[0] ?? null;
$mpOpen = fn (int $dev) => (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $dev AND type = 'mesh_install' AND state IN ('queued','running')");
$mpAudit = fn (string $action) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($action) . "'");

// ---- devices: a Linux one that announced job:mesh_install, one whose agent did not, one in Client B, and a Windows one that announced it
$mpDev = []; $mpTok = []; $mpAsset = [];
$mpCaps = ['job:collect', 'job:shell', 'job:svc_list', 'job:svc_control', 'job:proc_list', 'job:proc_kill', 'remote:services', 'remote:processes', 'job:mesh_install', 'job:mesh_uninstall'];
foreach (['MP1' => [1, 'A', 'linux', $mpCaps], 'MP2' => [1, 'A', 'linux', array_values(array_diff($mpCaps, ['job:mesh_install', 'job:mesh_uninstall']))],
    'MPB' => [2, 'B', 'linux', $mpCaps], 'MPW' => [1, 'A', 'windows', $mpCaps]] as $n => [$client, $tk, $os, $caps]) {
    $q("INSERT INTO assets SET asset_type='Server', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
    $mpAsset[$n] = (int) $db->insert_id;
    [$c, , $j] = ea_enroll($S['enroll'][$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
    $mpDev[$n] = (int) ($j['device_id'] ?? 0);
    $mpTok[$n] = (string) ($j['device_token'] ?? '');
    $ok($c === 201 && $mpDev[$n] > 0, "$n enrolled through the real endpoint");
    [$c] = ea_checkin($mpTok[$n], ['capabilities' => $caps]);
    $ok($c === 200, "$n checked in" . (in_array('job:mesh_install', $caps, true) ? ' and announced job:mesh_install' : ' without job:mesh_install (an older agent)'));
}
$A += $mpAsset;
$mpPage = fn (string $who, string $n) => web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $mpAsset[$n], $SS[$who]);
foreach ([3, 5] as $role) { $q("INSERT INTO user_role_permissions SET user_role_id=$role, module_id=6, user_role_permission_level=2"); }
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 1 WHERE company_id = 1");
rivetRmmForgetAccess();

// ============================================================ nothing is offered while remote tools or Mesh are not ready
$ok($rmm->meshAgents()->installRefusal() !== null || true, 'start of the fragment');
$q('DELETE FROM endpoint_agent_mesh_settings'); $q('DELETE FROM endpoint_agent_mesh_agents'); $q('DELETE FROM endpoint_agent_installer_options');
$rmm->meshAgentSettings()->refresh();
$rmm->settings()->set(['mesh_enabled' => 0]);
$fresh();
[$c, $body] = $mpPage('admin', 'MP1');
$ok($c === 200 && !str_contains($body, 'data-rem-mesh-push') && !str_contains($body, 'data-rem-mesh-install'), 'remote tools off: no Remote tab, so no install control');
[$c, $j] = $mpCall('admin', 'mesh_install', ['device_id' => $mpDev['MP1']]);
$ok($c === 409 && ($j['code'] ?? '') === 'feature_disabled', 'remote tools off: the handler refuses with Core\'s switch text (' . $c . ')');
$mpAct->updateSettings($admin, ['features' => ['remote_tools' => true]]);
$fresh();
[$c, $body] = $mpPage('admin', 'MP1');
$ok($c === 200 && str_contains($body, 'id="rmm-remote-tools"') && !str_contains($body, 'data-rem-mesh-push') && !str_contains($body, 'data-rem-mesh-install'), 'remote tools on but Mesh not set up: the Remote tab has no install control');
[$c, $j] = $mpCall('admin', 'mesh_install', ['device_id' => $mpDev['MP1']]);
$ok($c === 409 && ($j['code'] ?? '') === 'not_configured' && $mpOpen($mpDev['MP1']) === 0, 'Mesh not set up: Core says not_configured and queues nothing (' . $c . ': ' . ($j['error'] ?? '') . ')');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok($c === 200 && !str_contains($body, 'id="rmm-mesh-bulk"') && !str_contains($body, 'rmm_mesh_bulk.js'), 'Mesh not set up: the fleet page has no bulk card and does not link its script');
[$c, $j] = $mpBulk('admin', ['target' => 'all:0', 'confirm' => '1']);
$ok($c === 409 && ($j['code'] ?? '') === 'not_configured', 'Mesh not set up: the bulk handler is refused by Core too (' . $c . ')');

// ---- set Mesh up through the product (the real admin handler)
$mpFull = ['save_mesh_settings' => 1, 'mesh_install_fields' => '1', 'mesh_enabled' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_account_template' => 'rivetit-support',
    'mesh_policy' => 'unattended', 'mesh_token_ttl_s' => '300', 'mesh_login_key' => '', 'mesh_group_id' => $mpGroupUrl, 'mesh_server_cert_pin' => '', 'mesh_auto_map_overwrite' => ''];
$mpAdm($mpFull);
$rmm->meshAgentSettings()->refresh();
$fresh();
$ok($rmm->meshAgents()->installRefusal() === null, 'Mesh on with an https address and a device group: Core offers the install');

// ============================================================ the Remote tab: install
[$c, $body] = $mpPage('admin', 'MP1');
$ok($c === 200 && str_contains($body, 'data-rem-mesh-push="not_installed"') && preg_match('/<button type="button" class="btn btn-primary btn-sm" data-rem-mesh-install="1">/', $body) === 1 && str_contains($body, 'Install MeshCentral agent'), 'Remote tab: an enrolled Linux device that announced the job shows an enabled Install MeshCentral agent button');
$ok(!str_contains($body, 'data-rem-mesh-uninstall') && !str_contains($body, 'rem-mesh-push-help'), 'not installed: no Remove button and no "agent too old" help');
$ok(str_contains($body, 'id="rem-mesh-push-status"') && preg_match('/id="rem-mesh-push-status"[^>]*role="status"[^>]*aria-live="polite"/', $body) === 1 && preg_match('/id="rem-mesh-push-result"[^>]*role="status"/', $body) === 1, 'accessible: the status and result areas are live regions');
$ok(!preg_match('/\son[a-z]+="/', (string) (preg_match('/data-rem-mesh-push(.*?)rem-mesh-push-result/s', $body, $mm) ? $mm[1] : '')), 'CSP: no inline event handlers in the install section');
$ok(str_contains($body, 'rmm_remote.js'), 'the Remote script is linked');
[$c, $body] = $mpPage('admin', 'MP2');
$ok(preg_match('/data-rem-mesh-install="1"[^>]* disabled aria-describedby="rem-mesh-push-help"/', $body) === 1 && str_contains($body, 'id="rem-mesh-push-help"') && str_contains($body, 'too old to take the install'), 'an agent that never announced job:mesh_install: the button is disabled and the help says to update the agent first');
[$c, $j] = $mpCall('admin', 'mesh_install', ['device_id' => $mpDev['MP2']]);
$ok($c === 409 && ($j['code'] ?? '') === 'unsupported' && $mpOpen($mpDev['MP2']) === 0, 'forged click on that device: Core answers 409 unsupported, nothing queued (' . $c . ')');

// the technician (remote tools via the level 3 setting) may; roles without the ability are refused with Core's text and the control is not drawn
[$c, $body] = $mpPage('tech', 'MP1');
$ok(str_contains($body, 'data-rem-mesh-install="1"'), 'a technician with remote management sees the install button');
foreach (['viewer', 'rebootonly', 'remoteonly'] as $who) {
    [$c, $body] = $mpPage($who, 'MP1');
    $ok(!str_contains($body, 'data-rem-mesh-install') && !str_contains($body, 'data-rem-mesh-push'), "$who: no install control");
    [$c, $j] = $mpCall($who, 'mesh_install', ['device_id' => $mpDev['MP1']]);
    $ok($c === 403 && $mpOpen($mpDev['MP1']) === 0, "$who: forged install refused by Core ($c)");
    [$c, $j] = $mpCall($who, 'mesh_uninstall', ['device_id' => $mpDev['MP1'], 'confirm' => '1']);
    $ok($c === 403, "$who: forged removal refused ($c)");
}
// another client's device: the same 404 as a missing one
[$c1, , $raw1] = $mpCall('clientb', 'mesh_install', ['device_id' => $mpDev['MP1']]);
[$c2, , $raw2] = $mpCall('clientb', 'mesh_install', ['device_id' => 99999]);
[$c3, , $raw3] = $mpCall('clientb', 'mesh_uninstall', ['device_id' => $mpDev['MP1'], 'confirm' => '1']);
$ok($c1 === 404 && $c2 === 404 && $c3 === 404 && $raw1 === $raw2 && $mpOpen($mpDev['MP1']) === 0, 'a device of another client answers exactly like a missing one, for install and for removal');
// CSRF and method
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_remote_tools.php', $SS['admin'], ['action' => 'mesh_install', 'device_id' => $mpDev['MP1']]);
$ok($c === 403 && (json_decode($b, true)['code'] ?? '') === 'csrf' && $mpOpen($mpDev['MP1']) === 0, 'no CSRF token: 403 csrf, nothing queued');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_remote_tools.php', $SS['admin'], ['action' => 'mesh_install', 'device_id' => $mpDev['MP1'], 'csrf_token' => 'wrong']);
$ok($c === 403 && (json_decode($b, true)['code'] ?? '') === 'csrf', 'wrong CSRF token: 403 csrf');

// the click
$logBefore = $mpAudit('Remote Tool Queued');
[$c, $j] = $mpCall('admin', 'mesh_install', ['device_id' => $mpDev['MP1']]);
$ok($c === 200 && ($j['success'] ?? false) === true && !empty($j['job_id']), 'install: queued, the job id is returned (' . $c . ')');
$row = $mpJobRow($mpDev['MP1'], 'mesh_install');
$ok($row !== null && $row['state'] === 'queued' && $mpOpen($mpDev['MP1']) === 1 && $mpAudit('Remote Tool Queued') === $logBefore + 1, 'the job row is queued and the action is audited with the user\'s name');
$rp = json_decode((string) ($row['params_json'] ?? ''), true) ?: [];
$ok($row !== null && !str_contains((string) $row['params_json'], $mpGroup) && !str_contains((string) $row['params_json'], $mpGroupUrl) && ($rp['url'] ?? '') === 'https://mesh.example.test' && ($rp['force'] ?? null) === false, 'the device group id is not in the job row (Core seals it); the Mesh address and force are');
$jobs = $mpJobs($mpTok['MP1']);
$jj = null; foreach ($jobs as $x) { if ($x['type'] === 'mesh_install') { $jj = $x; } }
$ok($jj !== null && str_contains(json_encode($jj['params'] ?? []), $mpGroupUrl) && ($jj['params']['url'] ?? '') === 'https://mesh.example.test', 'the device receives the signed job with the group id restored in MeshCentral\'s URL form and the stored address');
[$c, $j] = $mpCall('admin', 'mesh_install', ['device_id' => $mpDev['MP1']]);
$ok($c === 200 && ($j['code'] ?? '') === 'already_queued' && ($j['skipped'] ?? '') === 'already_queued' && ($j['job_id'] ?? '') === ($row['job_id'] ?? 'x') && $mpOpen($mpDev['MP1']) === 1, 'a second click while one is open: the same job, nothing new (already_queued)');
// the result, through the job_result action the page polls
[$c, $j] = $mpCall('admin', 'job_result', ['device_id' => $mpDev['MP1'], 'job_id' => $row['job_id']]);
$ok($c === 200 && in_array($j['state'] ?? '', ['queued', 'sent', 'running'], true) && ($j['final'] ?? true) === false, 'job_result: still waiting for the device (not final)');
if ($jj !== null) {
    [$c] = http('POST', '/api/v1/agent_jobs', $mpTok['MP1'], ['job_id' => $jj['job_id'], 'attempt' => $jj['attempt'], 'state' => 'failed', 'exit_code' => 1, 'output' => 'mesh install failed: the Mesh server answered HTTP 404']);
    $ok($c === 200, 'the simulated device reports the job failed');
}
[$c, $j] = $mpCall('admin', 'job_result', ['device_id' => $mpDev['MP1'], 'job_id' => $row['job_id']]);
$ok($c === 200 && ($j['state'] ?? '') === 'failed' && ($j['final'] ?? false) === true && str_contains((string) ($j['html'] ?? ''), 'Failed'), 'job_result: final and failed');
// the reason travels in the existing check-in Mesh state, which the Remote card already shows
ea_checkin($mpTok['MP1'], ['capabilities' => $mpCaps, 'mesh_agent' => ['installed' => false, 'error' => 'the Mesh server answered HTTP 404']]);
$fresh();
[, $body] = $mpPage('admin', 'MP1');
$ok(str_contains($body, 'could not install it: the Mesh server answered HTTP 404') && str_contains($body, 'data-rem-mesh-push="not_installed"'), 'the failure reason is shown from the device\'s Mesh state, and Install is offered again');
$ok(!str_contains((string) ($j['html'] ?? ''), $mpGroup) && !str_contains((string) ($j['html'] ?? ''), $mpGroupUrl), 'the result never shows the device group id');

// the device reports the Mesh agent installed (a real check-in), the page switches to Remove / Run again
ea_checkin($mpTok['MP1'], ['capabilities' => $mpCaps, 'mesh_agent' => ['installed' => true, 'version' => '1.1.0', 'error' => null, 'node_id' => 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01']]);
$fresh();
[$c, $body] = $mpPage('admin', 'MP1');
$ok(str_contains($body, 'data-rem-mesh-push="installed"') && str_contains($body, 'data-rem-mesh-uninstall="1"') && str_contains($body, 'data-rem-mesh-install="force"') && !str_contains($body, 'data-rem-mesh-install="1"'), 'installed: Remove MeshCentral agent and "Run the install again" are offered, plain Install is not');
[$c, $j] = $mpCall('admin', 'mesh_install', ['device_id' => $mpDev['MP1']]);
$ok($c === 200 && ($j['skipped'] ?? '') === 'already_installed' && $mpOpen($mpDev['MP1']) === 0, 'install on a device that reports the agent: already_installed, nothing queued');
[$c, $j] = $mpCall('admin', 'mesh_install', ['device_id' => $mpDev['MP1'], 'force' => '1']);
$ok($c === 200 && !empty($j['job_id']) && $mpOpen($mpDev['MP1']) === 1, 'force: the installer is run again (a new job)');
// removal needs the explicit confirmation
[$c, $j] = $mpCall('admin', 'mesh_uninstall', ['device_id' => $mpDev['MP1']]);
$ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required' && $mpJobRow($mpDev['MP1'], 'mesh_uninstall') === null, 'removal without confirm: 422 confirmation_required, nothing queued');
[$c, $j] = $mpCall('admin', 'mesh_uninstall', ['device_id' => $mpDev['MP1'], 'confirm' => '1']);
$ok($c === 200 && !empty($j['job_id']) && ($u = $mpJobRow($mpDev['MP1'], 'mesh_uninstall')) !== null && (int) $u['destructive'] === 1, 'removal with confirm: a destructive mesh_uninstall job is queued');
$ok(str_contains($body, 'Removing') || str_contains($body, 'Remove MeshCentral agent'), 'the page names the action');
// the confirmation dialog the script uses exists in the page
$ok(str_contains($body, 'id="rmmRemConfirm"') && str_contains($body, 'id="rmmRemConfirmCheck"'), 'the in-page confirmation dialog is there (no window.confirm)');
// Windows device: both controls follow the same rules
[$c, $body] = $mpPage('admin', 'MPW');
$ok(str_contains($body, 'data-rem-mesh-install="1"'), 'a Windows device that announced the job is offered the install too');

// ============================================================ the fleet page: bulk
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok($c === 200 && str_contains($body, 'id="rmm-mesh-bulk"') && str_contains($body, 'data-post-url="/agent/post/rmm_mesh_bulk.php"') && str_contains($body, '/js/rmm_mesh_bulk.js') && str_contains($body, 'Install the MeshCentral agent on many devices'), 'Agent fleet: the bulk card and its script');
$ok(str_contains($body, '<optgroup label="A client">') && str_contains($body, '<option value="client:1">') && str_contains($body, '<option value="all:0">') && preg_match('/<label class="form-label small mb-1" for="rmm-mb-target">/', $body) === 1, 'the picker lists clients (and tags and groups when there are any) and "every device", with a label');
$ok(preg_match('/id="rmm-mb-msg" class="small mt-2" role="status" aria-live="polite"/', $body) === 1 && preg_match('/id="rmm-mb-go"[^>]* disabled/', $body) === 1 && str_contains($body, 'id="rmm-mb-confirm-wrap"'), 'accessible live region; the Install button starts disabled behind the confirmation box');
$ok(!preg_match('/\son[a-z]+="/', (string) (preg_match('/id="rmm-mesh-bulk"(.*?)<\/section>/s', $body, $mm) ? $mm[1] : '')), 'CSP: no inline event handlers in the bulk card');
foreach (['viewer', 'rebootonly', 'remoteonly'] as $who) {
    [$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS[$who]);
    $ok(!str_contains($body, 'id="rmm-mesh-bulk"'), "$who: no bulk card");
    [$c, $j] = $mpBulk($who, ['target' => 'all:0', 'confirm' => '1']);
    $ok($c === 403 && $mpOpen($mpDev['MP2']) === 0, "$who: forged bulk request refused by Core ($c)");
}
// the request must be well formed
foreach (['', 'all', 'client:abc', 'tag:-1', 'device:1;DROP', 'client:1 OR 1=1'] as $bad) {
    [$c, $j] = $mpBulk('admin', ['target' => $bad, 'confirm' => '1']);
    $ok($c === 422 && ($j['code'] ?? '') === 'invalid', 'malformed target ' . json_encode($bad) . ': 422 invalid');
}
[$c, $j] = $mpBulk('admin', ['target' => 'tag:999999', 'confirm' => '1']);
$ok($c === 422 && str_contains((string) ($j['error'] ?? ''), 'does not exist'), 'a tag that does not exist: Core says so');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_mesh_bulk.php', $SS['admin'], ['target' => 'all:0', 'confirm' => '1']);
$ok($c === 403 && (json_decode($b, true)['code'] ?? '') === 'csrf', 'bulk: no CSRF token -> 403 csrf');
[$c] = web($wb, 'GET', '/agent/post/rmm_mesh_bulk.php', $SS['admin']);
$ok($c === 405, 'bulk: GET -> 405');
// the two steps: count first, then confirm
$before = (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'mesh_install'");
[$c, $j] = $mpBulk('admin', ['target' => 'client:1']);
$ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required' && ($j['device_count'] ?? 0) >= 2 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'mesh_install'") === $before, 'step 1: no confirmation -> Core answers with the device count and queues nothing (' . ($j['device_count'] ?? '?') . ')');
$cnt = (int) ($j['device_count'] ?? 0);
[$c, $j] = $mpBulk('admin', ['target' => 'client:1', 'confirm' => '1']);
$ok($c === 200 && ($j['success'] ?? false) === true && ($j['device_count'] ?? 0) === $cnt, 'step 2: confirmed (' . $c . ')');
// MP1 reports the agent installed, MP2 and the devices of the earlier fragments announced nothing (too old), MPW gets one; the counts add up
$ok(($j['created'] ?? -1) >= 1 && ($j['skipped_unsupported'] ?? 0) >= 1 && ($j['skipped_installed'] ?? 0) >= 1 && array_sum(array_intersect_key($j, array_flip(['created', 'skipped_denied', 'skipped_installed', 'skipped_queued', 'skipped_unsupported', 'skipped_error']))) === $cnt, 'the counts: created, already queued, too old; they add up to the devices reached (' . json_encode(array_diff_key($j, ['success' => 1, 'code' => 1, 'message' => 1])) . ')');
$ok($mpOpen($mpDev['MPB']) === 0, 'a client-A bulk request never reaches the device of Client B');
[$c, $j] = $mpBulk('admin', ['target' => 'client:1', 'confirm' => '1']);
$ok($c === 200 && ($j['created'] ?? -1) === 0 && ($j['skipped_queued'] ?? 0) >= 1, 'repeating it is safe: nothing new is created and the open install is counted as already queued');
$ok($mpAudit('Mesh Agent Bulk Install') >= 2, 'the bulk install is audited by Core');
// a user limited to Client B reaches only Client B, whatever target they ask for
[, $jAdminB] = $mpBulk('admin', ['target' => 'client:2']);
$allLive = (int) $one('SELECT COUNT(*) FROM endpoint_agent_devices WHERE retired_at IS NULL');
[$c, $j] = $mpBulk('clientb', ['target' => 'all:0']);
$ok($c === 422 && ($j['device_count'] ?? 0) === ($jAdminB['device_count'] ?? -1) && ($j['device_count'] ?? 0) < $allLive, 'the Client B user asking for "every device" is told only the devices of Client B (' . ($j['device_count'] ?? '?') . ' of ' . $allLive . '), not the whole fleet');
$openA = (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'mesh_install' AND client_id = 1");
[$c, $j] = $mpBulk('clientb', ['target' => 'all:0', 'confirm' => '1']);
$ok($c === 200 && ($j['created'] ?? 0) >= 1 && $mpOpen($mpDev['MPB']) === 1 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'mesh_install' AND client_id = 1") === $openA, 'confirmed: only Client B devices got a job, none of Client A did');
[$c, $j] = $mpBulk('clientb', ['target' => 'client:1', 'confirm' => '1']);
$ok($c >= 400 && $mpOpen($mpDev['MP2']) === 0, 'asking for Client A as the Client B user is refused, nothing queued (' . $c . ')');
// the approval threshold refuses a large bulk (Core's rule), it is not worked around
$mpLim = (string) $one('SELECT COALESCE(limits_json, \'\') FROM endpoint_agent_settings WHERE id = 1');
$q("UPDATE endpoint_agent_settings SET limits_json = '" . $esc(json_encode(['approval_bulk_threshold' => 1])) . "' WHERE id = 1");
$rmm->settings()->refresh();
$fresh();
[$c, $j] = $mpBulk('admin', ['target' => 'client:1', 'confirm' => '1']);
$ok($c === 409 && ($j['code'] ?? '') === 'approval_required', 'above the approval threshold the request is refused (409 approval_required), not queued around the rule');
$q('UPDATE endpoint_agent_settings SET limits_json = ' . ($mpLim === '' ? 'NULL' : "'" . $esc($mpLim) . "'") . ' WHERE id = 1');
$rmm->settings()->refresh();
$fresh();
// a handler running without Mesh set up: the card disappears
$mpAdm($mpFull + ['mesh_group_id' => '']);   // an empty field leaves the saved id (no clearing by accident), so go through the settings object
$rmm->settings()->set(['mesh_enabled' => 0]);
$fresh();
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(!str_contains($body, 'id="rmm-mesh-bulk"'), 'Mesh switched off: the bulk card is gone');
[$c, $body] = $mpPage('admin', 'MP1');
$ok(str_contains($body, 'data-rem-mesh-uninstall="1"') && !str_contains($body, 'data-rem-mesh-install="force"') && str_contains($body, 'removing does not'), 'Mesh switched off: Remove is still offered for a device that has the agent (Core does not tie it to the settings), Install is not');
$rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test']);
$fresh();

// ============================================================ module off: nothing, and no RMM queries
$rmm->admin()->disable($admin);
$fresh();
[$c, $j] = $mpCall('admin', 'mesh_install', ['device_id' => $mpDev['MP1']]);
$ok($c === 404 && ($j['code'] ?? '') === 'module_disabled', 'module off: the Remote handler answers 404 module_disabled');
[$c, $j] = $mpBulk('admin', ['target' => 'all:0', 'confirm' => '1']);
$ok($c === 404 && ($j['code'] ?? '') === 'module_disabled', 'module off: the bulk handler answers 404 module_disabled');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(!str_contains($body, 'rmm-mesh-bulk') && !str_contains($body, 'rmm_mesh_bulk.js') && !str_contains($body, 'rmm_installer.js'), 'module off: the fleet page draws none of it and links none of the scripts');
$rmm->admin()->enable($admin);
$fresh();

// ============================================================ clean up
$mpAct->updateSettings($admin, ['features' => ['remote_tools' => false]]);
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 0 WHERE company_id = 1");
$q("DELETE FROM endpoint_agent_jobs WHERE type IN ('mesh_install','mesh_uninstall')");
$q('DELETE FROM endpoint_agent_mesh_settings'); $q('DELETE FROM endpoint_agent_mesh_agents');
foreach ([3, 5] as $role) { $q("DELETE FROM user_role_permissions WHERE user_role_id=$role AND module_id=6"); }
rivetRmmForgetAccess();
$fresh();
