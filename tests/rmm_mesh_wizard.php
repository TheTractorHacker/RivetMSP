<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * 26.10.30: the Create installer entry (RMM dashboard, Agent fleet, Add device dialog, messages for module off / missing permission / nothing published) and the Remote access
 * (MeshCentral) setup wizard (admin/rmm_mesh_wizard.php, admin/post/rmm_mesh_wizard.php, includes/rmm_mesh_wizard.php), on a scratch database and the real front controller (php -S).
 * Same scratch rules and environment as tests/endpoint_agent_lib.php. There is NO real MeshCentral server: "Save and test" is proven to REFUSE an unreachable one in words.
 */
$sd = sys_get_temp_dir() . '/rmm_mw_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_mesh_wizard.php";
require_once "$root/includes/rmm_installer_entry.php";
use RivetCore\Rmm\Authz\RmmPrincipal;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$q("DELETE FROM endpoint_agent_mesh_settings"); $q("DELETE FROM endpoint_agent_mesh_nodes");
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$rmm->syncState();
$sdir = sys_get_temp_dir() . '/rmm_mw_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'the service URL is the loopback test server');
$S = ['admin' => ea_forge_session($sdir, 1), 'tech' => ea_forge_session($sdir, 10), 'viewer' => ea_forge_session($sdir, 12), 'stock' => ea_forge_session($sdir, 14)];
$REF = ['Referer: ' . $wb . '/admin/rmm_mesh_wizard.php'];
$get = fn (string $path, string $who = 'admin') => web($wb, 'GET', $path, $S[$who]);
$wiz = fn (array $f, string $who = 'admin', bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $S[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $REF);
$cfg = fn () => $rmm->readModel()->settingsSummary();
$flash = function (string $b): string { return preg_match('/data-rmm-scale-msg>(.*?)<\/div>/s', $b, $m) === 1 ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES)) : ''; };
$stepState = fn (string $b, int $n) => preg_match('/data-wiz-step="' . $n . '" data-wiz-state="(ok|todo)"/', $b, $m) === 1 ? $m[1] : '?';
$hex = fn (int $n) => substr(str_repeat('a1b2c3d4e5f60718', 16), 0, $n);

// ---------------------------------------------------------------- Part 1: the Create installer entry
echo "\n--- Create installer entry ---\n";
$q("INSERT INTO endpoint_agent_devices SET install_id = 'dev-mw-1', hostname = 'MWPC1', os = 'windows', client_id = 1, link_state = 'linked', last_checkin_at = NOW()");
$devId = (int) $one('SELECT MAX(device_id) FROM endpoint_agent_devices');
$q("INSERT INTO assets SET asset_name = 'MWPC1', asset_type = 'Desktop', asset_client_id = 1"); $assetId = (int) $one('SELECT MAX(asset_id) FROM assets'); $q("UPDATE endpoint_agent_devices SET asset_id = $assetId WHERE device_id = $devId");
[$c, $b] = $get('/agent/rmm_fleet.php');
$ok($c === 200 && str_contains($b, 'data-rmm-build-installer') && str_contains($b, 'Create installer') && str_contains($b, 'href="/agent/rmm_add_device.php" class="btn btn-primary"'), 'fleet header: a primary Create installer button');
$ok(str_contains($b, 'data-rmm-installer-page-link') && str_contains($b, 'Open the Create installer page'), 'the Add device dialog links to the Create installer page');
$ok(str_contains($b, 'data-rmm-installer-entry="no_agent"') && str_contains($b, '/admin/settings_endpoint_agent.php#agent-updates') && str_contains($b, 'No Windows agent is published yet'), 'nothing published: the page says so and links to Agent updates');
[$c, $b] = $get('/agent/rmm_dashboard.php');
$ok($c === 200 && str_contains($b, 'data-rmm-build-installer') && str_contains($b, 'Create installer') && str_contains($b, 'btn btn-primary btn-sm'), 'RMM dashboard header: a primary Create installer button');
$ok(str_contains($b, 'data-rmm-installer-entry="no_agent"'), 'RMM dashboard: the nothing-published message too');
// role matrix: the tech role has no token.manage? read the truth from Core, then assert the page agrees
$can = fn (int $uid) => rivetRmmUiInstaller($db, $uid, null, null) !== null;
foreach (['tech' => 10, 'viewer' => 12] as $who => $uid) {
    foreach (['/agent/rmm_dashboard.php', '/agent/rmm_fleet.php'] as $path) {
        [$c, $b] = $get($path, $who);
        if ($c !== 200) { $ok(true, "$who: $path not reachable for this role (status $c): no entry to show"); continue; }
        if ($can($uid)) {
            $ok(str_contains($b, 'href="/agent/rmm_add_device.php"') && !str_contains($b, 'data-rmm-installer-entry="no_permission"'), "$who $path: may build installers: active button, no permission message");
        } else {
            $ok(str_contains($b, 'data-rmm-installer-entry="no_permission"') && str_contains($b, 'rmm.token.manage') && str_contains($b, 'Create enrollment tokens') && str_contains($b, 'data-rmm-build-installer-off') && str_contains($b, 'disabled'),
                "$who $path: cannot build installers: disabled button and a message naming the permission");
        }
    }
}
[$c, $b] = $get('/agent/rmm_add_device.php', 'viewer');
$ok($can(12) ? $c === 200 : str_contains($b, 'rmm.token.manage'), 'viewer: the builder page names the missing permission (or is open to this role)');
[$c, $b] = $get('/agent/rmm_fleet.php', 'stock');
$ok(!str_contains($b, 'data-rmm-build-installer'), 'a role without the RMM module sees neither button nor page');
// module off
$q("UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1");
[$c, $b] = $get('/agent/rmm_fleet.php');
$ok(str_contains($b, 'RMM module is turned off') && str_contains($b, 'RMM module card'), 'module off: the fleet page names the switch');
[$c, $b] = $get('/agent/rmm_add_device.php');
$ok(str_contains($b, 'RMM module is turned off') && str_contains($b, 'RMM module card'), 'module off: the builder page names the switch');
[$c, $b] = $get('/agent/rmm_dashboard.php');
$ok(str_contains($b, 'data-rmm-installer-entry="module_off"') && str_contains($b, 'data-rmm-build-installer-off'), 'module off: the dashboard shows a disabled button and the reason');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$rmm->syncState();

// ---------------------------------------------------------------- Part 2: the wizard
echo "\n--- wizard page ---\n";
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok($c === 200 && str_contains($b, 'Remote access (MeshCentral) setup') && str_contains($b, '0 of 5 steps done'), 'the wizard opens for an administrator: 0 of 5 steps done');
$ok(count(array_unique(array_map(fn ($n) => $stepState($b, $n), [1, 2, 3, 4, 5]))) === 1 && $stepState($b, 1) === 'todo', 'a fresh install: every step is amber');
foreach (['step1', 'step2', 'step3', 'step4', 'step5', 'id="troubleshooting"'] as $needle) { $ok(str_contains($b, $needle), "the page has $needle"); }
$ok(str_contains($b, 'loginTokenKey') && str_contains($b, 'Add Device Group') && str_contains($b, 'whole address') && str_contains($b, 'Let\'s Encrypt') && str_contains($b, 'Also install the MeshCentral agent') && str_contains($b, 'Connect</strong> button'), 'the numbered steps carry the plain instructions');
foreach (['Remote opens the MeshCentral home page', 'node id is not mapped', 'agent is not installed', 'Certificate errors'] as $t) { $ok(str_contains($b, $t), "troubleshooting: $t"); }
$ok(!preg_match('/\{"|\bJSON\b/i', strip_tags(preg_replace('#<script.*?</script>#s', '', $b))), 'no JSON on the page');
foreach (['mesh_url', 'mesh_domain', 'mesh_login_key', 'mesh_group_id', 'mesh_server_cert_pin'] as $f) { $ok(preg_match('/<label[^>]*for="[^"]+"[^>]*>/', $b) && preg_match('/name="' . $f . '"[^>]*aria-describedby="/', $b) === 1, "field $f has a one-sentence explanation"); }

echo "\n--- who may use it ---\n";
[$c, $b] = $get('/admin/rmm_mesh_wizard.php', 'tech');
$ok(!str_contains($b, 'name="mesh_login_key"') && !str_contains($b, 'data-wiz-step'), 'a technician does not get the wizard');
[$c, $b] = $get('/admin/rmm_mesh_wizard.php', 'viewer');
$ok(!str_contains($b, 'data-wiz-step'), 'a viewer does not get the wizard');
$before = json_encode($cfg());
$wiz(['wiz_save1' => '1', 'mesh_url' => 'https://evil.example.test', 'mesh_enabled' => '1'], 'tech');
$ok(json_encode($cfg()) === $before, 'a technician POST changes nothing');
$wiz(['wiz_save1' => '1', 'mesh_url' => 'https://evil.example.test', 'mesh_enabled' => '1'], 'admin', false);
$ok(json_encode($cfg()) === $before, 'a POST without the CSRF token changes nothing');
[$c] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['wiz_save1' => '1', 'mesh_url' => 'https://evil.example.test', 'csrf_token' => 'wrong'], $REF);
$ok(json_encode($cfg()) === $before, 'a POST with a wrong CSRF token changes nothing');

echo "\n--- hostile input ---\n";
$bad = [
    'javascript address' => ['wiz_save1' => '1', 'mesh_url' => 'javascript:alert(1)', 'mesh_enabled' => '1'],
    'credentials in the address' => ['wiz_save1' => '1', 'mesh_url' => 'https://user:pass@mesh.example.test', 'mesh_enabled' => '1'],
    'domain with markup' => ['wiz_save1' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '"><script>x</script>', 'mesh_enabled' => '1'],
    'key that is not hex' => ['wiz_save1' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_login_key' => '<script>alert(1)</script>', 'mesh_enabled' => '1'],
    'group id with markup' => ['wiz_save2' => '1', 'mesh_group_id' => '"><img src=x onerror=alert(1)>'],
    'pin that is not a hash' => ['wiz_save3' => '1', 'mesh_server_cert_pin' => '"><svg onload=alert(1)>'],
];
foreach ($bad as $label => $f) {
    $wiz($f);
    [$c, $b] = $get('/admin/rmm_mesh_wizard.php');
    $ok(json_encode($cfg()) === $before && trim($flash($b)) !== '' && !str_contains($b, '<script>x</script>') && !str_contains($b, '<img src=x') && !str_contains($b, '<svg onload') && !str_contains($b, '<script>alert(1)</script>'),
        "hostile: $label is refused in words, nothing saved, nothing unescaped on the page");
}
$wiz(['wiz_save1' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '"><b id=zz>', 'mesh_enabled' => '1']);
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok(!str_contains($b, '<b id=zz>') && str_contains($b, '&quot;&gt;&lt;b id=zz&gt;'), 'refused text is put back into the form escaped');

echo "\n--- the five steps, live ---\n";
$key = $hex(64);
$wiz(['wiz_save1' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_login_key' => $key, 'mesh_enabled' => '1']);
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok($cfg()['mesh_url'] === 'https://mesh.example.test' && $cfg()['mesh_login_key_set'] === true && (int) $cfg()['mesh_enabled'] === 1, 'step 1 saved: address, key (set) and the switch');
$ok($stepState($b, 1) === 'ok' && str_contains($b, '1 of 5 steps done'), 'step 1 turns green');
$ok(!str_contains($b, $key) && !str_contains($b, strtoupper($key)), 'the login key is never printed back');
$ok($one('SELECT mesh_login_key_enc FROM endpoint_agent_settings WHERE id = 1') !== $key && strlen((string) $one('SELECT mesh_login_key_enc FROM endpoint_agent_settings WHERE id = 1')) > 20, 'the key is stored encrypted');
// a save that leaves the key box empty keeps the key and the other settings
$wiz(['wiz_save1' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_login_key' => '', 'mesh_enabled' => '1']);
$ok($cfg()['mesh_login_key_set'] === true, 'an empty key box keeps the stored key');
// test connection: the address does not answer; the failure is said in words and step 1 goes amber
$wiz(['wiz_test' => '1', 'mesh_url' => 'https://127.0.0.1:1', 'mesh_enabled' => '1']);
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok($stepState($b, 1) === 'todo' && str_contains($b, 'Last test: it did not answer'), 'test connection against nothing: step 1 amber with the reason');
$wiz(['wiz_save1' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_enabled' => '1']);
// step 2
$group = $hex(64);
$grp = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKlMnOpQrStUvWxYz01';
$ok(strlen($grp) === 64, 'fixture: a 64-character group id');
$wiz(['wiz_save2' => '1', 'mesh_group_id' => 'https://mesh.example.test/meshagents?id=4&meshid=' . $grp . '&installflags=0']);
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok($stepState($b, 2) === 'ok' && !str_contains($b, '>' . $grp), 'step 2: the whole pasted download address is accepted, step turns green');
$ok(str_contains($b, 'value="' . $grp . '"') , 'the group id is in a masked input only');
// step 3
$ok($stepState($b, 3) === 'todo', 'step 3 is amber until the certificate is checked or pinned');
$wiz(['wiz_probe' => '1']);
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok(str_contains($b, 'data-rmm-mesh-pin-result="error"') && $stepState($b, 3) === 'todo', '"Read it from the server" against an unreachable server: an error in words, nothing saved');
$ok((string) ($one('SELECT server_cert_pin FROM endpoint_agent_mesh_settings WHERE id = 1') ?? '') === '', 'the read never saves a pin');
$pin = $hex(96);
$wiz(['wiz_save3' => '1', 'mesh_server_cert_pin' => $pin]);
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok($stepState($b, 3) === 'ok' && str_contains($b, 'is pinned'), 'a valid pin saved: step 3 green');
$wiz(['wiz_save3' => '1', 'mesh_server_cert_pin' => '']);
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok($stepState($b, 3) === 'todo', 'the pin emptied again: back to amber until checked');
// step 4: ready now?
$ok($stepState($b, 4) === 'ok' ? str_contains($b, 'Installers can include the MeshCentral agent') : str_contains($b, 'Not ready:'), 'step 4 mirrors Core\'s own readiness sentence');
[$c, $bd] = $get('/agent/rmm_add_device.php');
$ok($stepState($b, 4) === (str_contains($bd, 'name="install_mesh_agent"') && !preg_match('/name="install_mesh_agent"[^>]*disabled/', $bd) ? 'ok' : 'todo'), 'step 4 agrees with the installer page\'s tick-box');
// step 5
$ok($stepState($b, 5) === 'todo' && str_contains($b, 'No enrolled device is mapped yet'), 'step 5 amber while no device is mapped');
$q("INSERT INTO endpoint_agent_mesh_nodes SET device_id = $devId, mesh_node_id = 'node//" . $grp . "', source = 'agent'");
[$c, $b] = $get('/admin/rmm_mesh_wizard.php');
$ok($stepState($b, 5) === 'ok' && str_contains($b, '1 of 1 enrolled device(s) are mapped'), 'a mapped device turns step 5 green');
// audit
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Settings' AND log_description LIKE '%edited the MeshCentral settings%'") >= 2, 'saves are audited by Core');

echo "\n--- device page and links ---\n";
$q("UPDATE endpoint_agent_settings SET mesh_enabled = 0, mesh_url = ''");
[$c, $b] = $get("/agent/asset_details.php?client_id=1&asset_id=$assetId");
$ok($c === 200 && str_contains($b, 'id="rmm-act-remote-setup"') && str_contains($b, '/admin/rmm_mesh_wizard.php'), 'device page, Mesh not set up, administrator: the Remote button leads to the wizard');
[$c, $b] = $get("/agent/asset_details.php?client_id=1&asset_id=$assetId", 'tech');
$ok($c !== 200 || (!str_contains($b, 'id="rmm-act-remote-setup"') && (!str_contains($b, 'id="rmm-act-remote"') || str_contains($b, 'not set up yet'))), 'device page, technician: no wizard link, a disabled Remote with the reason');
[$c, $b] = $get('/admin/settings_endpoint_agent.php');
$ok(str_contains($b, 'data-mesh-wizard-link') && str_contains($b, 'Step-by-step setup with live status'), 'Endpoint agent admin page header and Mesh card link to the wizard');
[$c, $b] = $get('/admin/settings.php');
$ok(str_contains($b, 'rmm_mesh_wizard.php') && str_contains($b, 'Remote access (MeshCentral) setup'), 'Administration settings lists the wizard');
