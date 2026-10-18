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
$mGroupUrl = \RivetCore\Rmm\Mesh\MeshAgentCatalog::hexToUrl($mGroup);   // RivetCore 1.0.0-rc.15: MeshCentral's own 64-character spelling of the same id (what the field reads back)
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
$ok(str_contains($body, 'name="mesh_server_cert_pin"') && str_contains($body, 'name="mesh_auto_map_overwrite"') && str_contains($body, 'name="mesh_agent_id[windows/amd64]"') && str_contains($body, 'name="mesh_install_arg[linux]"') && !str_contains($body, 'name="mesh_agent_ids"') && str_contains($body, 'name="mesh_install_fields"'), 'pin, overwrite switch, the two corrections and the marker field are on the form');
$ok(!preg_match('/name="mesh_auto_map_overwrite"[^>]*checked/', $body), 'the overwrite switch is off by default');
$ok(str_contains($body, 'id="mesh-pin-probe"') && preg_match('/<button type="submit" form="mesh-pin-probe"/', $body) === 1 && str_contains($body, 'Read it from the server'), 'the "Read it from the server" button belongs to its own form (forms cannot nest) and the page has that form');
$ok(str_contains($body, 'was checked against a real MeshCentral') && str_contains($body, 'were not: check them against your Mesh version') && str_contains($body, 'Verify these against your own Mesh server') && str_contains($body, '<details class="rmm-mesh-advanced"'), 'the advanced section carries Core\'s note (what was checked against a real MeshCentral and what was not) and our verify warning');
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
$ok(str_contains($body, 'value="' . $mGroupUrl . '"') && !str_contains($body, 'value="' . $mGroup . '"') && str_contains($body, 'value="' . hash('sha384', 'pin-one') . '"') && preg_match('/name="mesh_auto_map_overwrite"[^>]*checked/', $body) === 1 && str_contains($body, 'Installers can include the MeshCentral agent'), 'the page shows the stored values (the group id read back in the URL form) and says installers can include the agent');
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
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_agent_id' => ['windows/amd64' => 'abc']]);
[$c, $body] = $mPg();
$ok(str_contains($mMsg($body), 'must be a whole number from 1 to 255') && str_contains($body, 'value="abc"'), 'a non-number in an agent id box is refused before Core is asked (plain words), and the text stays in the box');
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_agent_id' => ['windows/amd64' => '999']]);
[$c, $body] = $mPg();
$ok(str_contains($mMsg($body), 'whole number from 1 to 255') && $mCfg()->get()['agent_ids'] === [], 'an out-of-range agent id is refused by Core');

// the corrections
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_agent_id' => ['windows/arm64' => '40'], 'mesh_install_arg' => ['linux' => '-install --installPath=/opt/mesh']]);
$mCfg()->refresh();
$ok($mCfg()->get()['agent_ids'] === ['windows/arm64' => 40] && $mCfg()->get()['install_args'] === ['linux' => ['-install', '--installPath=/opt/mesh']] && $mCfg()->get()['server_cert_pin'] === '', 'the corrections are saved, and an empty pin clears the pin');
[$c, $body] = $mPg();
$ok(str_contains($body, 'rmm-mesh-advanced" data-rmm-mesh-advanced open') && preg_match('/name="mesh_agent_id\[windows\/arm64\]"[^>]*value="40"/', $body) === 1 && str_contains($body, 'value="-install --installPath=/opt/mesh"'), 'with corrections stored the advanced section opens and shows them in plain boxes');
$mAdm($full + ['mesh_group_id' => $mGroup, 'mesh_server_cert_pin' => '', 'mesh_agent_id' => ['windows/arm64' => ''], 'mesh_install_arg' => ['linux' => '']]);
$mCfg()->refresh();
$ok($mCfg()->get()['agent_ids'] === [] && $mCfg()->get()['install_args'] === [], 'empty boxes clear the corrections (the built-in tables apply again)');

// ============================================================ RivetCore 1.0.0-rc.15: the device group id in MeshCentral's own URL form
[$c, $body] = $mPg();
$ok(str_contains($body, 'Paste the download address from MeshCentral') && str_contains($body, 'Windows 64-bit installer') && str_contains($body, '64 characters, not hex') && str_contains($body, 'invitation key'), 'the group id help: paste the download address (device group, Add Agent, Windows 64-bit installer) or its meshid, 64 characters not hex, treat it like an invitation key');
$ok(!preg_match('/id="mc_group"[^>]*\b(pattern|inputmode)=/', $body) && preg_match('/id="mc_group"[^>]*maxlength="2000"/', $body) === 1 && !str_contains($body, 'The long hex after'), 'no hex-only restriction on the field (no pattern, room for a pasted address)');
$ok(str_contains($body, 'Leave empty when the server certificate is issued by a public CA such as Let\'s Encrypt') && str_contains($body, 'Use a pin only for a private CA or a self-signed certificate'), 'the pin help: leave empty for a publicly trusted certificate, pin only a private CA or self-signed');
$ok(preg_match('/<input type="checkbox"[^>]*id="mc_group_show"[^>]*data-rmm-mesh-show="mc_group"/', $body) === 1, 'the show toggle for the (masked) group id is there');
// a fresh random id (both "@" and "$" in it; made at run time, never committed)
do { $rawA = random_bytes(48); $urlA = strtr(base64_encode($rawA), ['+' => '@', '/' => '$']); } while (!str_contains($urlA, '@') || !str_contains($urlA, '$'));
do { $rawB = random_bytes(48); $urlB = strtr(base64_encode($rawB), ['+' => '@', '/' => '$']); } while ($urlB === $urlA);
$mSaveGroup = function (string $v, string $pin = '') use ($mAdm, $mCfg, $mPg, $full) { $mAdm($full + ['mesh_group_id' => $v, 'mesh_server_cert_pin' => $pin]); $mCfg()->refresh(); return $mPg()[1]; };
$mAddr = fn (string $id, string $host = 'mesh.example.test', string $extra = '') => "https://$host/meshagents?id=4&meshid=$id&installflags=0$extra";
// 1. the bare 64-character meshid
$body = $mSaveGroup($urlA);
$g = $mCfg()->get();
$ok($g['group_id'] === bin2hex($rawA) && $g['group_id_url'] === $urlA, 'bare meshid: stored as hex, the URL form kept verbatim');
$ok(str_contains($body, 'value="' . $urlA . '"') && preg_match('/<input type="password"[^>]*id="mc_group"[^>]*value="' . preg_quote($urlA, '/') . '"/', $body) === 1 && !str_contains($body, 'value="' . bin2hex($rawA) . '"'), 'the field (still masked) reads back mesh_group_id_url, not the hex');
$ok($mMsg($body) === '', 'a clean save has no warning');
$view = $rmm->remoteActions()->meshSettings(new RmmPrincipal(1, 'admin'));
$ok($view->ok && ($view->data['mesh_group_id_url'] ?? '') === $urlA && ($view->data['mesh_group_id'] ?? '') === bin2hex($rawA), 'Core\'s settings read model answers mesh_group_id (canonical hex) and mesh_group_id_url');
// 2. the whole pasted download address (same host): only the meshid is taken out of it, no warning
$body = $mSaveGroup($mAddr($urlB));
$ok($mCfg()->get()['group_id'] === bin2hex($rawB) && $mCfg()->get()['group_id_url'] === $urlB && str_contains($body, 'value="' . $urlB . '"') && !str_contains($body, 'meshagents?id='), 'the whole pasted download address: the meshid is extracted, stored and read back as the bare URL form');
$ok($mMsg($body) === '', 'and a pasted address for the saved Mesh host gives no warning');
// 3. the whole address for a DIFFERENT host: saved, with a warning in the card
$mAdm($full + ['mesh_group_id' => $mAddr($urlA, 'other.example.test'), 'mesh_server_cert_pin' => '']);
$mCfg()->refresh();
[, $body] = $mPg();
$ok($mCfg()->get()['group_id'] === bin2hex($rawA) && $mCfg()->get()['group_id_url'] === $urlA, 'an address for another host is still saved');
$ok(str_contains($mMsg($body), 'Saved, with a warning') && str_contains($mMsg($body), 'other.example.test') && str_contains($mMsg($body), 'mesh.example.test') && str_contains($body, 'data-rmm-scale-msg') && str_contains($body, 'alert-warning'), 'and the card shows Core\'s warning naming both hosts: "' . $mMsg($body) . '"');
[, $body] = $mPg();
$ok($mMsg($body) === '', 'the warning is shown once');
// 4. the hex form (any case) and mesh//...: accepted; the field reads back the URL form of the same id
$body = $mSaveGroup(strtoupper(bin2hex($rawB)));
$ok($mCfg()->get()['group_id'] === bin2hex($rawB) && $mCfg()->get()['group_id_url'] === $urlB && str_contains($body, 'value="' . $urlB . '"'), 'hex (upper case): accepted, and read back in the URL form');
$body = $mSaveGroup('mesh//' . $urlA);
$ok($mCfg()->get()['group_id'] === bin2hex($rawA) && $mCfg()->get()['group_id_url'] === $urlA, 'mesh//<64 characters>: accepted');
$body = $mSaveGroup("  \n" . $urlB . "\r\n ");
$ok($mCfg()->get()['group_id'] === bin2hex($rawB), 'whitespace around the value is trimmed');
$body = $mSaveGroup($mAddr($urlA));
$g = $mCfg()->get();
$ok($g['group_id'] === bin2hex($rawA), 'back to id A');
// 5. hostile or malformed values are refused with Core\'s one sentence, nothing is written, what was typed is put back escaped
$bad = [
    'inner whitespace' => $urlA . ' ' . $urlB,
    'a newline inside' => substr($urlA, 0, 30) . "\n" . substr($urlA, 30),
    'a double quote' => '"' . $urlA,
    'a single quote' => "'" . $urlA . "'",
    'an ampersand' => $urlA . '&installflags=0',
    'a fragment' => $urlA . '#x',
    '63 characters' => substr($urlA, 0, 63),
    '65 characters' => $urlA . 'A',
    'another alphabet' => substr($urlA, 0, 63) . '!',
    'a standard base64 id (+ and /)' => strtr($urlA, ['@' => '+', '$' => '/']),
    'mesh// with a short id' => 'mesh//' . substr($urlA, 1),
    'a javascript url' => 'javascript:alert(1)',
    'a script tag' => '"><script>alert(1)</script>',
    'an address without meshid' => 'https://mesh.example.test/meshagents?id=4&installflags=0',
    'an address with id 0' => 'https://mesh.example.test/meshagents?id=0&meshid=' . $urlA . '&installflags=0',
    'an address with id 999' => 'https://mesh.example.test/meshagents?id=999&meshid=' . $urlA . '&installflags=0',
    'an address with credentials' => 'https://u:p@mesh.example.test/meshagents?id=4&meshid=' . $urlA,
    'a non http scheme' => 'ftp://mesh.example.test/meshagents?id=4&meshid=' . $urlA,
    'more than 2000 characters' => str_repeat('A', 2100),
];
$stored = json_encode($mCfg()->get()); $rowBefore = json_encode($rows('SELECT * FROM endpoint_agent_mesh_settings'));
foreach ($bad as $label => $v) {
    $body = $mSaveGroup($v);
    $ok(json_encode($mCfg()->get()) === $stored && json_encode($rows('SELECT * FROM endpoint_agent_mesh_settings')) === $rowBefore && str_contains($mMsg($body), 'The Mesh device group id must be the meshid from the MeshCentral download address'), "refused ($label): nothing written, Core's sentence in the card");
}
[, $body] = $mPg();
$mAdm($full + ['mesh_group_id' => '"><script>alert(1)</script>', 'mesh_server_cert_pin' => '']);
[, $body] = $mPg();
$ok(!str_contains($body, '<script>alert(1)</script>') && str_contains($body, '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;'), 'a hostile value put back into the field is escaped');
// 6. clearing: an empty value removes the group (and its URL form)
$body = $mSaveGroup('');
$ok($mCfg()->get()['group_id'] === '' && $mCfg()->get()['group_id_url'] === '', 'an empty group id clears both forms');
$body = $mSaveGroup($mGroup);
$ok($mCfg()->get()['group_id'] === $mGroup && $mCfg()->get()['group_id_url'] === $mGroupUrl, 'a hex group id saved earlier has its URL form derived (what an rc.12 to rc.14 row looks like)');
// 7. a settings row from before migration 0024 still works (the column is absent): the URL form is derived from the hex
$q('ALTER TABLE endpoint_agent_mesh_settings DROP COLUMN group_id_url');
$rmm->meshAgentSettings()->refresh();
$ok($rmm->meshAgentSettings()->get()['group_id_url'] === $mGroupUrl, 'before migration 0024 the URL form is derived from the stored hex');
[$c, $body] = $mPg();
$ok($c === 200 && str_contains($body, 'value="' . $mGroupUrl . '"') && !str_contains($body, 'Fatal error'), 'and Administration still loads and shows it');
$q("ALTER TABLE endpoint_agent_mesh_settings ADD COLUMN `group_id_url` varchar(64) NOT NULL DEFAULT '' AFTER `group_id`");
$rmm->meshAgentSettings()->refresh();

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
$ok(str_contains($body, 'data-rmm-mesh-pin-note') && str_contains($body, 'not trusted by the system certificate store') && str_contains($body, 'private CA or a self-signed certificate') && !str_contains($body, 'data-rmm-mesh-pin-result="trusted"'), 'the self-signed local TLS server gets Core\'s note (not publicly trusted: compare the pin before saving) and the pin may be used');
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
// a publicly trusted certificate (Core answers publicly_trusted + its note): the card shows the note and offers NO way to save the pin
$trustedHtml = rivetRmmMeshPinProposalHtml(['ok' => true, 'pin' => str_repeat('ab', 48), 'sha256' => str_repeat('cd', 32), 'subject' => 'mesh.example.test', 'not_after' => '2099-01-01T00:00:00Z', 'publicly_trusted' => true, 'note' => 'This certificate is publicly trusted: no pin needed. Leave the pin empty so installs keep working when the certificate is renewed.']);
$ok(str_contains($trustedHtml, 'data-rmm-mesh-pin-result="trusted"') && str_contains($trustedHtml, 'This certificate is publicly trusted: no pin needed') && !str_contains($trustedHtml, 'data-rmm-mesh-use-pin') && !str_contains($trustedHtml, 'Put this hash') && !str_contains($trustedHtml, str_repeat('ab', 48)) && !str_contains($trustedHtml, 'data-rmm-mesh-pin-value'), 'publicly_trusted: the note is shown, there is no button and no hash to copy into the pin field');
$untrustedHtml = rivetRmmMeshPinProposalHtml(['ok' => true, 'pin' => str_repeat('ab', 48), 'publicly_trusted' => false, 'note' => 'N-O-T-E <b>x</b>']);
$ok(str_contains($untrustedHtml, 'data-rmm-mesh-use-pin') && str_contains($untrustedHtml, 'N-O-T-E &lt;b&gt;x&lt;/b&gt;'), 'not publicly trusted: the button is offered and Core\'s note is escaped');
// ... and end to end: Core's own verified handshake. The system trust store of this machine cannot be changed and a public CA (Let's Encrypt) is out of reach from here, so "publicly trusted"
// is produced with a LOCAL CA: a second php -S started with curl.cainfo = that CA (the bundle PHP's curl hands to libcurl), against a stand-in whose certificate that CA signed. That proves the
// page logic and the handshake wiring, NOT Let's Encrypt itself.
$pt = sys_get_temp_dir() . '/mesh_pt_' . bin2hex(random_bytes(4)); mkdir($pt, 0700, true);
exec('openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=rc15 local test CA" -addext "basicConstraints=critical,CA:TRUE" -keyout ' . escapeshellarg("$pt/ca.key") . ' -out ' . escapeshellarg("$pt/ca.pem") . ' 2>/dev/null', $oo, $rc1);
exec('openssl req -newkey rsa:2048 -nodes -subj "/CN=mesh.public.test" -keyout ' . escapeshellarg("$pt/p.key") . ' -out ' . escapeshellarg("$pt/p.csr") . ' 2>/dev/null', $oo, $rc2);
file_put_contents("$pt/ext.cnf", "subjectAltName=IP:127.0.0.1,DNS:localhost\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n");
exec('openssl x509 -req -in ' . escapeshellarg("$pt/p.csr") . ' -CA ' . escapeshellarg("$pt/ca.pem") . ' -CAkey ' . escapeshellarg("$pt/ca.key") . ' -CAcreateserial -days 2 -extfile ' . escapeshellarg("$pt/ext.cnf") . ' -out ' . escapeshellarg("$pt/p.pem") . ' 2>/dev/null', $oo, $rc3);
$haveCa = $rc1 === 0 && $rc2 === 0 && $rc3 === 0 && is_file("$pt/p.pem") && trim((string) @shell_exec('command -v python3')) !== '';
$ok($haveCa, 'a local CA and a server certificate it signed (SAN IP 127.0.0.1) for the publicly-trusted probe');
if ($haveCa) {
    $ps = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $caPort = (int) explode(':', stream_socket_get_name($ps, false))[1]; fclose($ps);
    $caProc = proc_open(['python3', '-I', "$root/tests/support/tls_stub_server.py", (string) $caPort, "$pt/p.pem", "$pt/p.key"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$pt/srv.log", 'a'], 2 => ['file', "$pt/srv.log", 'a']], $ppipes);
    register_shutdown_function(function () use (&$caProc) { if (is_resource($caProc)) { proc_terminate($caProc); proc_close($caProc); } });
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $caPort)) { break; } usleep(100000); }
    $caPin = strtolower(str_replace(':', '', trim((string) shell_exec('openssl x509 -in ' . escapeshellarg("$pt/p.pem") . ' -noout -fingerprint -sha384 | cut -d= -f2'))));
    $web2 = ea_start_php($root . '/tests/rmm_golden/router.php', ['RIVETMSP_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", "curl.cainfo=$pt/ca.pem"]);
    $wb2 = "http://127.0.0.1:{$web2['port']}";
    $mAdm2 = fn (array $f) => web($wb2, 'POST', '/admin/post.php', $SS['admin'], $f + ['csrf_token' => 'csrftok1'], $REF1);
    $mPg2 = fn () => web($wb2, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin']);
    $rmm->settings()->set(['mesh_url' => 'https://127.0.0.1:' . $caPort]);
    $fresh();
    $rowBefore = json_encode($rows('SELECT * FROM endpoint_agent_mesh_settings'));
    // 1. a certificate that the web server's trust bundle verifies: the note says no pin is needed and NOTHING offers to save the pin
    $mAdm2(['probe_mesh_pin' => '1']);
    [, $cd] = $mPg2();
    $ok(str_contains($cd, 'data-rmm-mesh-pin-result="trusted"') && str_contains($cd, 'This certificate is publicly trusted: no pin needed. Leave the pin empty so installs keep working when the certificate is renewed.'), 'publicly_trusted (Core\'s verified handshake against a local CA): the card shows Core\'s note, "no pin needed"');
    $ok(!str_contains($cd, 'data-rmm-mesh-use-pin') && !str_contains($cd, 'Put this hash in the pin field') && !str_contains($cd, $caPin) && !str_contains($cd, 'data-rmm-mesh-pin-value'), 'and there is no button to use the pin and the hash is not even printed');
    $ok(preg_match('/id="mc_pin"[^>]*value=""/', $cd) === 1 && json_encode($rows('SELECT * FROM endpoint_agent_mesh_settings')) === $rowBefore, 'the pin field stays empty and nothing was saved');
    // 2. the control: the very same server read by the first web server (whose trust store does not hold that CA) is NOT publicly trusted, so it is the CA that flipped the answer
    $mAdm(['probe_mesh_pin' => '1']);
    [, $cd] = $mPg();
    $ok(str_contains($cd, 'data-rmm-mesh-pin-result="ok"') && str_contains($cd, $caPin) && str_contains($cd, 'not trusted by the system certificate store') && str_contains($cd, 'data-rmm-mesh-use-pin'), 'control: the same certificate read by a web server that does not trust the local CA is not publicly trusted, and the pin is offered');
    // 3. the self-signed stand-in read by the web server that trusts only the local CA is not publicly trusted either
    $tls2 = new MockTlsServer();
    $rmm->settings()->set(['mesh_url' => $tls2->url()]);
    $fresh();
    $mAdm2(['probe_mesh_pin' => '1']);
    [, $cd] = $mPg2();
    $ok(str_contains($cd, 'data-rmm-mesh-pin-result="ok"') && str_contains($cd, $tls2->sha384) && !str_contains($cd, 'data-rmm-mesh-pin-result="trusted"'), 'a self-signed certificate is not publicly trusted even where the local CA is trusted');
    $tls2->stop();
    $rmm->settings()->set(['mesh_url' => 'https://mesh.example.test']);
    $fresh();
}

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
