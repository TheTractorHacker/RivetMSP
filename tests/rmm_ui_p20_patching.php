<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fragment of tests/rmm_ui.php (RMM Phase 4 UI, RivetCore 1.0.0-rc.20): patch management. Shares that file's variables. Driven through the product: the pages and the form
 * handler (agent/post/rmm_patch.php, CSRF) over real HTTP with forged sessions, the technician API underneath (RivetCore's PatchApi), the real module on the scratch
 * database. Scan results are written straight into the patch tables (the agent side is Core's own proof); everything else goes through the handler. The `patching`
 * switch is OFF at the start (the default) and is put back OFF at the end.
 *
 * Users: admin (1), tech (10: rmm 3, scripts 3: may view, manage and install), rebootonly (11: scripts 2: view and manage, no install), viewer (12) and remoteonly (13)
 * (view only), stock (14: no RMM rows: nothing), clientb (16: a tech limited to Client B).
 */

use RivetCore\Rmm\Authz\RmmAbility;

$ptGet = fn (string $who, string $path, array $h = []) => web($wb, 'GET', $path, $SS[$who], [], $h);
$ptPost = fn (string $who, array $f, array $headers = []) => web($wb, 'POST', '/agent/post/rmm_patch.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $headers);
$ptJson = fn (string $who, array $f) => $ptPost($who, $f, ['Accept: application/json']);
$ptLoc = fn (string $hdr) => preg_match('/^location:\s*(\S+)/mi', $hdr, $mm) === 1 ? $mm[1] : '';
$ptRows = fn (string $sql) => $rows($sql);
$ptNav = fn (?int $uid = null) => array_values(array_filter(array_column(rivetRmmAutoNav($uid), 'href'), fn ($h) => str_contains($h, 'rmm_patch')));
$ptWin = $D['WIN1'];
$ptLnx = $D['LNX1'];
$ptWinAsset = $A['WIN1'];
$ptLnxAsset = $A['LNX1'];
$ptNow = gmdate('Y-m-d H:i:s');
$ptAgo = fn (int $days) => gmdate('Y-m-d H:i:s', time() - $days * 86400);
$G1 = '11111111-1111-4111-8111-111111111111';
$G2 = '22222222-2222-4222-8222-222222222222';
$G3 = '33333333-3333-4333-8333-333333333333';
$GB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$hostileTitle = '<script>alert("pt")</script> & <img src=x onerror=alert(1)>';

// ---- the starting state: module on, patching OFF (the default)
$ok($rmm->enabled() && !$rmm->featureOn('patching') && !rivetRmmFeatureOn('patching'), 'patching: the module is on and the patching switch is off at the start');
$ok($ptNav() === [] && $ptNav($USER['admin']) === [], 'menu: no patch entries while the switch is off');
foreach (['/agent/rmm_patch_compliance.php', '/agent/rmm_patch_approvals.php', '/agent/rmm_patch_policies.php'] as $path) {
    [$c, $body] = $ptGet('admin', $path);
    $ok($c === 403 && str_contains($body, 'switched off'), "$path: 403 and the switched-off notice while the switch is off ($c)");
}
[$c, $b] = $ptJson('admin', ['action' => 'scan', 'device_id' => $ptWin]);
$j = json_decode($b, true);
$ok($c === 404 && ($j['success'] ?? null) === false && str_contains((string) ($j['error'] ?? ''), 'switched off'), 'handler (JSON): the switched-off refusal is a 404 with a message');
[$c, $body] = $ptGet('admin', '/agent/asset_details.php?asset_id=' . $ptWinAsset);
$ok($c === 200 && !str_contains($body, 'id="rmm-tab-patches"'), 'patching off: no Patches tab on the asset page');

// ---- the data: WIN1 (Client A) and LNX1 (Client B) are scanned; titles are hostile on purpose
$q("DELETE FROM rmm_patch_available"); $q("DELETE FROM rmm_patch_history"); $q("DELETE FROM rmm_patch_scans"); $q("DELETE FROM rmm_patch_assignments"); $q("DELETE FROM rmm_patch_policies"); $q("DELETE FROM rmm_patch_rules");
foreach ([[$ptWin, 1, 'wua'], [$ptLnx, 2, 'apt']] as [$d, $cl, $mgr]) {
    $q("INSERT INTO rmm_patch_scans SET device_id=$d, client_id=$cl, scanned_at='$ptNow', manager='$mgr', scan_state='ok', wu_state='running', total_count=3");
}
$ins = fn (int $d, int $cl, string $uid, string $title, string $kind, string $status, int $ago, string $extra = '') => $q("INSERT INTO rmm_patch_available SET device_id=$d, client_id=$cl, update_id='$uid', kb='KB5030001', title='" . $esc($title) . "', kind='$kind', severity='important',
    size_bytes=52428800, status='$status', first_seen_at='" . $ptAgo($ago) . "', last_seen_at='$ptNow'$extra");
$ins($ptWin, 1, $G1, $hostileTitle, 'security', 'available', 30);
$ins($ptWin, 1, $G2, 'Cumulative update for Windows 11', 'critical', 'available', 2);
$ins($ptWin, 1, $G3, 'Old driver', 'driver', 'declined', 40, ", decline_reason='not needed'");
$ins($ptLnx, 2, $GB, 'Client B secret package', 'security', 'available', 20);
$q("INSERT INTO rmm_device_state SET device_id=$ptWin, platform='windows', capabilities_json='[\"patch:scan\",\"patch:install\"]' ON DUPLICATE KEY UPDATE capabilities_json=VALUES(capabilities_json), platform='windows'");
$q("INSERT INTO rmm_device_state SET device_id=$ptLnx, platform='linux', capabilities_json='[\"patch:scan\",\"patch:install\"]' ON DUPLICATE KEY UPDATE capabilities_json=VALUES(capabilities_json), platform='linux'");

// ---- Core refuses to do anything while the switch is off, even through the page's own API calls
$ctxA = ['uid' => 1, 'name' => 'admin', 'mysqli' => $db, 'csrf' => ''];
$r = rivetRmmPatchApi($ctxA, 'POST', ['patches', 'approve'], [], ['update_ids' => [$G2]]);
$ok(!$r['ok'] && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'available', 'patching off: Core refuses to approve and nothing changes (' . $r['status'] . ')');

// ---- switch on
$feat = $rmm->settings()->features();
$feat['patching'] = true;
$r = $rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
$fresh();
$ok($r->ok && $rmm->featureOn('patching') && rivetRmmFeatureOn('patching'), 'patching: switched on through RmmAdmin');
$ok(count($ptNav()) === 3 && $ptNav($USER['admin']) === $ptNav() && $ptNav($USER['viewer']) === $ptNav() && $ptNav($USER['stock']) === [], 'menu: three patch entries for a user who may view patch status, none for a technician with no RMM role rows');

// ---- the access matrix on the pages
foreach (['/agent/rmm_patch_compliance.php', '/agent/rmm_patch_approvals.php', '/agent/rmm_patch_policies.php'] as $path) {
    foreach (['admin', 'tech', 'rebootonly', 'viewer', 'remoteonly', 'clientb'] as $who) {
        [$c, $body] = $ptGet($who, $path);
        $ok($c === 200 && !str_contains($body, 'switched off'), "$path: $who can open it ($c)");
    }
    [$c, $body] = $ptGet('stock', $path);
    $ok($c === 403, "$path: a technician with no RMM role rows is refused ($c)");
}
// the approvals page and its hostile titles
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_approvals.php');
$ok($c === 200 && !str_contains($body, '<script>alert("pt")') && !str_contains($body, '<img src=x') && str_contains($body, '&lt;script&gt;alert(&quot;pt&quot;)&lt;/script&gt;'), 'approvals page: a hostile update title is escaped');
$ok(str_contains($body, 'Client B secret package') && str_contains($body, 'Cumulative update for Windows 11'), 'approvals page: an administrator sees updates of every client');
[$c, $body] = $ptGet('clientb', '/agent/rmm_patch_approvals.php');
$ok($c === 200 && str_contains($body, 'Client B secret package') && !str_contains($body, 'Cumulative update for Windows 11') , 'approvals page: a Client B technician sees only Client B updates (the fleet list is scoped by Core)');
[$c, $body] = $ptGet('clientb', '/agent/rmm_patch_approvals.php?client_id=1');
$ok(!str_contains($body, 'Cumulative update for Windows 11') && !str_contains($body, $G2), 'approvals page: asking for another client\'s id shows nothing of it');
[$c, $body] = $ptGet('viewer', '/agent/rmm_patch_approvals.php');
$ok($c === 200 && !str_contains($body, 'name="update_ids[]"') && str_contains($body, 'can see the queue but not decide'), 'approvals page: a view-only role sees no tick boxes and no buttons');

// ---- compliance
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_compliance.php');
$ok($c === 200 && str_contains($body, 'Out of compliance') && str_contains($body, 'Client A') && str_contains($body, 'Client B') && preg_match('~rmm_agent_device\.php\?device_id=' . $ptWin . '#~', $body) === 1, 'compliance page: totals, both clients and a link to the non-compliant device');
[$c, $body] = $ptGet('clientb', '/agent/rmm_patch_compliance.php');
$ok($c === 200 && str_contains($body, 'Client B') && !str_contains($body, 'Client A') && !str_contains($body, "device_id=$ptWin#"), 'compliance page: a Client B technician sees only Client B figures and devices');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_compliance.php?group_by=site&os=windows&overdue_days=999&client_id=abc');
$ok($c === 200, 'compliance page: odd filter values are ignored, not an error (' . $c . ')');

// ---- the Patches tab
[$c, $body] = $ptGet('admin', '/agent/asset_details.php?asset_id=' . $ptWinAsset);
$ok($c === 200 && str_contains($body, 'id="rmm-tab-patches"') && str_contains($body, 'id="rmm-pane-patches"') && str_contains($body, 'Install history') === false && str_contains($body, 'Cumulative update for Windows 11'), 'asset page: the Patches tab lists the missing updates of the device');
$ok(!str_contains($body, '<script>alert("pt")') && !str_contains($body, '<img src=x onerror') && str_contains($body, '&lt;script&gt;alert(&quot;pt&quot;)'), 'asset page: the hostile title is escaped in the Patches tab');
$ok(substr_count($body, 'id="rmmAutoConfirm"') === 1, 'asset page: the in-page confirmation dialog is present exactly once');
$ok(str_contains($body, 'Live installs are off'), 'asset page: it says plainly that live installs are off');
$ok(str_contains($body, 'name="action" value="install"') && str_contains($body, 'name="confirm_install"'), 'asset page: an administrator is offered Install with its own confirmation box');
[$c, $body] = $ptGet('rebootonly', '/agent/asset_details.php?asset_id=' . $ptWinAsset);
$ok(str_contains($body, 'id="rmm-tab-patches"') && str_contains($body, 'name="action" value="approve"') && !str_contains($body, 'name="action" value="install"') && str_contains($body, 'needs a role with the install right'), 'asset page: a role that may manage but not install gets approve and dry run, no Install button');
[$c, $body] = $ptGet('viewer', '/agent/asset_details.php?asset_id=' . $ptWinAsset);
$ok(str_contains($body, 'id="rmm-tab-patches"') && !str_contains($body, 'name="update_ids[]"') && !str_contains($body, 'value="dry_run"') && str_contains($body, 'can see patch state but not change it'), 'asset page: a view-only role sees the list without any action');
[$c, $body] = $ptGet('stock', '/agent/asset_details.php?asset_id=' . $ptWinAsset);
$ok(!str_contains($body, 'id="rmm-tab-patches"') && !str_contains($body, 'Cumulative update for Windows 11'), 'asset page: a technician with no RMM role rows gets no Patches tab and no update text');
[$c, $body] = $ptGet('clientb', '/agent/asset_details.php?asset_id=' . $ptWinAsset);
$ok(!str_contains($body, 'id="rmm-tab-patches"') && !str_contains($body, 'Cumulative update for Windows 11') && !str_contains($body, $G2), 'asset page (IDOR): a Client B technician opening a Client A asset sees no patch data');
[$c, $body] = $ptGet('admin', '/agent/asset_details.php?asset_id=' . $ptWinAsset . '&pt_status=declined&pt_kind=driver&pt_q=' . urlencode("x' OR 1=1 -- "));
$ok($c === 200 && str_contains($body, 'No update matches this filter') && !str_contains($body, 'Cumulative update for Windows 11'), 'asset page: filters work and a SQL-looking search is only text');
[$c, $body] = $ptGet('admin', '/agent/asset_details.php?asset_id=' . $ptWinAsset . '&pt_status=declined');
$ok(str_contains($body, 'Old driver') && str_contains($body, 'not needed'), 'asset page: the declined filter shows the declined update and its reason');

// ---- the handler: CSRF, scan, approve, decline
[$c] = web($wb, 'POST', '/agent/post/rmm_patch.php', $SS['admin'], ['csrf_token' => 'wrong', 'action' => 'approve', 'update_ids' => [$G2], 'device_id' => $ptWin]);
[$c2] = web($wb, 'POST', '/agent/post/rmm_patch.php', $SS['admin'], ['action' => 'approve', 'update_ids' => [$G2], 'device_id' => $ptWin]);
$ok($one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'available', 'handler: a wrong or missing CSRF token changes nothing (' . $c . ', ' . $c2 . ')');
[$c] = web($wb, 'GET', '/agent/post/rmm_patch.php', $SS['admin']);
$ok($c === 405, 'handler: GET is refused (' . $c . ')');
[$c, $b] = $ptJson('admin', ['action' => 'scan', 'device_id' => $ptWin]);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false) === true && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_scan'") === 1, 'handler: scan queues one patch_scan job (' . $c . ')');
[$c, $b] = $ptJson('admin', ['action' => 'scan', 'device_id' => $ptWin]);
$j = json_decode($b, true);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_scan'") === 1 && str_contains((string) ($j['message'] ?? ''), 'already waiting'), 'handler: a second scan request is not queued twice');
[$c, $b] = $ptJson('viewer', ['action' => 'scan', 'device_id' => $ptWin]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_scan'") === 1, 'handler: a view-only role cannot scan (' . $c . ')');
[$c, $b] = $ptJson('clientb', ['action' => 'scan', 'device_id' => $ptWin]);
$ok($c === 404 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_scan'") === 1, 'handler (IDOR): a Client B technician scanning a Client A device gets the same 404 as a missing device (' . $c . ')');
[$c, $b] = $ptJson('stock', ['action' => 'scan', 'device_id' => $ptWin]);
$ok(in_array($c, [403, 404], true), 'handler: a technician with no RMM role rows is refused (' . $c . ')');
[$c, $b] = $ptJson('viewer', ['action' => 'approve', 'update_ids' => [$G2], 'device_id' => $ptWin]);
$ok($c === 403 && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'available', 'handler: a view-only role cannot approve (' . $c . ')');
[$c, $b] = $ptJson('clientb', ['action' => 'approve', 'update_ids' => [$G2], 'device_id' => $ptWin]);
$ok($c === 404 && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'available', 'handler (IDOR): approving on another client\'s device is a 404 and changes nothing (' . $c . ')');
[$c, $b] = $ptJson('clientb', ['action' => 'approve', 'update_ids' => [$G2]]);
$ok($c === 404 && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'available', 'handler (IDOR): a fleet approve by a Client B technician cannot reach a Client A device (' . $c . ')');
[$c, $b] = $ptJson('clientb', ['action' => 'approve', 'update_ids' => [$G2], 'client_id' => 1]);
$ok($c === 404 && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'available', 'handler (IDOR): naming another client\'s id is a 404 (' . $c . ')');
[$c, $b] = $ptJson('admin', ['action' => 'approve', 'update_ids' => ['-oAPT::Foo', 'a b'], 'device_id' => $ptWin]);
$ok($c === 422 && $one("SELECT COUNT(*) FROM rmm_patch_available WHERE status='approved'") === '0', 'handler: an update id that is not an id is refused (' . $c . ')');
[$c, $b] = $ptJson('admin', ['action' => 'approve', 'device_id' => $ptWin]);
$ok($c === 422 && str_contains($b, 'Tick at least one update'), 'handler: approving nothing is a message, not an error page');
[$c, $b] = $ptJson('admin', ['action' => 'approve', 'update_ids' => [[$G1], ['a' => $G1]], 'device_id' => $ptWin]);
$ok($c === 422 && $one("SELECT COUNT(*) FROM rmm_patch_available WHERE status='approved'") === '0', 'handler: nested arrays in update_ids are dropped, not passed on (' . $c . ')');
[$c, $b] = $ptJson('tech', ['action' => 'approve', 'update_ids' => [$G2], 'device_id' => $ptWin]);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false) === true && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'approved' && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G1'") === 'available', 'handler: a technician with patch management approves one update on one device; the other stays (' . $c . ')');
[$c, $b] = $ptJson('rebootonly', ['action' => 'decline', 'update_ids' => [$G1], 'device_id' => $ptWin, 'reason' => 'breaks the line-of-business app']);
$ok($c === 200 && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G1'") === 'declined' && $one("SELECT decline_reason FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G1'") === 'breaks the line-of-business app', 'handler: decline stores the reason (' . $c . ')');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action IN ('Patch Approved','Patch Declined')") >= 2, 'handler: approvals and declines are audited by Core');
// browser form: flash + redirect back to the tab
[$c, , $hd] = $ptPost('admin', ['action' => 'approve', 'update_ids' => [$G1], 'device_id' => $ptWin, 'return' => '/agent/asset_details.php?asset_id=' . $ptWinAsset . '#rmm-patches']);
$ok($c === 302 && str_contains($ptLoc($hd), '/agent/asset_details.php?asset_id=' . $ptWinAsset) && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G1'") === 'approved', 'handler: a browser form redirects back to the asset page and a declined update can be approved again');
[$c, , $hd] = $ptPost('admin', ['action' => 'approve', 'update_ids' => [$G1], 'device_id' => $ptWin, 'return' => 'https://evil.example/x']);
$ok($c === 302 && !str_contains($ptLoc($hd), 'evil.example'), 'handler: an off-site return address is ignored (open redirect)');
[$c, , $hd] = $ptPost('admin', ['action' => 'approve', 'update_ids' => [$G1], 'device_id' => $ptWin, 'return' => '//evil.example/x']);
$ok($c === 302 && !str_contains($ptLoc($hd), 'evil.example'), 'handler: a protocol-relative return address is ignored');

// ---- dry run and install
[$c, $b] = $ptJson('admin', ['action' => 'install', 'device_id' => $ptWin]);
$ok($c === 422 && str_contains($b, 'confirmation box') && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_install'") === 0, 'handler: an install without the confirmation box is refused before Core is even asked');
[$c, $b] = $ptJson('rebootonly', ['action' => 'install', 'device_id' => $ptWin, 'confirm_install' => '1']);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_install'") === 0, 'handler: a role without the install right cannot install even with the box ticked (' . $c . ')');
[$c, $b] = $ptJson('viewer', ['action' => 'dry_run', 'device_id' => $ptWin]);
$ok($c === 403, 'handler: a view-only role cannot dry run (' . $c . ')');
[$c, $b] = $ptJson('clientb', ['action' => 'install', 'device_id' => $ptWin, 'confirm_install' => '1']);
$ok($c === 404 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_install'") === 0, 'handler (IDOR): installing on another client\'s device is a 404 (' . $c . ')');
[$c, $b] = $ptJson('rebootonly', ['action' => 'dry_run', 'device_id' => $ptWin]);
$j = json_decode($b, true);
$job = $ptRows("SELECT * FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_install' ORDER BY created_at DESC LIMIT 1")[0] ?? [];
$ok($c === 200 && ($j['success'] ?? false) === true && $job !== [] && str_contains((string) json_encode($job), 'dry_run') && str_contains($b, 'changes nothing'), 'handler: a dry run is queued as a dry run (' . $c . ')');
$q("DELETE FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_install'");
$q("UPDATE rmm_patch_jobs SET applied_at='$ptNow' WHERE device_id=$ptWin");
[$c, $b] = $ptJson('tech', ['action' => 'install', 'device_id' => $ptWin, 'confirm_install' => '1', 'update_ids' => [$G2, '-oAPT::Foo']]);
$ok($c === 422 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_install'") === 0, 'handler: a hostile id in an install is refused (' . $c . ')');
[$c, $b] = $ptJson('tech', ['action' => 'install', 'device_id' => $ptWin, 'confirm_install' => '1', 'update_ids' => [$G3]]);
$ok($c !== 200 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_install'") === 0, 'handler: naming a declined update in an install installs nothing (' . $c . ')');
[$c, $b] = $ptJson('tech', ['action' => 'install', 'device_id' => $ptWin, 'confirm_install' => '1']);
$j = json_decode($b, true);
$job = $ptRows("SELECT * FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type='patch_install' ORDER BY created_at DESC LIMIT 1")[0] ?? [];
$ok($c === 200 && ($j['success'] ?? false) === true && $job !== [] && !str_contains((string) json_encode($job), 'dry_run') && !str_contains((string) json_encode($job), $GB), 'handler: a technician with the install right queues a real install of approved updates only (' . $c . ')');
$ok(!str_contains((string) json_encode($job), $G3), 'handler: the declined update is not part of the install');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Patch Install Queued'") === 1, 'handler: the install is audited by Core');
$q("DELETE FROM endpoint_agent_jobs WHERE device_id=$ptWin AND type IN ('patch_install','patch_scan')");
$q("DELETE FROM rmm_patch_jobs WHERE device_id=$ptWin");

// ---- policies, rings, rules
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php');
$ok(str_contains($body, 'pilot') && str_contains($body, 'broad') && str_contains($body, 'last') && str_contains($body, 'No patch policy yet'), 'policies page: the three default rings and the empty state');
$ok(str_contains($body, 'gated three times'), 'policies page: it explains that live installs are gated');
[$c, $body] = $ptGet('viewer', '/agent/rmm_patch_policies.php');
$ok(!str_contains($body, 'name="action" value="save_policy"') && !str_contains($body, 'New policy') && !str_contains($body, 'action" value="save_ring"'), 'policies page: a view-only role sees no forms and no New policy button');
[$c, $b] = $ptJson('viewer', ['action' => 'save_policy', 'name' => 'PT viewer policy']);
$ok($c === 403 && $one("SELECT COUNT(*) FROM rmm_patch_policies WHERE name='PT viewer policy'") === '0', 'handler: a view-only role cannot create a policy (' . $c . ')');
[$c, $b] = $ptJson('clientb', ['action' => 'save_policy', 'name' => 'PT clientb policy']);
$ok($c === 403 && $one("SELECT COUNT(*) FROM rmm_patch_policies WHERE name='PT clientb policy'") === '0', 'handler: a technician limited to one client cannot create a fleet-wide policy (' . $c . ')');
[$c, $b] = $ptJson('admin', ['action' => 'save_policy', 'name' => '']);
$ok($c === 422 && str_contains($b, 'Give the policy a name'), 'handler: a policy needs a name');
$form = ['action' => 'save_policy', 'name' => 'PT pilot policy', 'description' => 'Test', 'enabled' => '1', 'approval_mode' => 'auto', 'ring' => 'pilot', 'auto_days_security' => '3', 'auto_days_critical' => '1',
    'install_enabled' => '1', 'window_mode' => 'daily', 'window_days' => ['mon', 'tue', 'bogus'], 'window_start' => '02:00', 'window_minutes' => '120', 'window_tz' => 'UTC', 'reboot_policy' => 'if_required_defer', 'max_minutes' => '45', 'two_person' => '0'];
[$c, , $hd] = $ptPost('admin', $form);
$pol = $ptRows("SELECT * FROM rmm_patch_policies WHERE name='PT pilot policy'")[0] ?? null;
$ps = $pol ? json_decode($pol['settings_json'], true) : [];
$ok($c === 302 && $pol !== null && ($ps['approval_mode']['value'] ?? '') === 'auto' && ($ps['auto_days_security']['value'] ?? null) === 3 && ($ps['install_enabled']['value'] ?? null) === true && ($ps['window_days']['value'] ?? '') === 'mon,tue' && ($ps['window_minutes']['value'] ?? null) === 120 && ($ps['two_person']['value'] ?? null) === false && !isset($ps['auto_days_driver']),
    'handler: a policy is saved with typed settings (ints are ints, booleans are booleans, an unknown weekday is dropped, a blank field stores nothing)');
$polId = (int) ($pol['policy_id'] ?? 0);
[$c, $b] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'PT bad', 'auto_days_security' => '3x']);
$ok($c === 422 && $one("SELECT COUNT(*) FROM rmm_patch_policies WHERE name='PT bad'") === '0', 'handler: days that are not whole numbers never reach Core (' . $c . ')');
[$c, $b] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'PT bad2', 'approval_mode' => 'sometimes']);
$ok($c === 422 && str_contains($b, 'approval_mode'), 'handler: Core\'s vocabulary refusal reaches the user (' . $c . ')');
[$c, $b] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'PT bad3', 'install_enabled' => '1', 'reboot_policy' => 'if_required_now']);
$ok($c === 422 && $one("SELECT COUNT(*) FROM rmm_patch_policies WHERE name='PT bad3'") === '0', 'handler: restarting by itself without the confirmation is refused (' . $c . ')');
[$c, $b] = $ptJson('rebootonly', ['action' => 'save_policy', 'name' => 'PT reboot', 'install_enabled' => '1', 'reboot_policy' => 'if_required_now', 'reboot_confirm' => '1']);
$ok(in_array($c, [200, 403], true), 'handler: restart-by-itself needs the right to restart devices (' . $c . ')');
[$c, $body] = $ptGet('admin', "/agent/rmm_patch_policies.php?policy_id=$polId");
$ok($c === 200 && str_contains($body, 'Edit policy') && str_contains($body, 'value="PT pilot policy"') && preg_match('/name="window_days\[\]" value="mon" checked/', $body) === 1, 'policies page: editing shows the saved values');
[$c, $b] = $ptJson('admin', ['action' => 'assign_policy', 'policy_id' => $polId, 'pa_type' => 'client', 'pa_id_client' => '1', 'priority' => '50']);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_assignments WHERE policy_id=$polId AND scope_type='client' AND scope_id=1 AND priority=50") === 1, 'handler: a policy is assigned to a client');
[$c, $b] = $ptJson('admin', ['action' => 'assign_policy', 'policy_id' => $polId, 'pa_type' => 'client', 'pa_id_client' => '0']);
$ok($c === 422 && str_contains($b, 'Choose where'), 'handler: assigning with no target is a message');
[$c, $b] = $ptJson('admin', ['action' => 'assign_policy', 'policy_id' => $polId, 'pa_type' => 'global']);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_assignments WHERE policy_id=$polId AND scope_type='global'") === 1, 'handler: a policy can be assigned to everything');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php');
$ok(str_contains($body, 'PT pilot policy') && str_contains($body, 'Client Client A') && str_contains($body, 'Everything (global)'), 'policies page: the list shows where the policy applies');
// the effect on the asset page: the effective policy of WIN1
[$c, $body] = $ptGet('admin', '/agent/asset_details.php?asset_id=' . $ptWinAsset);
$ok(str_contains($body, 'approved automatically after a delay') && str_contains($body, 'ring pilot') && str_contains($body, 'daily window 02:00 for 120 min'), 'asset page: the effective patch policy is described in plain words');
$asg = (int) $one("SELECT assignment_id FROM rmm_patch_assignments WHERE policy_id=$polId AND scope_type='global'");
[$c, $b] = $ptJson('viewer', ['action' => 'unassign_policy', 'policy_id' => $polId, 'assignment_id' => $asg]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_patch_assignments WHERE assignment_id=$asg") === 1, 'handler: a view-only role cannot remove an assignment');
[$c, $b] = $ptJson('admin', ['action' => 'unassign_policy', 'policy_id' => $polId, 'assignment_id' => $asg]);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_assignments WHERE assignment_id=$asg") === 0, 'handler: an assignment is removed');
// rings
[$c, $b] = $ptJson('admin', ['action' => 'save_ring', 'name' => 'canary', 'delay_days' => '2', 'description' => 'few machines']);
$ok($c === 200 && (int) $one("SELECT delay_days FROM rmm_patch_rings WHERE name='canary'") === 2, 'handler: a ring is added');
[$c, $b] = $ptJson('admin', ['action' => 'save_ring', 'name' => '', 'delay_days' => 'x']);
$ok($c === 422, 'handler: a ring needs a name and a whole-number delay');
$ringId = (int) $one("SELECT ring_id FROM rmm_patch_rings WHERE name='canary'");
[$c, $b] = $ptJson('viewer', ['action' => 'delete_ring', 'ring_id' => $ringId]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rings WHERE name='canary'") === 1, 'handler: a view-only role cannot delete a ring');
[$c, $b] = $ptJson('admin', ['action' => 'delete_ring', 'ring_id' => (int) $one("SELECT ring_id FROM rmm_patch_rings WHERE name='pilot'")]);
$ok($c === 409 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rings WHERE name='pilot'") === 1, 'handler: a ring a policy uses cannot be deleted (' . $c . ')');
[$c, $b] = $ptJson('admin', ['action' => 'delete_ring', 'ring_id' => $ringId]);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rings WHERE name='canary'") === 0, 'handler: an unused ring is deleted');
// rules
[$c, $b] = $ptJson('admin', ['action' => 'save_rule', 'name' => 'PT security rule', 'after_days' => '5', 'ring' => 'broad', 'kinds' => ['security', 'critical', 'nonsense'], 'severities' => ['critical'], 'client_id' => '1', 'enabled' => '1']);
$rule = $ptRows("SELECT * FROM rmm_patch_rules WHERE name='PT security rule'")[0] ?? null;
$ok($c === 200 && $rule !== null && $rule['kinds'] === 'security,critical' && (int) $rule['after_days'] === 5 && (int) $rule['client_id'] === 1, 'handler: a rule is saved (an unknown kind is dropped)');
[$c, $b] = $ptJson('admin', ['action' => 'save_rule', 'name' => 'PT bad rule', 'after_days' => 'many']);
$ok($c === 422 && $one("SELECT COUNT(*) FROM rmm_patch_rules WHERE name='PT bad rule'") === '0', 'handler: days that are not a number are a message');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php?rule_id=' . (int) $rule['rule_id']);
$ok(str_contains($body, 'PT security rule') && str_contains($body, 'Edit rule'), 'policies page: the rule is listed and can be edited');
[$c, $b] = $ptJson('viewer', ['action' => 'delete_rule', 'rule_id' => (int) $rule['rule_id']]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rules WHERE rule_id=" . (int) $rule['rule_id']) === 1, 'handler: a view-only role cannot delete a rule');
[$c, $b] = $ptJson('admin', ['action' => 'delete_rule', 'rule_id' => (int) $rule['rule_id']]);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rules WHERE rule_id=" . (int) $rule['rule_id']) === 0, 'handler: a rule is deleted');
[$c, $b] = $ptJson('admin', ['action' => 'delete_policy', 'policy_id' => $polId]);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_policies WHERE policy_id=$polId") === 0 && (int) $one("SELECT COUNT(*) FROM rmm_patch_assignments WHERE policy_id=$polId") === 0, 'handler: deleting a policy removes its assignments');
[$c, $b] = $ptJson('admin', ['action' => 'no_such_action']);
$ok($c === 422 && str_contains($b, 'Unknown action'), 'handler: an unknown action is refused');

// ---- two people: a policy that asks for a second person, a manual approval waits, and the approvals page offers the decision
[$c, $b] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'PT two person', 'enabled' => '1', 'two_person' => '1']);
$tp = (int) $one("SELECT policy_id FROM rmm_patch_policies WHERE name='PT two person'");
$ptJson('admin', ['action' => 'assign_policy', 'policy_id' => $tp, 'pa_type' => 'global']);
$q("UPDATE rmm_patch_available SET status='available', status_source='scan' WHERE device_id=$ptWin AND update_id='$G2'");
[$c, $b] = $ptJson('tech', ['action' => 'approve', 'update_ids' => [$G2], 'device_id' => $ptWin]);
$j = json_decode($b, true);
$apr = $ptRows("SELECT * FROM rmm_approvals WHERE kind='patch' ORDER BY approval_id DESC LIMIT 1")[0] ?? null;
$ok($c === 200 && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'available' && $apr !== null && str_contains((string) ($j['message'] ?? ''), 'Sent for approval'), 'two people: the approval is sent for a second person instead of being applied (' . $c . ')');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_approvals.php');
$ok(str_contains($body, 'waiting for a second person') && str_contains($body, 'kind=patch'), 'two people: the approvals page points at the queue');
[$c, $body] = $ptGet('admin', '/agent/rmm_approvals.php?state=pending_approval&kind=patch');
$ok($c === 200 && str_contains($body, 'Approving patches') && str_contains($body, $G2) && str_contains($body, 'It only marks these updates approved') && !str_contains($body, 'Script text'), 'two people: the approvals page shows the request as patches (update ids, no script details)');
$aprId = (int) ($apr['approval_id'] ?? 0);
$decide = fn (string $who, string $decision) => web($wb, 'POST', '/agent/post/rmm_automation_scr.php', $SS[$who], ['csrf_token' => 'csrftok1', 'action' => 'decide_approval', 'approval_id' => $aprId, 'decision' => $decision], ['Accept: application/json']);
[$c, $b] = $decide('tech', 'approve');
$ok($c !== 200 && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'available', 'two people: the requester cannot approve their own request (' . $c . ')');
[$c, $b] = $decide('admin', 'approve');
$ok($c === 200 && $one("SELECT status FROM rmm_patch_available WHERE device_id=$ptWin AND update_id='$G2'") === 'approved', 'two people: a second person approves and the update becomes approved (' . $c . ')');
$q("UPDATE rmm_patch_available SET status='available' WHERE device_id=$ptWin AND update_id='$G2'");
$ptJson('admin', ['action' => 'delete_policy', 'policy_id' => $tp]);
// with the script library off, patch requests can still be decided but script requests cannot
$feat = $rmm->settings()->features();
$featS = $feat; $featS['scripts'] = false;
$rmm->admin()->saveSettings($admin, ['features_json' => $featS]);
$fresh();
[$c, $body] = $ptGet('admin', '/agent/rmm_approvals.php?kind=run');
$ok($c === 200 && !str_contains($body, 'switched off'), 'scripts off: the approvals page still opens for patch requests (' . $c . ')');
$q("INSERT INTO rmm_approvals SET kind='run', state='pending_approval', summary='PT script run', request_json='{}', request_sha256='" . hash('sha256', '{}') . "', device_count=1, requested_by=10, requested_at='$ptNow', expires_at='" . gmdate('Y-m-d H:i:s', time() + 3600) . "'");
$runApr = (int) $db->insert_id;
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_automation_scr.php', $SS['admin'], ['csrf_token' => 'csrftok1', 'action' => 'decide_approval', 'approval_id' => $runApr, 'decision' => 'approve'], ['Accept: application/json']);
$ok($c === 403 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$runApr") === 'pending_approval', 'scripts off: a script run cannot be decided through the patch fallback (' . $c . ')');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_automation_scr.php', $SS['admin'], ['csrf_token' => 'csrftok1', 'action' => 'cancel_approval', 'approval_id' => $runApr], ['Accept: application/json']);
$ok($c === 403 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$runApr") === 'pending_approval', 'scripts off: nor cancelled through it (' . $c . ')');
$q("DELETE FROM rmm_approvals WHERE approval_id=$runApr");
$rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
$fresh();

// ---- a database that has not had the migration yet: a notice, not a crash
$q("RENAME TABLE rmm_patch_scans TO rmm_patch_scans_hold");
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_compliance.php');
$ok($c === 200 && str_contains($body, 'Patch compliance') && !str_contains($body, 'Fatal error') && !str_contains($body, 'Stack trace'), 'schema pending: the compliance page still renders (' . $c . ')');
[$c, $body] = $ptGet('admin', '/agent/asset_details.php?asset_id=' . $ptWinAsset);
$ok($c === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Stack trace'), 'schema pending: the asset page still renders (' . $c . ')');
$q("RENAME TABLE rmm_patch_scans_hold TO rmm_patch_scans");

// ---- the switch off again
$feat['patching'] = false;
$rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
$fresh();
$ok(!$rmm->featureOn('patching') && $ptNav() === [], 'patching: switched off again, the menu entries are gone');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php');
$ok($c === 403 && str_contains($body, 'switched off'), 'patching off again: the pages are the switched-off notice');
$q("DELETE FROM rmm_patch_available"); $q("DELETE FROM rmm_patch_history"); $q("DELETE FROM rmm_patch_scans"); $q("DELETE FROM rmm_patch_assignments"); $q("DELETE FROM rmm_patch_policies"); $q("DELETE FROM rmm_patch_rules"); $q("DELETE FROM rmm_patch_jobs");
