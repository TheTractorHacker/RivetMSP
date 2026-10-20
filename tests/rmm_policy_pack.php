<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Policy starter pack card (RivetCore 1.0.0-rc.26, Rmm\Policy\StarterPack\PolicyPackInstaller / Technician\PolicyPackActions), section 4b of the rc.27
 * adoption: includes/rmm_policy_pack_ui.php (view-model, card HTML), agent/post/rmm_policy_pack.php (plan/install/update/reset/uninstall), the "Starter"
 * badge on the Policies / Patch policies / Maintenance windows lists. Same scratch rules and environment as tests/endpoint_agent_lib.php.
 */
$sd = sys_get_temp_dir() . '/rmm_pp_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_policy_pack_ui.php";
require_once "$root/includes/rmm_ui_render.php";   // rmmH()
use RivetCore\Rmm\Authz\RmmPrincipal;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$f = $rmm->settings()->features(); $f['policies'] = true; $rmm->admin()->saveSettings($admin, ['features_json' => $f]);
$rmm->syncState();

$sdir = sys_get_temp_dir() . '/rmm_pp_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'service url');
$sidA = ea_forge_session($sdir, 1); $sidT = ea_forge_session($sdir, 10);
$REF = ['Referer: ' . $wb . '/agent/rmm_policies.php'];
$post = fn (array $f, string $sid = null, string $csrf = 'csrftok1') => web($wb, 'POST', '/agent/post/rmm_policy_pack.php', $sid ?? $sidA, $f + ['csrf_token' => $csrf], $REF);
$hdr = fn (string $headers, string $name) => preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $headers, $m) === 1 ? trim($m[1]) : '';
$plan = fn (string $op, array $f = [], string $sid = null) => $post(['action' => 'plan', 'op' => $op] + $f, $sid);

echo "\n--- catalog view-model ---\n";
$vm = rivetRmmPolicyPackView($rmm, $admin);
$ok($vm['ok'] && $vm['pack_id'] !== '' && $vm['pack_version'] !== '' && count($vm['groups']) > 0, 'the catalog loads, grouped, with a pack id and version');
$allItems = array_merge(...array_values($vm['groups']));
$ok(count($allItems) > 10, 'the bundled pack has a meaningful number of items (' . count($allItems) . ')');
$checkItem = null; $changeItem = null;
foreach ($allItems as $it) {
    if ($it['kind'] === 'check_policy' && $checkItem === null) { $checkItem = $it; }
    if ($it['kind'] === 'change_presets') { $changeItem = $it; }
}
$ok($checkItem !== null && $checkItem['state'] === 'not_installed', 'a check_policy item starts not_installed');
$ok($changeItem === null || ($changeItem['instance_wide'] === true && $changeItem['state'] === 'not_installed'), 'the change-presets item (if the pack ships one) is instance_wide');

echo "\n--- the page ---\n";
[$c, $b] = web($wb, 'GET', '/agent/rmm_policies.php', $sidA);
$ok($c === 200 && str_contains($b, 'id="policy-starter-pack"') && str_contains($b, 'Starter policies') && str_contains($b, 'Installing creates the policies switched off and assigned to nothing'), 'the page (admin) shows the Starter policies card with the safety notice');
$ok(str_contains($b, rmmH($checkItem['name'])) && str_contains($b, 'name="ids[]" value="' . $checkItem['id'] . '"'), 'the catalog item appears with its checkbox');
[$c, $b] = web($wb, 'GET', '/agent/rmm_policies.php', $sidT);
$ok($c === 200 && !str_contains($b, 'id="policy-starter-pack"'), 'a non-administrator (viewer-level tech role here) does not see the card');

echo "\n--- plan: previews, writes nothing ---\n";
$n0 = (int) $one('SELECT COUNT(*) FROM rmm_policies');
[$c, $b] = $plan('install', ['ids' => [$checkItem['id']]]);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false) && ($j['items'][0]['outcome'] ?? '') === 'added' && (int) $one('SELECT COUNT(*) FROM rmm_policies') === $n0, 'plan install previews "added" and writes nothing');
[$c, $b] = $plan('install', ['ids' => [$checkItem['id']]], $sidT);
$j = json_decode($b, true);
$ok($c === 403, 'a technician cannot even preview (' . $c . ')');

echo "\n--- install ---\n";
[$c, $hh] = $post(['pp_op' => 'install', 'ids' => [$checkItem['id']]]);
$ok($c === 302, 'install redirects');
$ok((int) $one('SELECT COUNT(*) FROM rmm_policies') === $n0 + 1, 'exactly one policy was created');
$row = $rows("SELECT * FROM rmm_policies WHERE name = '" . $esc($checkItem['name']) . "'")[0] ?? null;
$ok($row !== null && (int) $row['enabled'] === 0, 'created switched OFF');
$ok($row !== null && str_contains((string) $row['description'], ' [pp|' . $checkItem['id'] . '|'), 'the description carries the pack marker');
$ok(rivetRmmPolicyPackMarked((string) $row['description']), 'rivetRmmPolicyPackMarked() recognizes it');
[$c, $b] = web($wb, 'GET', '/agent/rmm_policies.php', $sidA);
$ok(str_contains($b, 'rmm-starter-badge') && str_contains($b, '>Starter<'), 'the Policies list shows the Starter badge for it');
$ok(!str_contains($b, '[pp|'), 'the raw marker is never shown on the page');

echo "\n--- install twice: unchanged, no duplicate ---\n";
[$c] = $post(['pp_op' => 'install', 'ids' => [$checkItem['id']]]);
$ok((int) $one('SELECT COUNT(*) FROM rmm_policies') === $n0 + 1, 'installing again creates nothing new');

echo "\n--- edited is never overwritten; reset takes the pack version back ---\n";
$pid = (int) $row['policy_id'];
$q("UPDATE rmm_policies SET name = 'Edited by hand' WHERE policy_id = $pid");
$vm2 = rivetRmmPolicyPackView($rmm, $admin);
$found = null; foreach (array_merge(...array_values($vm2['groups'])) as $it) { if ($it['id'] === $checkItem['id']) { $found = $it; } }
$ok($found !== null && $found['state'] === 'edited', 'the catalog now shows it as edited');
[$c] = $post(['pp_op' => 'update', 'ids' => [$checkItem['id']]]);
$ok((string) $one("SELECT name FROM rmm_policies WHERE policy_id = $pid") === 'Edited by hand', 'update skips an edited item (never overwritten)');
[$c] = $post(['pp_op' => 'reset', 'ids' => [$checkItem['id']]]);
$ok((string) $one("SELECT name FROM rmm_policies WHERE policy_id = $pid") === $checkItem['name'], 'reset takes the pack name back even though it was edited');

echo "\n--- uninstall ---\n";
[$c] = $post(['pp_op' => 'uninstall', 'ids' => [$checkItem['id']]]);
$ok((int) $one('SELECT COUNT(*) FROM rmm_policies') === $n0, 'uninstall removes the untouched, unassigned item');
[$c, $b] = web($wb, 'GET', '/agent/rmm_policies.php', $sidA);
$ok(!str_contains($b, 'rmm-starter-badge'), 'the badge is gone with it');

if ($changeItem !== null) {
    echo "\n--- instance-wide item needs confirm_instance_wide ---\n";
    [$c, $b] = $post(['pp_op' => 'install', 'ids' => [$changeItem['id']]]);
    $flash = $_SESSION[RIVET_RMM_PP_SESSION] ?? null;
    [$c2, $b2] = web($wb, 'GET', '/agent/rmm_policies.php', $sidA);
    $ok(str_contains($b2, 'Applies to all devices'), 'the instance-wide item is marked "Applies to all devices" on the card');
}

echo "\n--- CSRF ---\n";
$n1 = (int) $one('SELECT COUNT(*) FROM rmm_policies');
[$c, , $hh] = $post(['pp_op' => 'install', 'ids' => [$checkItem['id']]], null, 'wrongtoken');
$ok($c === 302 && stripos($hdr($hh, 'Location'), 'index.php') !== false && (int) $one('SELECT COUNT(*) FROM rmm_policies') === $n1, 'a wrong CSRF token is refused (validateCSRFToken\'s own redirect), nothing written');

echo "\n--- module off: nothing asked, nothing shown ---\n";
$q('UPDATE settings SET config_core_rmm_enabled = 0'); $rmm->syncState();
[$c, $b] = web($wb, 'GET', '/agent/rmm_policies.php', $sidA);
$ok($c === 403 && !str_contains($b, 'policy-starter-pack'), 'module off: the page is denied (same as any other RMM page) and no card is shown');
[$c] = $plan('install', ['ids' => [$checkItem['id']]]);
$ok($c === 404, 'module off: the plan endpoint answers 404 module_disabled');
$q('UPDATE settings SET config_core_rmm_enabled = 1'); $rmm->syncState();
